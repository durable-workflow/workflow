# Embedded live execution

Persistent embedded PHP workers can keep a bounded suspended workflow between
database-queue jobs. An eligible continuation skips its completed instruction
prefix while accepting the same committed durable outcomes. The database
remains authoritative. Ordinary cold replay is the default.

This feature is under [qualification](https://github.com/durable-workflow/workflow/issues/748).
The current draft does not authorize a production performance or capacity claim.
Service-mode warm execution has a separate [PHP SDK implementation](https://github.com/durable-workflow/sdk-php/issues/113).

## Enable a selected workflow

In the application's published `config/workflows.php`, set an explicit allowlist
and a build identity that changes whenever the workflow deployment changes:

```php
'v2' => [
    // Other application settings remain here.
    'live_execution' => [
        'workflows' => [App\Workflows\OrderWorkflow::class],
        'build' => env('APP_RELEASE_ID'),
    ],
],
```

Set the application's `APP_RELEASE_ID` to its immutable release identifier.
Restart workers when deploying code or changing connections, schemas, bindings
or allowlists. An empty allowlist or missing/empty build identity selects cold
replay. Each process owns its cache; another worker can always cold replay.
There is no wait for a previous worker or extension of a task lease.

Use an asynchronous Laravel database queue and a persistent `queue:work`
process. Match its connection/queue to the application's existing workflow
routing. Octane is optional and is not part of this cache's lifetime.

## Initial execution profile

The retained profile supports sequential remote activities and positive ordinary
timers. Activities continue to run through their durable jobs. Timer deadlines
remain persisted and use the workflow's recorded time.

Allowlist workflows only when their suspended state contains deterministic
workflow data. Keep external I/O and container services in activities. Do not
retain request/scoped services, logging contexts or transient custom-model state
in workflow locals across durable calls. Ordinary replay remains available for
workflows that need those lifetimes.

The runtime automatically selects cold replay for constructor or method object
injection, explicit workflow bindings, decorated methods/classes, unsupported
history, fake/synchronous jobs and caller-owned transactions. Query/update/signal
handlers, parallel/selection/child operations, conditions, continue-as-new,
version markers, side effects and other excluded operations retain their cold
contract. An excluded operation encountered after a retained prefix triggers
cold replay before its durable handler. Repair/redrive history can also select
cold replay. Recorded successful business effects are not repeated.
The initial retained profile requires declared builtin entry-parameter types;
untyped, union/intersection and object parameters select cold replay.

Tracing integration is still an explicit qualification gate: a retained Fiber
must never carry an ended processing span or a previous job's task context.
The tracing-enabled cold fallback and context-toggle controls must pass before
the integrated feature is released.

## Bounds and recovery

Each worker keeps at most four entries. An entry's 30-second monotonic age is
not renewed by hits. Publication and lookup check a 128 MiB worker-resident-memory
threshold. This includes the worker's other memory and is not an isolated frame
measurement or an OS memory limit. Missing resident-memory observations select
cold replay. Use the normal worker/process supervisor limits as well.

Before a resume, the worker verifies the backend/session, namespace/run/model,
configured model classes, definition/build, fresh lease, original run fields and complete contiguous
committed history prefix. Reconnects, changed identities, eviction, expiry,
memory pressure and worker loss select authoritative replay.

An advanced frame becomes reusable only after root commit. Rollback, failed
execution or uncertain acknowledgement discards it. A worker job exception also
clears retained state, including a broker acknowledgement failure after commit.
The ordinary repair and
watchdog paths recover durable work with the original deadlines. Failed task
records remain available for diagnosis. A lost timer transport delivery still
uses the existing stale-dispatch interval; retention does not shorten it.

Shutdown and eviction discard suspended execution using the existing Fiber
disposal contract. They do not request workflow cancellation or create a new
durable cleanup outcome. External cancellation and termination remain durable
commands and are checked before resuming any frame.

## Worker diagnostics

The prototype exposes a process-local diagnostic snapshot from the worker:

```php
$snapshot = app(Workflow\V2\Support\WorkflowContinuationCache::class)->snapshot();
```

It reports current entry count, nullable worker resident bytes and cumulative
counters for hits, misses, retention publications, eviction, invalidation and cold-fallback
reasons. `cold_entry` counts fresh authoring starts within the observed profile.
Eviction counters count removed entries; `evict_clear` covers explicit clears
and normal worker shutdown. A repeated pressure check on an empty cache is not
another eviction.
`evict_job_exception` counts entries cleared after a worker job exception.
`reused_committed_operations` counts completed durable operations before the
retained suspension point, and `validated_history_events` counts the fresh
history prefixes checked before reuse. These counts are not PHP instruction
counts or CPU-saving estimates. The diagnostic class is internal while
qualification is pending.
Collect it through the application's existing worker metrics mechanism.
A web request or separate Artisan process cannot observe another worker's
cache. Counters are sparse and reset when that process exits; resident bytes
cover the whole worker and must not be labelled retained-frame bytes.

The [reproducible growth probe](../../tests/Performance/EMBEDDED-CONTINUATION.md)
separates cold test-worker startup, full replay and retained execution. Its
reports state actual cache hits/misses, queue/timer delay, CPU, SQL, memory,
sample counts, failures and unfinished work. Choose retention from measurements
of the actual application workload and its supported lifetime constraints.
