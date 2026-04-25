Cross-Node Long-Poll Coordination

## Problem Statement

The workflow/activity task polling and history wait mechanisms depend on server-local long-poll coordination that does not explicitly document or validate cross-node deployment requirements. Multi-node deployments require shared cache backends for prompt wake behavior, but this contract is not formalized or tested.

## Current Architecture

### Wake Signal Flow
1. Laravel model observers (server) watch `WorkflowTask` and `WorkflowHistoryEvent`  
2. On model changes → trigger wake signals via `LongPollSignalStore`
3. Signals write version stamps to cache channels (e.g., `workflow-tasks:namespace:queue`)
4. `LongPoller` snapshots channel versions, detects changes, triggers re-probe

### Timing Hint Flow
1. `WorkflowTaskPoller` reads `workflow_tasks.available_at` for next ready time
2. `HistoryController` reads `workflow_run_summaries` (next_task_at, next_task_lease_expires_at, wait_deadline_at)
3. These feed `LongPoller`'s `nextProbeAt` callback to optimize polling frequency

### Cache Backend Behavior
- `ServerPollingCache` uses file-based cache by default (single-node)
- Switches to shared cache (Redis, database, Memcached) if Laravel default is shared
- File cache = server-local wake signals (do not propagate across nodes)
- Shared cache = cross-node wake signals work correctly

## Gaps

1. **No documented contract** for LongPollWakeStore deployment requirements
2. **No validation** that cache backend is shared in multi-node setups
3. **No load tests** across supported cache backends
4. **Unclear ownership** of wake signal triggering (observers in server, not package)

## Proposed Solution

### Phase 1: Documentation (Priority: P0)

**Create**: `workflow/docs/long-poll-coordination.md`

Document:
- `LongPollWakeStore` contract as the stable multi-node interface
- Required cache backends for multi-node: Redis, database cache, Memcached
- File cache limitations: single-node only, wake signals do not propagate
- Wake signal channel naming patterns
- Timing hint patterns using `available_at` fields
- Deployment checklist for production multi-node setups

**Create**: `workflow/docs/deployment/multi-node-requirements.md`

Document:
- Cache backend configuration requirements
- Observer registration requirements (if kept in server)
- Network topology considerations
- Health check patterns

### Phase 2: Configuration Validation (Priority: P1)

**Add**: Cache backend validator in package

```php
// workflow/src/V2/Support/LongPollCacheValidator.php
class LongPollCacheValidator
{
    public function validateMultiNodeCapable(CacheRepository $cache): ValidationResult
    {
        // Check if cache backend supports cross-node coordination
        // Fail fast with clear error if file-based cache in multi-node config
    }
}
```

**Add**: Configuration helper in workflows config:

```php
'v2' => [
    'long_poll' => [
        'validate_multi_node' => env('WORKFLOW_V2_VALIDATE_MULTI_NODE_CACHE', true),
        'multi_node' => env('WORKFLOW_V2_MULTI_NODE', false),
    ],
],
```

**Add**: Boot-time validation in service provider:
- If `multi_node` is true and cache is file-based → fail/warn
- Log cache backend type on boot for observability

### Phase 3: Move Observers to Package (Priority: P1)

**Goal**: Make wake signal triggering automatic when package is installed

**Current**: Observers live in `server/app/Observers/`
- `WorkflowTaskObserver` 
- `WorkflowHistoryEventObserver`

**Proposed**: Move to `workflow/src/V2/Observers/`
- Register in package service provider
- Server no longer needs manual observer registration

**Benefits**:
- Wake signals work automatically when package installed
- Single source of truth for triggering logic
- Easier to test in package test suite

### Phase 4: Load Testing (Priority: P1)

**Create**: `workflow/tests/LoadTest/LongPollCoordinationTest.php`

Test scenarios:
1. **Redis backend**: 1000 concurrent polls, measure wake latency
2. **Database cache backend**: 1000 concurrent polls, measure wake latency  
3. **Memcached backend**: 1000 concurrent polls, measure wake latency
4. **File backend (baseline)**: 100 concurrent polls, confirm single-node only

Measure:
- Wake signal propagation time (time from model change to poller re-probe)
- Cache backend load (queries/sec, memory usage)
- Poll timeout behavior under load
- Coordinated poll deduplication effectiveness

**Create**: `workflow/tests/LoadTest/README.md`

Document:
- How to run load tests locally
- Required infrastructure (Redis, MySQL, Memcached containers)
- Expected performance baselines
- Interpreting results

### Phase 5: Package API Exposure (Priority: P2)

**Create**: `workflow/src/V2/Contracts/LongPollCoordinator.php`

Expose high-level coordinator interface:
```php
interface LongPollCoordinator
{
    public function pollWorkflowTask(string $namespace, string $queue, callable $probe): ?array;
    public function pollActivityTask(string $namespace, string $queue, callable $probe): ?array;
    public function waitHistoryEvent(string $runId, callable $probe): mixed;
}
```

This wraps the lower-level LongPoller + signals + timing hints into a documented public API.

## Acceptance Criteria

1. ✅ Documentation exists explaining multi-node requirements
2. ✅ Configuration validator catches file-cache-in-multi-node misconfigurations
3. ✅ Observers moved to package (wake signals automatic)
4. ✅ Load tests exist for Redis, database, Memcached backends
5. ✅ Load test results documented with performance baselines
6. ✅ Public API exposed for long-poll coordination (optional improvement)

## Risks & Mitigations

**Risk**: Moving observers to package breaks existing server deployments  
**Mitigation**: Keep server observers as fallback, package checks if already registered

**Risk**: Load tests reveal cache backend performance issues  
**Mitigation**: Document which backends are production-ready, which are dev-only

**Risk**: Validation too strict, breaks valid single-node file-cache setups  
**Mitigation**: Only fail validation if multi_node=true AND file cache detected

## Timeline Estimate

- Phase 1 (Documentation): 4 hours
- Phase 2 (Validation): 3 hours
- Phase 3 (Observers): 2 hours
- Phase 4 (Load Tests): 6 hours
- Phase 5 (API Exposure): 3 hours

Total: ~18 hours of focused work

## Success Metrics

1. TD-S002 issue can be closed with confidence
2. Multi-node deployments have clear deployment guide
3. Misconfigurations caught at boot time, not in production
4. Performance baselines documented for cache backends
5. Wake signal latency < 100ms p99 on recommended backends

