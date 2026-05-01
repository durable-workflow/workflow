# Long-Poll Coordination

## Overview

The workflow v2 engine uses cache-backed long-poll coordination to deliver workflow tasks, activity tasks, and history events to workers with minimal latency. This document describes the coordination architecture, deployment recommendations, and performance characteristics.

Long-poll coordination is the **acceleration layer** in the v2 architecture. Discovery, claim, lease expiry, and schedule fire evaluation are governed by durable rows in `workflow_tasks`, `workflow_runs`, `workflow_instances`, `activity_executions`, `activity_attempts`, and `workflow_schedules` — never by cache state. The cache-backed wake store described here exists to shorten the time between a task becoming eligible and a compatible worker claiming it; it is not part of the correctness boundary. See [`docs/architecture/scheduler-correctness.md`](architecture/scheduler-correctness.md) for the complete correctness vs. acceleration contract, the bounded discovery latency guarantees that hold without acceleration, and the reversible migration path from cache-coordinated wake notification to a stronger acceleration primitive.

## Architecture

### Wake Signals

When workflow state changes (task created, history event recorded), the system signals waiting pollers to re-probe immediately instead of waiting for poll timeout.

**Signal Flow:**
1. Model changes trigger wake signals (via Laravel observers)
2. Wake signals write version stamps to cache channels
3. Pollers snapshot channel versions before probing
4. If channel versions change, poller re-probes immediately

**Channel Naming:**
- Workflow tasks: `workflow-tasks:{namespace}:{queue}`
- Activity tasks: `activity-tasks:{namespace}:{queue}`
- History events: `history:{namespace}:{run_id}`

**Version Stamps:**
```
Format: {timestamp}:{ulid}
Example: 1713216000.123456:01HW8XQZF9P2K3M4N5Q6R7S8T9
```

Combines microsecond timestamp with ULID for uniqueness across nodes.

### Timing Hints

Pollers use timing hints to schedule re-probe attempts efficiently when no immediate work is available.

**Workflow Tasks:**
- Reads `workflow_tasks.available_at` to find earliest future-scheduled task
- If all tasks have `available_at` in the future, poller sleeps until earliest time

**Activity Tasks:**
- Same pattern as workflow tasks

**History Events:**
- Reads `workflow_run_summaries` columns:
  - `next_task_at`: when next workflow/activity task becomes ready
  - `next_task_lease_expires_at`: when leased task expires (recovery opportunity)
  - `wait_deadline_at`: when workflow wait timeout expires
- Poller wakes at earliest of these times

**Benefits:**
- Reduces database queries when no work available
- Avoids busy-polling
- Optimizes for batch workloads with scheduled tasks

### Cache Backend Contract

The `Workflow\V2\Contracts\LongPollWakeStore` interface defines the wake signal contract.

**Required Operations:**
```php
interface LongPollWakeStore
{
    // Capture current version stamps for channels
    public function snapshot(array $channels): array;
    
    // Check if any channel versions changed since snapshot
    public function changed(array $snapshot): bool;
    
    // Increment version stamps for channels
    public function signal(string ...$channels): void;
}
```

**Default Implementation:**
- `Workflow\V2\Support\CacheLongPollWakeStore` (package)
- Uses Laravel cache with 60-second TTL
- Helper methods: `signalHistoryEvent()`, `signalTask()`

## Multi-Node Deployment

### Acceleration backend recommendations

A multi-node deployment runs correctly without any wake-acceleration backend at all. Pollers fall back to their configured long-poll timeout (default 30 seconds, max 60 seconds) and the durable task-repair loop continues to redeliver work. To recover the sub-second discovery latency that wake signals provide, every node should publish to the same wake-store backend.

**Acceleration backends supported for multi-node:**
- ✅ Redis (recommended for production)
- ✅ Database cache (MySQL, PostgreSQL)
- ✅ Memcached
- ❌ File cache — wake signals do not propagate across nodes
- ❌ Array cache — process-local; signals are lost on restart

The `file` and `array` backends are not classified as supported for the multi-node acceleration layer because they cannot propagate wake signals between nodes. They do not break correctness — they simply turn the wake layer into a no-op across nodes, so pollers wait out the long-poll timeout instead of receiving immediate notification.

**Configuration:**

Laravel `config/cache.php`:
```php
'default' => env('CACHE_DRIVER', 'redis'),

'stores' => [
    'redis' => [
        'driver' => 'redis',
        'connection' => 'cache',
        'lock_connection' => 'default',
    ],
],
```

Point every node at the same Redis (or database) instance so a wake signal published by one node is observed by every other node's pollers.

### Single-Node Deployment

File cache is acceptable for single-node deployments:
```php
'default' => env('CACHE_DRIVER', 'file'),
```

Wake signals propagate within the node. No cross-node coordination needed.

### Bounded discovery latency without acceleration

If the wake-acceleration backend is delayed, partitioned, or entirely absent, discovery latency rises but no work is lost. The upper bound is the configured poll timeout (default `WorkerProtocolVersion::DEFAULT_LONG_POLL_TIMEOUT` of 30 seconds), the `workflows.v2.task_repair.redispatch_after_seconds` cadence (default 3 seconds), and the `workflows.v2.task_repair.loop_throttle_seconds` cadence (default 5 seconds). Lease expiry and redelivery use `workflow_tasks.lease_expires_at` directly and never read cache. See the [scheduler correctness contract](architecture/scheduler-correctness.md) for the full set of guarantees that hold when the cache backend is unreachable.

### Observer Registration

Wake signals are triggered by Laravel model observers watching `WorkflowTask` and `WorkflowHistoryEvent`.

**Server Implementation:**
- `App\Observers\WorkflowTaskObserver` (server/app/Observers/)
- `App\Observers\WorkflowHistoryEventObserver` (server/app/Observers/)

Registered in `AppServiceProvider::boot()`:
```php
WorkflowTask::observe(WorkflowTaskObserver::class);
WorkflowHistoryEvent::observe(WorkflowHistoryEventObserver::class);
```

**Note:** Future package versions may register observers automatically.

## Performance Characteristics

### Latency

**Wake Signal Propagation:**
- Redis backend: < 10ms p99 (typical)
- Database cache: < 50ms p99 (typical)
- Memcached: < 20ms p99 (typical)

**Poll Cycle:**
- Wake signal detected: immediate re-probe (< 100ms total)
- No wake signal: sleep until timing hint (0 queries)
- No timing hint: poll every 1-5 seconds (default)

### Throughput

**Tasks/Second (per node):**
- Redis backend: 1000+ tasks/sec
- Database cache: 500+ tasks/sec  
- Memcached: 800+ tasks/sec

**Bottlenecks:**
- Database queries for task claim (most significant)
- Cache backend latency (minor)
- Network latency between nodes and cache (minor)

### Cache Load

**Writes per Task:**
- 1 wake signal write on task creation
- 1 wake signal write on task update (lease, completion)
- Total: ~2-3 cache writes per task

**Reads per Poll:**
- 1 snapshot read (multiple channels)
- 1 changed check  
- Total: ~2 cache reads per poll attempt

**Memory:**
- Each channel: 1 version stamp (< 100 bytes)
- TTL: 60 seconds (auto-cleanup)
- Typical memory: < 10 MB for 10,000 active channels

## Troubleshooting

### Symptom: Workers Receiving Tasks With High Latency

If workers see tasks only when the long-poll timeout expires (default 30 seconds) instead of within sub-second range, the wake-acceleration layer is degraded. Tasks still flow — the system has fallen back to durable poll cadence, which preserves correctness but loses the latency win that wake signals provide.

**Check cache backend:**
```bash
php artisan tinker
>>> cache()->getStore()
```

If `Illuminate\Cache\FileStore` → file cache cannot propagate wake signals across nodes (single-node deployments are unaffected).

**Fix for multi-node:**
1. Configure a shared cache backend (Redis recommended) so every node observes the same wake signals.
2. Restart all nodes.
3. Verify: `cache()->put('test', 'value'); cache()->get('test')`.

If workers receive **no** tasks at all (not just delayed tasks), the problem is not the cache backend — investigate the durable substrate (`workflow_tasks`, repair loop, worker compatibility) per the scheduler correctness contract.

### Symptom: High Poll Latency

**Check timing hints:**
```sql
SELECT next_task_at, next_task_lease_expires_at, wait_deadline_at 
FROM workflow_run_summaries 
WHERE id = '<run_id>';
```

If all `NULL` → timing hints not populated (expected for closed runs)

**Check wake signals:**
```bash
# Redis
redis-cli KEYS "workflow-tasks:*"

# Database cache
SELECT * FROM cache WHERE key LIKE 'workflow-tasks:%';
```

If no keys found → wake signals not firing (check observer registration)

### Symptom: Cache Growing Unbounded

**Check TTL:**
Wake signal version stamps should expire after 60 seconds.

```bash
# Redis
redis-cli TTL "workflow-tasks:default:default"
```

If `-1` (no TTL) → cache backend not honoring TTL (configuration issue)

## Best Practices

1. **Production:** Always use Redis or database cache for multi-node
2. **Development:** File cache acceptable for single-node
3. **Monitoring:** Track cache hit/miss rates, wake signal latency
4. **Capacity:** Plan for 2-3 cache writes per task + 2 reads per poll
5. **Networking:** Minimize latency between nodes and cache backend
6. **Debugging:** Use `LongPollSignalIntegrationTest` to validate coordination

## Future Improvements

- Auto-registration of observers in package service provider
- Boot-time validation of cache backend for multi-node setups
- Configuration flag: `DW_V2_MULTI_NODE=true` → log a warning if file cache is detected (legacy `WORKFLOW_V2_MULTI_NODE` still honored). The cache admission is warning-only by contract; boot is never blocked.
- Load test suite across cache backends
- Public `LongPollCoordinator` API wrapping lower-level primitives

