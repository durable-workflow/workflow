# Workflow V2 Sticky Execution Contract

This document freezes the v2 contract for sticky execution: a supported
replay optimization that lets a worker keep a warm, process-local workflow
cache and lets matching prefer that worker for follow-up workflow tasks.
Sticky execution is not a correctness feature. Ordinary cold replay from
durable history is always the fallback, and workflow authors MUST NOT rely
on process-local state for correctness.

The guarantees below apply to the `durable-workflow/workflow` package at
v2, to the standalone server that embeds it, and to SDK workers that talk to
the server over the worker protocol.

## Scope

The contract covers:

- **sticky-cache lifecycle** — ownership, expiry, cache-miss behavior,
  eviction, and worker restart handling.
- **routing identity** — the `worker_id` affinity that keeps follow-up
  workflow tasks sticky to a warm worker when it remains alive.
- **fallback semantics** — cache misses, worker replacement, expired
  affinity, and disabled sticky execution all force ordinary cold replay.
- **deployment behavior** — draining or replacing sticky workers stops new
  sticky claims while in-flight leased tasks finish under the normal lease
  contract.
- **operator controls** — the named enable, TTL, capacity, and pressure
  controls operators can tune.
- **metrics and diagnostics** — hit rate, miss rate, forced cold replay,
  and sticky-capacity pressure.
- **replay compatibility guidance** — workflow code must be deterministic
  under both sticky and cold-replay execution.

It does not cover:

- serializing process-local workflow objects into durable storage;
  sticky caches are intentionally in-memory worker state.
- any relaxation of deterministic replay rules. Cold replay remains the
  source of truth.

## Terminology

- **Sticky cache** — the process-local worker cache that may hold a replayed
  workflow execution for a run after a workflow task completes.
- **Sticky owner** — the worker identified by `worker_id` in
  `sticky_worker_id`. The worker owns only its local cache; the durable
  workflow history remains the authority.
- **Sticky affinity** — the durable `(sticky_worker_id, sticky_until)` pair
  recorded on `workflow_runs` and inherited onto follow-up workflow tasks.
- **Sticky hit expected** — a workflow task claimed by its sticky owner
  before `sticky_until`. The worker may resume from its warm cache.
- **Forced cold replay** — a workflow task that had sticky affinity but was
  claimed after the affinity expired or by a different worker because the
  sticky owner was unavailable.
- **Cold replay** — ordinary replay from durable history with no sticky
  affinity. This is always valid and is the correctness fallback.

## Lifecycle and Ownership

The sticky cache is owned by one worker process. The server and workflow
package never treat cache contents as durable state. When a worker completes
a workflow task and advertises sticky-cache support, the server may record
that worker as the run's sticky owner until the configured TTL expires. A
follow-up workflow task inherits the same sticky owner and expiry at create
time.

Eviction is local worker policy. A worker may evict any cached run when it
reaches capacity, drains, restarts, loses memory, or decides the cached
execution is unsafe to reuse. After eviction the worker still accepts a
sticky-routed task, but it must report or treat the execution as a cache miss
and perform cold replay from history.

The durable affinity is advisory. The fields `sticky_worker_id` and
`sticky_until` are routing hints, not correctness fences. The task lease
remains the ownership fence for committing workflow progress.

## Routing Identity

The routing identity is the worker protocol `worker_id`. A worker that wants
sticky routing must register with sticky cache enabled and continue sending
heartbeats. Matching prefers workflow tasks whose active `sticky_worker_id`
matches the polling worker. If a task is sticky to a different live worker,
matching holds the task for that owner until the sticky TTL expires or the
owner becomes unavailable.

The routing rules are:

- A task with active affinity for the polling worker is considered first.
- A task with no active affinity may be claimed by any compatible worker.
- A task with active affinity for another live worker is skipped by other
  workers until expiry or owner loss.
- Claim-time lease ownership remains authoritative; a sticky task is still
  claimed, leased, heartbeated, completed, or failed through the normal
  workflow-task protocol.

## Fallback Semantics

Cold replay is mandatory fallback. Workers must cold replay when:

- sticky execution is disabled by operator configuration.
- the worker did not register sticky-cache support.
- the task has no active sticky affinity.
- the task's sticky owner is stale, draining, missing, or restarted.
- the worker cache evicted the run.
- the worker receives a sticky-routed task but cannot find or validate the
  cached execution.

The workflow package records task diagnostics with the replay mode names
`sticky_hit_expected`, `cold_replay`, and `forced_cold_replay`. Operators use
these modes to distinguish healthy sticky routing from fallback replay.

## Deployment, Drain, and Rollout

Sticky execution follows the worker deployment contract. Draining a worker
or build id stops it from accepting new claims; in-flight leases continue
until completion, failure, heartbeat timeout, or lease expiry. Replacement
workers do not inherit process-local caches, so replacing a sticky worker
may create forced cold replay until new workers warm their own caches.

Rolling deploys should prefer unique worker ids per process. Reusing a
worker id after changing workflow code or restarting a process can make
diagnostics ambiguous, so the deployment surface should treat each process
restart as a new sticky owner. Compatibility and fingerprint pinning still
decide whether the worker is allowed to execute the run.

## Operator Controls

The supported controls are:

- `workflows.v2.sticky_execution.enabled`
  (`DW_V2_STICKY_EXECUTION_ENABLED`) — enables or disables sticky affinity.
- `workflows.v2.sticky_execution.ttl_seconds`
  (`DW_V2_STICKY_EXECUTION_TTL_SECONDS`) — how long a run remains sticky to
  the last sticky-capable worker after a workflow task completes.
- server `sticky_execution.default_cache_capacity`
  (`DW_V2_STICKY_EXECUTION_DEFAULT_CACHE_CAPACITY`) — advertised default
  worker cache capacity when a worker does not report a capacity.
- server `sticky_execution.capacity_pressure_ratio`
  (`DW_V2_STICKY_EXECUTION_CAPACITY_PRESSURE_RATIO`) — the worker
  cache-used/capacity ratio that reports capacity pressure.

Operators can disable sticky execution without changing workflow semantics;
the system continues with ordinary cold replay.

## Metrics and Diagnostics

The operator metrics surface reports:

- `sticky_execution.active_sticky_runs`
- `sticky_execution.ready_sticky_tasks`
- `sticky_execution.leased_sticky_tasks`
- `sticky_execution.hit_expected_last_minute`
- `sticky_execution.miss_last_minute`
- `sticky_execution.forced_cold_replay_last_minute`
- `sticky_execution.cold_replay_last_minute`
- `sticky_execution.hit_rate_last_minute`
- `sticky_execution.miss_rate_last_minute`
- `sticky_execution.capacity_pressure_tasks`

The standalone server augments those with worker-reported cache capacity,
cache size, cache hit count, cache miss count, eviction count, forced cold
replay count, and capacity-pressure worker count. A high forced-cold-replay
rate means sticky routing is not harmful to correctness, but it is not
delivering the intended replay-speed benefit.

## Replay Compatibility

Workflow code must remain replay-safe under both sticky and cold replay.
Sticky execution does not permit workflow code to read mutable globals,
wall-clock time, random values, open sockets, local files, or other
process-local state for correctness. Anything that affects workflow
decisions must be recorded in durable history through the normal command,
activity, timer, signal, update, side-effect, or version-marker surfaces.
