# Workflow Memos Architecture

**Status**: Phase 1 Implementation Complete  
**Date**: 2026-04-16  
**Scope**: Non-indexed returned-only execution metadata

## Purpose

Implements v2 Plan Phase 1 deliverable: dedicated typed memos table for non-indexed workflow metadata.

**Before**: `workflow_runs.memo` JSON column - no structure, no size limits, mixed with search attributes

**After**: `workflow_memos` table - structured key-value storage, explicit size/count limits, separate contract from search attributes

## Core Design Principle (from v2 Plan)

**Indexed search metadata and non-indexed memo metadata must be separate typed contracts** with explicit privacy, inheritance, and upsert rules.

Memos are:
- **Non-indexed**: No value indexes, NOT filterable
- **Returned-only**: For describe/list/detail views only
- **JSON-friendly**: Arrays, objects, nested structures supported
- **Operator-visible**: Not codec-protected secret-bearing payloads
- **Larger values**: 10KB per memo (vs 2KB for search attributes)

## Schema

### workflow_memos Table

```sql
CREATE TABLE workflow_memos (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    workflow_run_id VARCHAR(26) NOT NULL,
    workflow_instance_id VARCHAR(191) NOT NULL,
    
    -- Memo identity
    key VARCHAR(191) NOT NULL,
    
    -- JSON-friendly value (no codec protection)
    value JSON NOT NULL,
    
    -- Metadata
    upserted_at_sequence INT UNSIGNED NOT NULL,
    inherited_from_parent BOOLEAN NOT NULL DEFAULT 0,
    
    created_at TIMESTAMP(6),
    updated_at TIMESTAMP(6),
    
    UNIQUE KEY workflow_memos_run_key_unique (workflow_run_id, key),
    INDEX workflow_memos_instance_key (workflow_instance_id, key),
    
    FOREIGN KEY (workflow_run_id) REFERENCES workflow_runs(id) ON DELETE CASCADE
);
```

**Critical**: NO indexes on value column. Memos are not filterable by contract.

## Size and Count Limits

From v2 Plan structural limits section:

- **Max 100 memos per run** - prevents unbounded metadata growth
- **10KB per memo value** - sufficient for detailed context, not documents
- **64KB total per run** - prevents excessive storage/memory

Limits enforced at:
1. Application level (model validation)
2. Transaction level (checked after all upserts in a batch)

Violations throw `InvalidArgumentException` with typed error messages visible to operators.

## Upsert Semantics

### Create or Update

```php
$service = new MemoUpsertService();

$call = new UpsertMemosCall([
    'user_context' => ['user_id' => 'user_123', 'tenant' => 'acme'],
    'workflow_config' => ['max_retries' => 5, 'timeout' => 3600],
    'order_details' => [
        'items' => [
            ['sku' => 'ABC', 'quantity' => 2],
            ['sku' => 'XYZ', 'quantity' => 1],
        ],
        'total' => 150.00,
    ],
]);

$service->upsert($run, $call, $historySequence);
```

- If memo key exists: updates value and sequence
- If memo key is new: creates new row
- Values are NOT normalized (preserves JSON structure)

### Delete

```php
$call = new UpsertMemosCall([
    'temp_data' => null,  // Delete this memo
]);

$service->upsert($run, $call, $historySequence);
```

Null value means "delete this memo from the run."

### Continue-As-New Inheritance

```php
$service->inheritFromParent($parentRun, $childRun, $childStartSequence);
```

All parent memos are copied to child with:
- `inherited_from_parent = true`
- New `upserted_at_sequence` in child's history
- Child can override inherited memos (clears inherited flag)

Per v2 Plan:
> explicit continue-as-new inheritance rules for routing, retry defaults, and compatibility markers, including which values are carried forward automatically and which must be restated on the new run

Memos inherit automatically; overrides are explicit via upsert.

## Memos vs Search Attributes

| Feature | Search Attributes | Memos |
|---------|------------------|-------|
| **Purpose** | Indexed visibility metadata | Returned-only execution context |
| **Filterable** | Yes (via Waterline queries) | No (by contract) |
| **Indexed** | Per-type indexes | No indexes |
| **Value Types** | Typed columns (string, keyword, int, float, bool, datetime) | JSON (arrays, objects, scalars) |
| **Max per run** | 100 | 100 |
| **Max value size** | 2KB (string), 255B (keyword) | 10KB |
| **Total size** | 64KB | 64KB |
| **Normalization** | String normalization | No normalization (preserves JSON) |
| **Use case** | Fleet filtering, saved views | Detail context, describe metadata |

## API Surface

### MemoUpsertService

```php
class MemoUpsertService
{
    // Upsert memos for a run
    public function upsert(
        WorkflowRun $run,
        UpsertMemosCall $call,
        int $sequence,
        bool $inheritedFromParent = false,
    ): void;
    
    // Copy memos from parent to child (continue-as-new)
    public function inheritFromParent(
        WorkflowRun $parentRun,
        WorkflowRun $childRun,
        int $childStartSequence,
    ): void;
    
    // Get memos as key-value array
    public function getMemos(WorkflowRun $run): array;
    
    // Get memos with metadata (value, inherited flag, sequence)
    public function getMemosWithMetadata(WorkflowRun $run): array;
    
    // Delete all memos for a run (cleanup)
    public function deleteAllForRun(string $runId): void;
}
```

### WorkflowMemo Model

```php
class WorkflowMemo extends Model
{
    // Get JSON value
    public function getValue(): mixed;
    
    // Set JSON value with size validation
    public function setValue(mixed $value): void;
    
    // Validate total size across all memos for a run
    public static function validateTotalSize(string $runId): void;
    
    // Validate count limit for a run
    public static function validateCount(string $runId): void;
}
```

### WorkflowRunSummary Projection

```php
class WorkflowRunSummary extends Model
{
    // Memo relationship (no filtering scope)
    public function memos(): HasMany;
    
    // Get memos with dual-read fallback
    public function getMemos(): array;
    
    // Note: NO scopeWithMemo() method
    // Memos are not filterable by contract
}
```

## Test Coverage

15 test cases covering:

1. **JSON Value Storage**
   - String values
   - Array values
   - Nested JSON structures

2. **Upsert Semantics**
   - Create new memos
   - Update existing memos
   - Delete via null value
   - Sequence tracking

3. **Size and Count Limits**
   - Max 100 memos per run
   - 10KB per memo limit
   - 64KB total size limit

4. **Continue-As-New Inheritance**
   - Inherit all parent memos
   - Mark as inherited
   - Override inherited memos

5. **API Contract**
   - getMemos() key-value array
   - getMemosWithMetadata() with metadata
   - Non-filterability contract

All tests pass with proper database transactions and cleanup.

## v2 Visibility Metadata Contract

The `workflow_memos` table is the authoritative storage for memo
values in v2. Every detail/describe/list view that returns memos
binds to this table or to a projection that derives from it. The
`workflow_runs.memo` JSON column is not part of the v2 contract: it
is a transitional artifact left over from earlier v2-alpha development
snapshots and will be removed before the v2.0 stable release.

There is no v2-alpha to v2 backwards-compatibility contract. v2 has
never been released, so different v2 development snapshots are not a
mixed fleet — they are iterations of an unreleased product. Operators
upgrading from v1 follow the documented v1-to-v2 migration path; there
is no separate v2-alpha cutover surface to preserve.

Required cleanup before v2.0 stable (tracked in issue #622):

- runtime stops dual-writing the JSON column from `WorkflowExecutor`
- runtime stops dual-reading the JSON column as a fallback in
  `WorkflowRunSummary::getMemos()` and other projections
- the `workflow_runs.memo` column is dropped from the
  `create_workflow_runs_table` migration
- this document and `docs/search-attributes-architecture.md` describe
  the finalized typed-table contract with no transition phases or
  alpha fallbacks

Until that cleanup lands, the runtime continues to write the JSON
column for backwards compatibility with already-running v2-alpha
clusters in development environments. The runtime-side cleanup is a
mechanical removal once issue #622 is scheduled.

## Waterline Integration

### Detail Views Only

Memos are exposed in detail/describe views:

```php
// Get run with memos for detail view
$summary = WorkflowRunSummary::with('memos')
    ->find($runId);

$memos = $summary->getMemos();
// Returns: ['user_context' => [...], 'workflow_config' => [...]]
```

### NOT for Filtering

Memos are explicitly excluded from visibility filters:

```php
// This is NOT supported (memos are not filterable)
WorkflowRunSummary::whereHas('memos', function ($q) {
    $q->where('key', 'user_id')
      ->where('value', 'user_123');  // NO value indexes!
})->get();

// Use search attributes for filtering instead
WorkflowRunSummary::whereHas('searchAttributes', function ($q) {
    $q->where('key', 'user_id')
      ->where('value_keyword', 'user_123');  // Indexed!
})->get();
```

## Metadata Contract Separation

The memos system completes the Phase 1 metadata contract:

### When to Use Search Attributes
- Fleet visibility queries
- Saved operational views
- Filtering by customer, tenant, region, priority
- Short values (IDs, enums, flags)
- Indexed lookups required

### When to Use Memos
- Detailed execution context for describe views
- Nested configuration objects
- Order details, user preferences, workflow state
- Larger values (up to 10KB)
- NO filtering required

This separation ensures:
1. Search attributes remain fast (indexed, small values)
2. Memos can store richer context (non-indexed, larger values)
3. Contracts are explicit (filterable vs returned-only)
4. Operators know which metadata is searchable

## Success Criteria (from v2 Plan)

✅ Indexed search metadata and non-indexed memo metadata are separate typed contracts  
✅ Memos support JSON-friendly values with explicit size/count limits  
✅ Continue-as-new inheritance is explicit and tracked  
✅ Memos are excluded from filtering by contract  
✅ Projection layer exposes memos for detail views  
✅ Structural limit violations fail through typed exceptions  

## References

- `docs/workflow/plan.md` - Phase 1 metadata contract, memo requirements
- Migration: `src/migrations/2026_04_16_000151_create_workflow_memos_table.php`
- Model: `src/V2/Models/WorkflowMemo.php`
- Service: `src/V2/Support/MemoUpsertService.php`
- Call: `src/V2/Support/UpsertMemosCall.php`
- Projection: `src/V2/Models/WorkflowRunSummary.php` - getMemos() method
- Tests: `tests/Unit/V2/MemoTest.php`

## Implementation Summary

Total implementation: ~800 lines across 5 files

**Commits:**
- d061e5d: Core schema, model, service, call
- 76efaec: Comprehensive test coverage
- 4433d9e: Runtime integration (WorkflowExecutor dual-write)
- c50954b: Projection support (WorkflowRunSummary dual-read)

This completes the indexed-vs-non-indexed metadata contract separation, a foundational Phase 1 deliverable enabling proper operator visibility boundaries.
