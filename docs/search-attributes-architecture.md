# Typed Search Attributes Architecture

**Status**: Phase 1 Implementation Complete  
**Date**: 2026-04-16  
**Scope**: Core schema, models, upsert service, comprehensive tests

## Purpose

Implements v2 Plan Phase 1 deliverable: dedicated typed search attributes table replacing JSON blob storage.

**Before**: `workflow_runs.search_attributes` JSON column - no indexing, no type enforcement, no size limits, poor query performance.

**After**: `workflow_search_attributes` table - typed columns, per-attribute rows, indexed values, efficient filtering, explicit size/count limits.

## Core Design Principles (from v2 Plan)

1. **Indexed search metadata and non-indexed memo metadata are separate typed contracts** with explicit privacy, inheritance, and filterability rules.

2. **Searchable-field registry governance**, built-in diagnostic fields, and alias/collision behavior are explicit enough that custom metadata cannot silently shadow system operator semantics.

3. **Waterline reads projections, not engine internals.** Search attributes are projected for visibility queries.

4. **Operators can search, filter, and save durable fleet views** over versioned visibility contracts rather than relying on raw SQL.

5. **Payload, history-transaction, metadata, and pending-fan-out limits fail through typed observable outcomes** rather than generic transport or worker errors.

## Schema

### workflow_search_attributes Table

```sql
CREATE TABLE workflow_search_attributes (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    workflow_run_id VARCHAR(26) NOT NULL,
    workflow_instance_id VARCHAR(191) NOT NULL,
    
    -- Attribute identity
    key VARCHAR(191) NOT NULL,  -- e.g., "customer_id", "priority", "region"
    type VARCHAR(16) NOT NULL,  -- string, keyword, int, float, bool, datetime
    
    -- Typed value columns (only one populated per row based on type)
    value_string TEXT NULL,             -- For type=string (max 2048 chars app-level)
    value_keyword VARCHAR(255) NULL,    -- For type=keyword (exact match, indexed)
    value_int BIGINT NULL,              -- For type=int
    value_float DOUBLE NULL,            -- For type=float
    value_bool BOOLEAN NULL,            -- For type=bool
    value_datetime TIMESTAMP(6) NULL,   -- For type=datetime (microsecond precision)
    
    -- Metadata
    upserted_at_sequence INT UNSIGNED NOT NULL,  -- History sequence of last upsert
    inherited_from_parent BOOLEAN NOT NULL DEFAULT 0,  -- Continue-as-new inheritance flag
    
    created_at TIMESTAMP(6),
    updated_at TIMESTAMP(6),
    
    UNIQUE KEY workflow_search_attrs_run_key_unique (workflow_run_id, key),
    INDEX workflow_search_attrs_instance_key_type (workflow_instance_id, key, type),
    INDEX workflow_search_attrs_key_keyword (key, value_keyword),
    INDEX workflow_search_attrs_key_int (key, value_int),
    INDEX workflow_search_attrs_key_float (key, value_float),
    INDEX workflow_search_attrs_key_bool (key, value_bool),
    INDEX workflow_search_attrs_key_datetime (key, value_datetime),
    
    FOREIGN KEY (workflow_run_id) REFERENCES workflow_runs(id) ON DELETE CASCADE
);
```

## Type System

### Supported Types

| Type     | Storage Column    | Max Size      | Indexed | Use Case                              |
|----------|-------------------|---------------|---------|---------------------------------------|
| string   | value_string      | 2048 chars    | No      | Long descriptions, free text          |
| keyword  | value_keyword     | 255 chars     | Yes     | IDs, enums, status values, tags       |
| int      | value_int         | 64-bit signed | Yes     | Counters, priorities, quantities      |
| float    | value_float       | Double        | Yes     | Scores, temperatures, percentages     |
| bool     | value_bool        | Boolean       | Yes     | Flags, toggles                        |
| datetime | value_datetime    | Timestamp(6)  | Yes     | Deadlines, scheduled times, events    |

### Type Inference

When upserting without explicit type, the service infers from PHP value:

- `bool` → bool type
- `int` → int type
- `float` → float type
- `CarbonInterface` or `DateTimeInterface` → datetime type
- `string` ≤ 255 chars → keyword type (indexed)
- `string` > 255 chars → string type (not indexed)
- `null` → keyword type (stored as NULL in DB)

## Size and Count Limits

From v2 Plan structural limits section:

- **Max 100 attributes per run** - prevents unbounded metadata growth
- **String values: 2048 characters** - sufficient for descriptions, not documents
- **Keyword values: 255 characters** - optimized for indexed lookups
- **Total serialized size: 64KB per run** - prevents excessive storage/memory

Limits enforced at:
1. Application level (model validation)
2. Transaction level (checked after all upserts in a batch)
3. Database level (column constraints for keyword)

Violations throw `InvalidArgumentException` with typed error messages visible to operators.

## Upsert Semantics

### Create or Update

```php
$service = new SearchAttributeUpsertService();

$call = new UpsertSearchAttributesCall([
    'customer_id' => 'cust_123',
    'priority' => 5,
    'is_urgent' => true,
]);

$service->upsert($run, $call, $historySequence);
```

- If attribute key exists: updates value, type, and sequence
- If attribute key is new: creates new row
- Type can change across upserts (e.g., int → string)

### Delete

```php
$call = new UpsertSearchAttributesCall([
    'temp_flag' => null,  // Delete this attribute
]);

$service->upsert($run, $call, $historySequence);
```

Null value means "delete this attribute from the run."

### Continue-As-New Inheritance

```php
$service->inheritFromParent($parentRun, $childRun, $childStartSequence);
```

All parent attributes are copied to child with:
- `inherited_from_parent = true`
- New `upserted_at_sequence` in child's history
- Child can override inherited attributes (clears inherited flag)

Per v2 Plan:
> explicit continue-as-new inheritance rules for routing, retry defaults, and compatibility markers, including which values are carried forward automatically and which must be restated on the new run

Search attributes inherit automatically; overrides are explicit via upsert.

## Query Performance

### Indexed Lookups

Keyword filtering (exact match):
```php
WorkflowSearchAttribute::where('key', 'customer_id')
    ->where('value_keyword', 'cust_123')
    ->pluck('workflow_run_id');
```

Uses index: `workflow_search_attrs_key_keyword (key, value_keyword)`

Int range queries:
```php
WorkflowSearchAttribute::where('key', 'priority')
    ->where('value_int', '>=', 5)
    ->pluck('workflow_run_id');
```

Uses index: `workflow_search_attrs_key_int (key, value_int)`

Boolean filtering:
```php
WorkflowSearchAttribute::where('key', 'is_urgent')
    ->where('value_bool', true)
    ->pluck('workflow_run_id');
```

Uses index: `workflow_search_attrs_key_bool (key, value_bool)`

Datetime range:
```php
WorkflowSearchAttribute::where('key', 'deadline')
    ->where('value_datetime', '<=', now())
    ->pluck('workflow_run_id');
```

Uses index: `workflow_search_attrs_key_datetime (key, value_datetime)`

### Join to Runs

```php
WorkflowRun::join('workflow_search_attributes', 'workflow_runs.id', '=', 'workflow_search_attributes.workflow_run_id')
    ->where('workflow_search_attributes.key', 'customer_id')
    ->where('workflow_search_attributes.value_keyword', 'cust_123')
    ->where('workflow_runs.status', 'running')
    ->select('workflow_runs.*')
    ->get();
```

Efficient filtering for Waterline visibility queries.

## API Surface

### SearchAttributeUpsertService

```php
class SearchAttributeUpsertService
{
    // Upsert attributes for a run
    public function upsert(
        WorkflowRun $run,
        UpsertSearchAttributesCall $call,
        int $sequence,
        bool $inheritedFromParent = false,
    ): void;
    
    // Copy attributes from parent to child (continue-as-new)
    public function inheritFromParent(
        WorkflowRun $parentRun,
        WorkflowRun $childRun,
        int $childStartSequence,
    ): void;
    
    // Get attributes as key-value array
    public function getAttributes(WorkflowRun $run): array;
    
    // Get attributes with type metadata
    public function getTypedAttributes(WorkflowRun $run): array;
    
    // Delete all attributes for a run (cleanup)
    public function deleteAllForRun(string $runId): void;
}
```

### WorkflowSearchAttribute Model

```php
class WorkflowSearchAttribute extends Model
{
    // Get typed value (regardless of storage column)
    public function getValue(): mixed;
    
    // Set typed value with coercion and validation
    public function setTypedValue(mixed $value, string $type): void;
    
    // Set typed value with automatic type inference
    public function setTypedValueWithInference(mixed $value): void;
    
    // Infer type from PHP value
    public static function inferType(mixed $value): string;
    
    // Validate total size across all attributes for a run
    public static function validateTotalSize(string $runId): void;
    
    // Validate count limit for a run
    public static function validateCount(string $runId): void;
}
```

## Test Coverage

27 test cases covering:

1. **Type Inference and Storage**
   - String type inference
   - Keyword type for short strings
   - Int type inference
   - Float type inference
   - Bool type inference

2. **Upsert Semantics**
   - Create new attributes
   - Update existing attributes
   - Delete via null value
   - Sequence tracking

3. **Size and Count Limits**
   - Max 100 attributes per run
   - String length limit (2048 chars)
   - Keyword length limit (255 chars)
   - Total size limit (64KB)

4. **Continue-As-New Inheritance**
   - Inherit all parent attributes
   - Mark as inherited
   - Override inherited attributes

5. **Query Performance**
   - Keyword filtering (indexed)
   - Int range queries (indexed)
   - Float range queries
   - Bool filtering (indexed)
   - Datetime range queries (indexed)

6. **API Contract**
   - getAttributes() key-value array
   - getTypedAttributes() with metadata
   - Efficient joins to workflow_runs

All tests pass with proper database transactions and cleanup.

## v2 Visibility Metadata Contract

The `workflow_search_attributes` table is the authoritative storage for
search attribute values in v2. Every read path that filters, sorts, or
displays search attributes binds to this table or to a projection that
derives from it. The `workflow_runs.search_attributes` JSON column is
not part of the v2 contract: it is a transitional artifact left over
from earlier v2-alpha development snapshots and will be removed before
the v2.0 stable release.

There is no v2-alpha to v2 backwards-compatibility contract. v2 has
never been released, so different v2 development snapshots are not a
mixed fleet — they are iterations of an unreleased product. Operators
upgrading from v1 follow the documented v1-to-v2 migration path; there
is no separate v2-alpha cutover surface to preserve.

Required cleanup before v2.0 stable:

- runtime stops dual-writing the JSON column from `WorkflowExecutor`
- the `workflow_runs.search_attributes` column is dropped from the
  `create_workflow_runs_table` migration
- this document and `docs/workflow-memos-architecture.md` describe the
  finalized typed-table contract with no transition phases or alpha
  fallbacks

Failure-mode contract: typed-storage writes are not optional.
`WorkflowExecutor` records `SearchAttributesUpserted` only after the
typed-table upsert succeeds. There is no silent fallback path that
treats the JSON column as a safety net for typed-storage failure; a
typed-storage failure surfaces as a workflow task failure and follows
the normal task-failure handling path.

Until the dual-write itself is removed, the runtime continues to mirror
the merged state into the JSON column for consumers that have not yet
switched to the typed table. The runtime-side cleanup is a mechanical
removal once those consumers are migrated.

## Waterline Integration

### Visibility Queries

Waterline list filters will use typed attributes:

```php
// Filter by customer
$runs = WorkflowRun::whereHas('searchAttributes', function ($q) {
    $q->where('key', 'customer_id')
      ->where('value_keyword', 'cust_123');
})->get();

// Filter by priority range
$urgentRuns = WorkflowRun::whereHas('searchAttributes', function ($q) {
    $q->where('key', 'priority')
      ->where('value_int', '>=', 8);
})->get();

// Complex filters
$filtered = WorkflowRun::where('status', 'running')
    ->whereHas('searchAttributes', function ($q) {
        $q->where('key', 'region')
          ->where('value_keyword', 'us-west');
    })
    ->whereHas('searchAttributes', function ($q) {
        $q->where('key', 'is_urgent')
          ->where('value_bool', true);
    })
    ->get();
```

### Saved Views

Operators can save filter combinations:

```json
{
  "name": "High Priority US West Runs",
  "filters": [
    {"key": "region", "type": "keyword", "operator": "=", "value": "us-west"},
    {"key": "priority", "type": "int", "operator": ">=", "value": 8}
  ]
}
```

Filters bind to versioned visibility contracts (not raw SQL).

## Next Steps

1. ✅ **Update WorkflowExecutor** to use SearchAttributeUpsertService instead of JSON merge (4613a19)
2. ✅ **Update Projections** - WorkflowRunSummary should expose typed attributes (8211304)
3. ✅ **Waterline Adapters** - Update visibility query builders to use typed table (3021205)
4. **Documentation** - Update user-facing docs on search attribute limits and types
5. **Performance Testing** - Benchmark indexed queries at scale (10k, 100k, 1M runs)
6. **Memo Implementation** - Separate non-indexed memo table (Phase 1 parallel deliverable)

## Success Criteria (from v2 Plan)

✅ Indexed search metadata and non-indexed memo metadata are separate typed contracts  
✅ Operators can search, filter, and save durable fleet views over versioned visibility contracts  
✅ Searchable-field registry governance prevents custom metadata from shadowing system semantics  
✅ Payload, metadata, and limit violations fail through typed observable outcomes  
✅ Waterline reads projections, not engine internals  
⏳ Visibility-field indexing, filter semantics, and saved-view compatibility tested (next)  
⏳ List-filter case-sensitivity, null semantics, and relative-time behavior explicit (next)

## References

- `docs/workflow/plan.md` - Phase 1 projection catalog, search attribute contract
- Migration: `src/migrations/2026_04_15_000150_create_workflow_search_attributes_table.php`
- Model: `src/V2/Models/WorkflowSearchAttribute.php`
- Service: `src/V2/Support/SearchAttributeUpsertService.php`
- Visibility: `src/V2/Support/VisibilityFilters.php` - Waterline query adapter
- Tests: `tests/Unit/V2/SearchAttributeTest.php`
