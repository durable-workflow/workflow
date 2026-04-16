# Workflow Child Calls Architecture

## Overview

The workflow child calls system tracks parent-child workflow invocations, enabling hierarchical workflow orchestration. This document describes the architecture, data model, lifecycle semantics, and parent-close policies for child workflow execution.

## Purpose

Child workflows enable:
- **Decomposition**: Break complex workflows into manageable sub-workflows
- **Reusability**: Invoke common workflow patterns from multiple parents
- **Isolation**: Separate failure domains and execution contexts
- **Coordination**: Synchronize multiple child workflows with parent lifecycle
- **Policy Control**: Define how parent closure affects running children

## Database Schema

### Table: `workflow_child_calls`

Tracks each parent-child invocation with lifecycle state and outcome.

```sql
CREATE TABLE workflow_child_calls (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    
    -- Parent context
    parent_workflow_run_id VARCHAR(26) INDEX,
    parent_workflow_instance_id VARCHAR(191) INDEX,
    sequence INT UNSIGNED INDEX,  -- History event that scheduled the child
    
    -- Child identity (before resolution)
    child_workflow_type VARCHAR(191),
    child_workflow_class VARCHAR(255),
    requested_child_id VARCHAR(191) NULL,  -- User-specified child instance ID
    
    -- Resolved references (after child starts)
    resolved_child_instance_id VARCHAR(191) NULL INDEX,
    resolved_child_run_id VARCHAR(26) NULL INDEX,
    
    -- Snapped parent-close policy
    parent_close_policy VARCHAR(32) DEFAULT 'abandon',  -- abandon|request_cancel|terminate
    
    -- Snapped routing options
    connection VARCHAR(191) NULL,
    queue VARCHAR(191) NULL,
    compatibility VARCHAR(191) NULL,
    
    -- Future expansion
    retry_policy JSON NULL,
    timeout_policy JSON NULL,
    cancellation_propagation BOOLEAN DEFAULT FALSE,
    
    -- Lifecycle status
    status VARCHAR(32) DEFAULT 'scheduled' INDEX,
    -- States: scheduled, started, completed, failed, cancelled, terminated, abandoned
    
    -- Outcome metadata
    result_payload_reference VARCHAR(191) NULL,
    failure_reference VARCHAR(191) NULL,
    closed_reason VARCHAR(64) NULL,  -- completed|failed|cancelled|terminated|abandoned
    
    -- Timing
    scheduled_at TIMESTAMP(6),
    started_at TIMESTAMP(6) NULL,
    closed_at TIMESTAMP(6) NULL,
    
    -- Arguments and metadata
    arguments JSON NULL,
    metadata JSON NULL,
    
    created_at TIMESTAMP(6),
    updated_at TIMESTAMP(6),
    
    -- Composite indexes
    INDEX child_calls_parent_seq (parent_workflow_run_id, sequence),
    INDEX child_calls_parent_status (parent_workflow_run_id, status),
    INDEX child_calls_child_parent (resolved_child_instance_id, parent_workflow_run_id),
    
    FOREIGN KEY (parent_workflow_run_id) REFERENCES workflow_runs(id) ON DELETE CASCADE
);
```

## Child Call Lifecycle

### State Machine

```
    scheduleChild()
         |
         v
    [Scheduled] ────────────────┐
         |                      │
         | resolveReferences()  │ recordChildAbandoned()
         v                      │ (parent closed with abandon policy)
    [Started] ──────────────────┤
         |                      │
         ├──> markCompleted() ──┤
         ├──> markFailed() ─────┤
         ├──> markCancelled() ──┤
         └──> markTerminated() ─┤
                                │
                                v
                         [Terminal States]
                    (Completed | Failed | 
                     Cancelled | Terminated | 
                     Abandoned)
```

### State Descriptions

| State | Description | Triggers |
|-------|-------------|----------|
| **Scheduled** | Parent requested child start, child not yet running | `scheduleChild()` |
| **Started** | Child workflow is executing | `resolveReferences()` after child starts |
| **Completed** | Child finished successfully | `markCompleted()` |
| **Failed** | Child execution failed | `markFailed()` |
| **Cancelled** | Child was cancelled (graceful) | `markCancelled()` |
| **Terminated** | Child was terminated (forceful) | `markTerminated()` |
| **Abandoned** | Parent closed with abandon policy before child finished | `markAbandoned()` |

### Open vs Terminal States

- **Open**: `Scheduled`, `Started` — child execution is in progress or pending
- **Terminal**: `Completed`, `Failed`, `Cancelled`, `Terminated`, `Abandoned` — child execution is finished

## Parent-Close Policies

When a parent workflow closes (completes, fails, or is cancelled), its parent-close policy determines how to handle open children.

### Policy: `Abandon`

**Behavior**: Mark child as abandoned, let it continue independently.

**Semantics**:
- Child continues executing in its own lifecycle
- Child status → `Abandoned`
- Child is no longer tracked as "open" for the parent
- Child can still complete, fail, or be cancelled independently
- No cancellation or termination commands issued

**Use Cases**:
- Fire-and-forget child workflows
- Long-running background processes
- Children that should outlive parent

**Implementation**:
```php
private function handleAbandon(WorkflowChildCall $childCall, array &$stats): void
{
    $childCall->markAbandoned();
    $stats['abandoned']++;
}
```

### Policy: `RequestCancel`

**Behavior**: Issue graceful cancellation command to child.

**Semantics**:
- Cancellation request is sent to child workflow
- Child can handle cancellation gracefully (cleanup, compensation)
- Child status remains `Started` until cancellation completes
- Metadata flag `parent_close_cancel_requested` set to true
- Actual cancellation command dispatch handled by `WorkflowExecutor`

**Use Cases**:
- Children that need graceful cleanup
- Workflows with compensation logic
- Transactional child workflows

**Implementation**:
```php
private function handleRequestCancel(WorkflowChildCall $childCall, array &$stats): void
{
    $childCall->forceFill([
        'metadata' => array_merge($childCall->metadata ?? [], [
            'parent_close_cancel_requested' => true,
            'parent_close_cancel_requested_at' => now()->toIso8601String(),
        ]),
    ])->save();
    
    $stats['cancel_requested']++;
    // Actual cancel command dispatched by WorkflowExecutor
}
```

### Policy: `Terminate`

**Behavior**: Forcefully terminate child execution immediately.

**Semantics**:
- Child workflow is stopped immediately without cleanup
- Child status will become `Terminated`
- Metadata flag `parent_close_terminate_requested` set to true
- Actual termination command dispatch handled by `WorkflowExecutor`
- No compensation or rollback logic executed

**Use Cases**:
- Time-sensitive parent workflows
- Children that must not outlive parent
- Abort-on-failure scenarios

**Implementation**:
```php
private function handleTerminate(WorkflowChildCall $childCall, array &$stats): void
{
    $childCall->forceFill([
        'metadata' => array_merge($childCall->metadata ?? [], [
            'parent_close_terminate_requested' => true,
            'parent_close_terminate_requested_at' => now()->toIso8601String(),
        ]),
    ])->save();
    
    $stats['terminate_requested']++;
    // Actual terminate command dispatched by WorkflowExecutor
}
```

## Continue-As-New Semantics

When a parent workflow continues as a new run (continue-as-new pattern), open children must be transferred to the new run.

### Transfer Logic

```php
public function transferChildCallsToContinuedRun(
    WorkflowRun $closingRun,
    WorkflowRun $continuedRun,
): void {
    DB::transaction(function () use ($closingRun, $continuedRun): void {
        // Transfer open children to new run
        WorkflowChildCall::where('parent_workflow_run_id', $closingRun->id)
            ->whereIn('status', [
                ChildCallStatus::Scheduled->value,
                ChildCallStatus::Started->value,
            ])
            ->update([
                'parent_workflow_run_id' => $continuedRun->id,
                'updated_at' => now(),
            ]);
        
        // Terminal children remain with closing run (historical record)
    });
}
```

### Transfer Behavior

| Child State | Behavior |
|-------------|----------|
| **Open** (Scheduled, Started) | Transferred to continued run |
| **Terminal** (Completed, Failed, etc.) | Remain with closing run (historical) |

**Rationale**:
- Open children are still executing and need parent tracking
- Terminal children are historical and don't need updated tracking
- Continued run inherits responsibility for open children
- Lineage preserved through `parent_workflow_instance_id` (unchanged)

## Reference Resolution

Child calls use a two-phase resolution process:

### Phase 1: Scheduling

When parent schedules a child:
- `parent_workflow_run_id`: Known (parent's run ID)
- `child_workflow_type`: Known (workflow class to invoke)
- `requested_child_id`: Optional (user-specified child instance ID)
- `resolved_child_instance_id`: NULL (not yet started)
- `resolved_child_run_id`: NULL (not yet started)
- `status`: `Scheduled`

### Phase 2: Resolution

After child starts executing:
- `resolved_child_instance_id`: Set (actual instance ID assigned)
- `resolved_child_run_id`: Set (actual run ID assigned)
- `status`: `Started`

**Why Two Phases?**
- Child instance ID may be system-generated or user-specified
- Child run ID is always system-generated
- Resolution happens asynchronously after parent schedules child
- Enables tracking of "scheduled but not started" children

## Service API

### `ChildCallService`

#### Scheduling

```php
public function scheduleChild(
    WorkflowRun $parentRun,
    ChildWorkflowCall $call,
    int $sequence,
    ?string $requestedChildId = null,
): WorkflowChildCall
```

Creates a child call record in `Scheduled` state.

**Parameters**:
- `$parentRun`: The parent workflow run
- `$call`: Child workflow invocation details (workflow class, arguments, options)
- `$sequence`: History event sequence that scheduled the child
- `$requestedChildId`: Optional user-specified child instance ID

**Returns**: Created `WorkflowChildCall` record

**Side Effects**:
- Snaps parent-close policy from options (default: `Abandon`)
- Inherits connection/queue from parent if not specified
- Records scheduling timestamp

#### Reference Resolution

```php
public function resolveChildReferences(
    WorkflowChildCall $childCall,
    string $childInstanceId,
    string $childRunId,
): void
```

Updates child call with resolved instance/run IDs after child starts.

**Side Effects**:
- Sets `resolved_child_instance_id`
- Sets `resolved_child_run_id`
- Transitions status from `Scheduled` → `Started`
- Records start timestamp

#### Outcome Recording

```php
public function recordChildCompleted(
    WorkflowChildCall $childCall,
    ?string $resultPayloadReference = null,
): void

public function recordChildFailed(
    WorkflowChildCall $childCall,
    ?string $failureReference = null,
): void

public function recordChildCancelled(WorkflowChildCall $childCall): void
public function recordChildTerminated(WorkflowChildCall $childCall): void
public function recordChildAbandoned(WorkflowChildCall $childCall): void
```

Mark child call as terminal with appropriate outcome.

**Side Effects**:
- Transitions status to terminal state
- Sets `closed_reason`
- Records closure timestamp
- Stores outcome references (result/failure payloads)

#### Parent-Close Policy Enforcement

```php
public function enforceParentClosePolicy(WorkflowRun $parentRun): array
```

Applies parent-close policies to all open children when parent closes.

**Returns**: Stats array with counts of actions taken:
```php
[
    'abandoned' => 2,           // Children marked abandoned
    'cancel_requested' => 1,    // Children marked for cancellation
    'terminate_requested' => 0, // Children marked for termination
]
```

**Behavior**:
- Queries all open children for parent
- Applies each child's snapped parent-close policy
- Marks metadata for cancel/terminate (actual commands dispatched by executor)
- Returns action counts for observability

#### Queries

```php
public function getOpenChildren(WorkflowRun $parentRun): Collection
public function getAllChildren(WorkflowRun $parentRun): Collection
public function getChildBySequence(WorkflowRun $parentRun, int $sequence): ?WorkflowChildCall
public function countOpenChildren(WorkflowRun $parentRun): int
public function hasOpenChildren(WorkflowRun $parentRun): bool
public function getChildrenByInstanceId(string $childInstanceId): Collection
```

Query helpers for child call tracking and lineage.

## Model API

### `WorkflowChildCall`

#### Relationships

```php
public function parentRun(): BelongsTo
public function parentInstance(): BelongsTo
public function childInstance(): BelongsTo
public function childRun(): BelongsTo
```

#### State Checks

```php
public function isOpen(): bool      // Scheduled or Started
public function isTerminal(): bool  // Any terminal state
public function isResolved(): bool  // Has resolved_child_instance_id
```

#### State Transitions

```php
public function resolveReferences(string $childInstanceId, string $childRunId): void
public function markCompleted(?string $resultPayloadReference = null): void
public function markFailed(?string $failureReference = null): void
public function markCancelled(): void
public function markTerminated(): void
public function markAbandoned(): void
```

## Integration Points

### WorkflowExecutor Integration

The `WorkflowExecutor` is responsible for:

1. **Scheduling Children**: Call `ChildCallService::scheduleChild()` when parent executes child workflow call
2. **Resolving References**: Call `ChildCallService::resolveChildReferences()` after child starts
3. **Recording Outcomes**: Call appropriate outcome methods when child completes
4. **Enforcing Policies**: Call `enforceParentClosePolicy()` before parent closes
5. **Dispatching Commands**: Issue actual cancel/terminate commands based on metadata flags
6. **Continue-As-New**: Call `transferChildCallsToContinuedRun()` during continue-as-new

### Child Workflow Start

When starting a child workflow:
1. Read scheduled child call record
2. Resolve actual instance/run IDs (system-generated or from `requested_child_id`)
3. Call `resolveChildReferences()` to update child call
4. Dispatch child workflow execution

### Child Workflow Completion

When child workflow completes:
1. Determine outcome (completed/failed/cancelled/terminated)
2. Call appropriate outcome recording method
3. Store result/failure payload references
4. Parent can query child outcome through child call record

### Parent Workflow Closure

When parent workflow closes:
1. Query open children with `getOpenChildren()`
2. Call `enforceParentClosePolicy()` to apply policies
3. Dispatch cancel/terminate commands for affected children (based on metadata flags)
4. Continue parent closure (don't block on child termination)

## Query Patterns

### Find Open Children

```php
$openChildren = ChildCallService::getOpenChildren($parentRun);
```

Returns children in `Scheduled` or `Started` states, ordered by sequence.

### Check for Open Children

```php
$hasOpen = ChildCallService::hasOpenChildren($parentRun);
```

Efficient boolean check without loading child records.

### Find Child by Sequence

```php
$childCall = ChildCallService::getChildBySequence($parentRun, $sequence);
```

Retrieves specific child call by history event sequence.

### Track Child Lineage

```php
$childCalls = ChildCallService::getChildrenByInstanceId($childInstanceId);
```

Returns all child calls for a given child instance (across continue-as-new runs).

## Testing

Comprehensive test coverage in `ChildCallServiceTest.php`:

### Test Categories

1. **Scheduling**: Child call creation with options
2. **Reference Resolution**: Instance/run ID assignment
3. **Outcome Recording**: All terminal states (completed, failed, etc.)
4. **Parent-Close Policies**: Abandon, request_cancel, terminate enforcement
5. **Continue-As-New**: Open child transfer to continued run
6. **Queries**: Open/terminal filtering, sequence lookups, lineage tracking

### Key Test Cases

- `test_schedule_child_with_default_options`: Default policy snapping
- `test_schedule_child_with_custom_parent_close_policy`: Policy override
- `test_resolve_child_references`: Scheduled → Started transition
- `test_record_child_completed`: Successful completion
- `test_record_child_failed`: Failure with error reference
- `test_enforce_parent_close_policy_abandon`: Abandon behavior
- `test_enforce_parent_close_policy_request_cancel`: Cancel metadata marking
- `test_enforce_parent_close_policy_terminate`: Terminate metadata marking
- `test_enforce_parent_close_policy_with_mixed_policies`: Multi-child scenarios
- `test_transfer_child_calls_to_continued_run`: Continue-as-new transfer
- `test_get_open_children`: Open state filtering
- `test_has_open_children`: Boolean convenience check
- `test_get_child_by_sequence`: Sequence-based lookup
- `test_get_children_by_instance_id`: Lineage tracking

## Design Decisions

### Why Snap Parent-Close Policy?

Parent-close policy is stored with each child call (not dynamically resolved) because:
- **Determinism**: Policy effective at scheduling time, not closure time
- **Immutability**: Parent options may change, but scheduled child policy shouldn't
- **Auditing**: Clear record of what policy was in effect for each child

### Why Mark Metadata Instead of Direct Dispatch?

Cancel/terminate commands are marked in metadata (not directly dispatched) because:
- **Separation of Concerns**: `ChildCallService` tracks state, `WorkflowExecutor` dispatches commands
- **Testability**: Can test policy enforcement without mocking command dispatch
- **Flexibility**: Executor can batch commands, add retry logic, or defer execution
- **Consistency**: Same pattern used across workflow command dispatch

### Why Track Both Requested and Resolved IDs?

Child calls store both `requested_child_id` and `resolved_child_instance_id` because:
- **User Intent**: Requested ID shows what user specified (may be custom)
- **Actual Identity**: Resolved ID shows what system assigned (authoritative)
- **Debugging**: Can diagnose ID conflicts or resolution failures
- **Optional**: Requested ID may be null (system-generated IDs only)

### Why Transfer Open Children Only?

Continue-as-new only transfers open children (not terminal) because:
- **Active Tracking**: Only open children need ongoing parent relationship
- **Historical Record**: Terminal children belong to historical run record
- **Performance**: Avoids unnecessary updates to closed child calls
- **Semantics**: Continued run doesn't "inherit" past outcomes, only ongoing work

## Future Expansion

### Planned Features

1. **Retry Policies**: Automatic child retry on failure (schema column exists, not implemented)
2. **Timeout Policies**: Child execution deadlines (schema column exists, not implemented)
3. **Cancellation Propagation**: Cascade parent cancellation to children (schema column exists, not implemented)
4. **Batch Child Scheduling**: Schedule multiple children atomically
5. **Child Result Caching**: Cache child results for retry/replay scenarios

### Schema Extensibility

The schema includes placeholder columns for future features:
- `retry_policy` (JSON): Retry configuration
- `timeout_policy` (JSON): Timeout configuration
- `cancellation_propagation` (boolean): Cascading cancellation flag
- `metadata` (JSON): Arbitrary extension data

These columns are not yet used but allow backward-compatible feature additions.

## Related Systems

- **Workflow Messages**: Children can send messages to parents (see workflow-messages-architecture.md)
- **Workflow History**: Child scheduling/outcome recorded as history events
- **Workflow Memos**: Children inherit memos from parents (see workflow-memos-architecture.md)
- **Search Attributes**: Children can inherit search attributes from parents

## Performance Considerations

### Indexes

Critical indexes for child call queries:
- `(parent_workflow_run_id, sequence)`: Find child by parent and sequence
- `(parent_workflow_run_id, status)`: Filter open/terminal children
- `(resolved_child_instance_id, parent_workflow_run_id)`: Lineage tracking

### Query Optimization

- **Open Children**: Use `whereIn('status', [Scheduled, Started])` instead of `where('closed_at', null)`
- **Existence Checks**: Use `countOpenChildren() > 0` instead of loading records
- **Batch Operations**: Update multiple children in single query during continue-as-new

### Cascade Deletes

Foreign key cascade on `parent_workflow_run_id` ensures:
- Deleting parent run deletes all child call records
- Orphaned child call records cannot exist
- Referential integrity maintained without application logic

## Security Considerations

### Input Validation

- **requested_child_id**: Validate format (26-character ULID or user-specified string)
- **arguments**: Validate JSON-encodability before storage
- **metadata**: Sanitize untrusted input before storage

### Authorization

- Parent workflow must have permission to invoke child workflow type
- Child workflow may have separate authorization context from parent
- Abandoned children continue with original authorization scope

## Observability

### Metrics

Recommended metrics for child call system:
- Child call scheduling rate (per workflow type)
- Child call resolution lag (scheduled → started duration)
- Child call completion rate by outcome (completed/failed/cancelled)
- Open child count per parent run
- Parent-close policy enforcement actions (abandon/cancel/terminate counts)

### Logging

Key events to log:
- Child call scheduled (with parent-close policy)
- Child references resolved (instance/run IDs)
- Child outcome recorded (with result/failure references)
- Parent-close policy enforced (with action counts)
- Continue-as-new child transfer (with open child count)

### Alerting

Suggested alerts:
- High unresolved child count (scheduled but not started)
- Long-running children approaching timeout
- High child failure rate for specific workflow types
- Parent-close policy terminations (may indicate design issues)

## Summary

The workflow child calls system provides:

1. **Hierarchical Orchestration**: Parent workflows can invoke and track child workflows
2. **Lifecycle Management**: Full state machine from scheduling to terminal outcomes
3. **Policy Control**: Configurable parent-close policies (abandon/cancel/terminate)
4. **Continue-As-New Support**: Open children transfer to continued runs
5. **Reference Resolution**: Two-phase ID resolution (requested → resolved)
6. **Comprehensive Queries**: Efficient access to open/terminal children by various criteria
7. **Separation of Concerns**: State tracking separate from command dispatch
8. **Extensibility**: Schema ready for future features (retry, timeout, propagation)

This architecture enables complex workflow hierarchies while maintaining deterministic execution, clear failure semantics, and efficient query patterns.
