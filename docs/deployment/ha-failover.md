# High Availability and Failover Contract

This note records the engine-side contract for high-availability self-hosted
Durable Workflow v2 deployments and for the failover events that operators
must plan for: managed-database failover (writer promotion in MySQL/Aurora,
PostgreSQL/RDS, or any equivalent), managed-Redis failover (Sentinel
promotion, Elasticache replication-group failover, Memorystore standby
promotion, etc.), and the loss of an API node, worker, or scheduler runner
inside a single region.

It is the engine-side counterpart to the standalone server's
[`docs/ha-failover-validation.md`](https://github.com/durable-workflow/server/blob/main/docs/ha-failover-validation.md)
operator contract. Cross-region active/passive recovery is a different
contract and lives in [`docs/deployment/multi-region.md`](multi-region.md);
this document covers only **single-region** HA — the failure modes that the
engine survives without a regional cutover.

The guarantees below apply to the `durable-workflow/workflow` package at v2,
to the standalone `durable-workflow/server` that embeds it, and to every host
that embeds the package directly.

## Scope

This contract names what the engine guarantees during the following events
inside one region:

- managed-database failover where a primary database instance is replaced
  by a previously-replicating secondary and the connection endpoint either
  pauses, returns errors, or transparently re-points to the new primary;
- managed-Redis (or Sentinel/Cluster) failover where a primary cache node
  is replaced by a previously-replicating secondary;
- crash, drain, or replacement of one API node behind the load balancer;
- crash, drain, or replacement of an external SDK worker host;
- crash, drain, or replacement of the singleton scheduler/maintenance
  runner;
- short-lived network partitions between API nodes and the database, the
  cache, or each other.

It does not cover:

- regional disaster recovery (see [`docs/deployment/multi-region.md`](multi-region.md));
- the rolling-upgrade contract for moving between server image versions
  (see `docs/architecture/rollout-safety.md` and the public
  [Rolling Upgrades](https://durable-workflow.github.io/docs/2.0/rolling-upgrades)
  guide);
- active/active multi-writer database topologies or RPO=0 replication
  primitives, which remain support-led;
- detection of failover events. The engine reacts to failover; deciding
  *that* a failover has occurred remains the operator's responsibility,
  driven by the managed service's own promotion signal.

## Engine model: substrate vs acceleration

The HA contract is a direct application of the
[scheduler correctness contract](../architecture/scheduler-correctness.md).
The substrate vs acceleration split governs how the engine survives each
failover class:

- The **workflow database** is the correctness substrate. Every claim,
  lease, schedule, history append, and projection write is durable in the
  database before any acceleration signal fires
  (`DB::afterCommit()` per the Phase 3 contract). When the database is
  unavailable, no new commits happen and pollers safely return empty;
  when it returns, the same rows are visible exactly as they were before
  the outage. The engine never bridges the outage by holding writes in
  memory.
- **Redis (or any other configured cache/wake backend)** is the
  acceleration layer. Wake signals, query-task locks, admission locks,
  and worker-compatibility heartbeats live there. Every consumer of the
  acceleration layer has a documented degraded-mode fallback that reads
  the durable substrate directly. Loss of Redis is a latency event, not
  a correctness event.
- The **singleton scheduler/maintenance runner** is the third HA
  boundary. It owns `schedule:evaluate`, `activity:timeout-enforce`, and
  `history:prune`. It is intentionally a singleton in the v2 contract —
  duplicate runners are not yet a supported topology — and the engine's
  HA story for it is *bounded pause and resume*, not concurrent runners.

These three pieces — substrate, acceleration, scheduler — are also the
three failure domains the operator-side validation contract is required
to test against.

## Validated single-region HA topology

The engine guarantees the failover behavior in this document when the
deployment matches the validated topology pinned by
[Multi-Node Deployment Requirements](multi-node-requirements.md):

- **API nodes**: 2 or more stateless API containers behind a stateless
  load balancer. Every node uses the same auth tokens or signature keys,
  application version, workflow package version, payload-codec
  configuration, database connection, and Redis connection. Each node
  has a unique `DW_SERVER_ID`. Sticky sessions are not required for any
  control-plane or worker-protocol path.
- **Workers**: external SDK worker processes that talk to the
  load-balanced API endpoint. Workers are stateless with respect to the
  cluster: a worker that loses its API node mid-poll re-polls against
  the load balancer and re-claims tasks from the durable substrate.
- **Scheduler/maintenance**: exactly one runner across the entire
  deployment. Two concurrent runners are not in this contract.
- **Coordination**: one shared external workflow database (MySQL 8.0+,
  PostgreSQL 13+, or MariaDB 10.5+) and one shared Redis (or other
  acceleration backend that implements `LongPollWakeStore` with the
  `snapshot()`/`changed()` semantics frozen in the Phase 3 contract).

Deployments outside this shape — SQLite-backed clusters, Redis-less
multi-node mode, duplicate schedulers, multi-writer database topologies —
are not covered by the guarantees below.

## Database failover behavior

A managed-database failover replaces the writable primary with a
previously-replicating secondary. The engine treats this as a transient
outage on every API node and on the scheduler:

1. **During the outage window** (writer paused, errors returned, or
   connection actively draining), every commit-bound operation fails
   with the underlying database error and is **not** acknowledged to
   the caller. Workflow starts, signals, updates, task claims, task
   completions, schedule fires, and history appends all return the
   error rather than silently buffering. There is no in-memory write
   queue that would survive process restart but contradict durable
   state.
2. **In-flight reads** (worker poll, `/api/cluster/info`, query-task
   evaluation, history retrieval) return the error to the caller. The
   long-poll wake layer continues to fire, but every wake-induced
   re-probe likewise hits the database error and returns no work.
3. **Lease bookkeeping does not advance during the outage.** Lease
   expiry is a function of `lease_expires_at` on `workflow_tasks` and
   activity rows, evaluated against `NOW()` *at read time*. If the
   database is unreachable, those reads fail and no expiry decision
   is made; nothing is silently expired against stale data.
4. **At promotion**, the new primary holds the last committed durable
   state up to the configured replication point. Any uncommitted
   in-flight transaction from the old primary is lost — the engine
   never returned success for those, so no caller is misled. The
   acknowledged-writes contract holds: any operation the caller saw
   `2xx`/success for is durable through the failover.
5. **After promotion**, every API node and the scheduler re-establish
   connections through the existing connection-pool reconnect path
   (PDO reconnect, RDS Proxy switchover, PgBouncer reset, etc.).
   Pollers naturally re-discover work on their next configured poll
   interval. No engine-level "drain mode" is required: the same
   `task_repair` cadence that handles ordinary lease expiry resumes
   redelivering any task whose lease passed during the outage.
6. **Bounded recovery**. The maximum visible recovery time is bounded
   by:
   - the database service's own promotion latency (operator's
     responsibility, managed service's contract);
   - one connection-pool reconnect interval per process;
   - one
     `workflows.v2.task_repair.redispatch_after_seconds` cadence
     (default 3 seconds) for stuck tasks to be redispatched;
   - one long-poll timeout (default 30 seconds, max 60 seconds) for
     pollers that returned empty during the outage to retry.

   The engine never extends or skips repair cadence in response to a
   failover event. Operators can plan around these numbers because they
   are the same cadence the system runs at all times.

The engine relies on the database service's published RPO and the
operator's connection-pool configuration. The engine does **not**
attempt automatic write retry across the failover boundary; clients
that received a `5xx` for a write that was in flight during the
outage MUST be prepared to retry, exactly the same as for any
transient database error.

### Database failover with a connection proxy

Connection-pool proxies (RDS Proxy, ProxySQL, PgBouncer) are explicitly
permitted between the API nodes / scheduler and the database. They do
not change any guarantee in this section: the engine's contract is on
the connection it sees, and the proxy is responsible for re-pointing
that connection at the new primary on its own schedule. The engine has
no knowledge of the proxy and no special path for it.

### Idempotency under failover-induced retry

Every public ingress path documented in
[Operational Liveness](../architecture/operational-liveness.md) uses
the run-level lease, the per-task claim lock, and the per-schedule row
lock to make duplicate retries safe:

- starts deduplicate on `workflow_id` plus the requested
  `id_reuse_policy` (the start either creates a new run or returns the
  existing run id, never two);
- task claim is gated by the per-row lock — a retried claim either
  takes the same row or sees it already claimed;
- task completion is gated by the lease holder check — a retried
  completion either matches the current lease or is rejected;
- schedule fire evaluation takes the per-schedule row lock before
  inserting a buffered action row, so a retried tick after failover
  does not double-fire.

These invariants come from
[Execution Guarantees](../architecture/execution-guarantees.md) and
[Scheduler Correctness](../architecture/scheduler-correctness.md);
they hold during managed-database failover for the same reason they
hold during any transient error: the durable substrate is the only
source of truth and is consulted under a lock at decision time.

## Redis (acceleration backend) failover behavior

A managed-Redis failover replaces the cache primary with a
previously-replicating secondary. The engine treats this as a transient
acceleration-layer outage. The
[scheduler correctness](../architecture/scheduler-correctness.md)
contract's degraded-mode behavior applies directly:

- **Wake signals** queued during the outage are dropped. Pollers that
  miss those signals re-probe the durable substrate at their next
  configured poll interval (default long-poll timeout 30 seconds, max
  60 seconds). Discovery latency rises to that bound; correctness is
  unchanged.
- **Worker-compatibility heartbeat cache** falls back to a direct read
  of `worker_compatibility_heartbeats`. Claim decisions stay consistent
  with the Phase 2 routing contract.
- **Query-task queue locks** that disappear with the outage are
  reacquired on the next attempt. A query-task whose lock disappeared
  mid-flight is retried by the caller with the same idempotency
  semantics as any other query call.
- **Task-queue admission locks** fall back to "best-effort latency
  budget" mode: a configured admission budget that depends on a missing
  cache lock degrades to allowing the call rather than blocking it.
  This is the explicit
  [task queue admission](https://durable-workflow.github.io/docs/2.0/polyglot/task-queue-admission)
  contract — admission is acceleration over a workflow-level lock the
  engine already enforces in the durable substrate, so a brief
  acceleration outage does not expose downstream services to traffic
  the workflow logic itself disallows.
- **Notifier fan-out** (Redis pub/sub, PostgreSQL `LISTEN/NOTIFY`,
  NATS) loses any in-flight signal. The
  `LongPollWakeStore::snapshot()`/`changed()` contract requires only
  that pollers eventually observe a different snapshot after a real
  change; missing one signal is not a correctness failure.

After Redis promotion, every API node, every scheduler tick, and every
worker poll re-attaches through the existing client reconnect path.
There is no engine-level cache warm-up: cold caches are served from the
durable substrate on the next poll.

The
[`backend_capabilities`](../long-poll-coordination.md) and
`long_poll_wake_acceleration` health checks surface acceleration-layer
degradation as **warnings**, never as errors, so an operator-side
readiness gate that filters traffic on errors does not flap on Redis
failover.

## API-node loss behavior

An API node may crash, be drained, be reaped by the host, or be
replaced as part of a rolling upgrade. The engine's response is uniform:

- **In-flight HTTP requests** to the failed node fail at the
  load-balancer or client layer. Callers retry through the load
  balancer to a healthy node. Every public ingress path is idempotent
  under retry per the rules in the previous section.
- **In-flight worker polls** against the failed node return the error.
  Workers re-poll against the load balancer; the load balancer routes
  them to a healthy node and they immediately resume claiming work.
  Compatibility pinning, queue routing, and lease ownership are all
  durable, so the new node does not need any handshake from the old
  one to make correct decisions.
- **Tasks that were leased to a worker through the failed node** are
  unaffected: the lease is a row in the durable substrate, not state
  in the API node's memory. Lease expiry and redelivery proceed
  exactly as for any other lease.
- **Cluster discovery** (`/api/cluster/info`) reports the local node's
  identity. There is no engine-level cluster membership view that
  needs reconciliation when a node disappears; per the
  [Multi-Node Requirements](multi-node-requirements.md) contract,
  inter-node coordination is mediated by the database and Redis, not
  by node-to-node RPC.

The load balancer is the only component that needs to learn about node
loss, and it learns about it through the readiness gate described
below.

## Scheduler/maintenance failover behavior

The scheduler/maintenance runner is a singleton. Its HA contract is
*bounded pause and resume*, not concurrent runners:

- **While no scheduler is running**, scheduled workflow starts pause.
  Schedules continue to accumulate in the durable
  `workflow_schedules` rows; their `next_fire_at` is not advanced.
  When a scheduler resumes, every schedule whose `next_fire_at` has
  passed is evaluated on the next tick. The
  [scheduler correctness contract](../architecture/scheduler-correctness.md#scheduler-fire-evaluation)
  fires schedules from durable state, not from a wall-clock timer
  that runs while no scheduler is alive.
- **Activity-timeout enforcement** pauses while no scheduler is
  running. Activity-attempt rows whose lease has expired remain
  visible as "lease expired, pending repair"; they are redelivered
  by `task_repair` once the scheduler resumes.
- **History pruning** pauses while no scheduler is running. Pruning is
  bounded and idempotent; missing one pruning interval grows the
  history table by one interval's worth of expired rows, no more.

The engine relies on the operator to ensure that exactly one
scheduler runs at any moment. **Concurrent scheduler runners are not
a supported topology under this contract** because
`schedule:evaluate`, `activity:timeout-enforce`, and `history:prune`
do not yet enforce a leader lease in the durable substrate. Operators
who need automatic scheduler failover should:

- run the scheduler as a singleton process under their orchestrator
  (for example a Kubernetes `Deployment` with `replicas: 1`, or a
  systemd service guarded by a host-level lease) so that the
  orchestrator handles restart latency, and accept that the resume
  bound is the orchestrator restart latency plus one tick;
- never deploy two scheduler runners against the same database, even
  briefly during a rollover — see split-brain rules below.

A scheduler that is restarted finds its previous tick state by
reading `workflow_schedules` and durable activity rows, not by
recovering an in-memory checkpoint.

## Worker loss behavior

An external SDK worker may crash, be drained, or lose its network
path to the load-balanced API endpoint. The engine's response follows
the worker-loss path frozen in
[Operational Liveness](../architecture/operational-liveness.md):

- **Leased tasks** held by the failed worker remain leased until
  `lease_expires_at`. After expiry, the durable
  `task_repair` cadence redelivers them to a new compatible worker.
  The engine does not mutate run status to "recover" from worker
  loss; it relies on lease expiry alone.
- **Heartbeats** that were in flight during the outage are not
  replayed. The next heartbeat from a new attempt against the same
  activity row starts fresh; partial heartbeat state from the old
  attempt does not split one attempt into two.
- **Worker re-registration** after restart uses `POST /api/worker/register`;
  there is no recovery handshake required from the failed worker.

## Split-brain prevention rules

The engine prevents split-brain — two parties believing they hold the
same authority — through the following invariants. Operators MUST
preserve every invariant during failover:

- **Single writable database, always.** No supported topology has two
  writable workflow database endpoints simultaneously. Managed
  failover is a *promotion* of one secondary to primary; the previous
  primary MUST be fenced (revoke write user, demote with
  `read_only=on`, sever replication, or restore from a known-good
  snapshot) before it ever returns. The active/passive multi-region
  contract names the same fencing rule for cross-region failback;
  single-region managed failover inherits the same requirement at the
  database service layer.
- **Single scheduler/maintenance runner, always.** A second scheduler
  must not be started until the first is provably stopped. "Provably
  stopped" means the previous process exited (the orchestrator
  reports it terminated) or its host is unreachable for longer than
  the longest plausible operation it could be running. The
  duplicate-scheduler outcomes are described below.
- **Per-row lock for every claim and fire.** Workflow-task claim,
  activity-task claim, and schedule-fire commit each take a per-row
  database lock. Two parties that both *try* to claim or fire the
  same row see exactly one winner; the loser observes the row in its
  post-claim state and skips. This is the engine's last-line defense
  against split-brain even if the operator-side invariants above
  are temporarily violated.
- **Lease holder check on completion.** Task completion compares the
  caller's `lease_token` against the row's current
  `lease_owner`/`lease_token`. A completion from a worker whose lease
  has been redelivered is rejected; the new lease holder is the
  authoritative completer.
- **Acknowledged-writes are durable.** The engine never returns
  success for a write that has not committed to the workflow
  database. There is no in-memory buffer that survives a process
  restart but contradicts durable state.

### What duplicate schedulers actually do

Two concurrent scheduler runners against the same workflow database
will both see the same `next_fire_at <= NOW()` rows. The
per-schedule row lock taken by
`ScheduleManager::triggerDetailed()` (per the
[scheduler correctness contract](../architecture/scheduler-correctness.md#scheduler-fire-evaluation))
serializes the actual fire, so a duplicate fire turns into a single
inserted buffered action and one no-op return on the loser side.
That row-lock seam is the engine's last-line defense; it is **not**
an endorsement of running two schedulers. Activity-timeout
enforcement and history pruning are bounded and idempotent but were
not explicitly tested under concurrent runners and are not part of
this contract for the duplicate-scheduler shape.

In short: the engine will not silently corrupt history if duplicate
schedulers are temporarily started, but the operator topology
contract requires "exactly one" and validation testing assumes that.

## Load balancer, readiness, and traffic-shift expectations

The engine publishes four endpoints that govern HA traffic decisions.
Operators MUST wire load balancers to them as follows:

- **`GET /api/health`** proves the process is serving HTTP. Use it as
  the liveness probe — the orchestrator restarts the process when this
  fails. Do **not** shift traffic on `/api/health` alone; a process
  serving HTTP can still be unable to reach the database or Redis.
- **`GET /api/ready`** proves the server can use its configured
  runtime dependencies, including migrations applied and default
  namespace ready. Use it as the readiness probe. The load balancer
  MUST remove a node from the rotation when readiness fails and add
  it back when readiness passes. During managed-database or
  managed-Redis failover, readiness MAY fail on every API node
  simultaneously; the load balancer MUST tolerate the all-down state
  rather than fall back to a stale roster.
- **`GET /api/cluster/info`** proves an authenticated client can
  discover build identity, control-plane protocol, worker protocol,
  payload codecs, and server capabilities. Use it during rollout to
  confirm the new image is in place and to read
  `topology.current_shape`, `topology.current_roles`, and
  `topology.matching_role`.
- **`POST /api/worker/register`** proves workers can authenticate
  into the expected namespace and task queue. Use it as the
  end-to-end smoke after a failover before declaring traffic shifted.

The readiness gate is the engine's single signal for traffic
admission. Acceleration-layer degradation surfaces as a **warning**
on `backend_capabilities` and `long_poll_wake_acceleration`; it does
not fail readiness. Substrate degradation (database unreachable,
migrations unapplied, default namespace missing) does fail readiness.
This split is intentional: a Redis failover should not depopulate the
load balancer rotation; a database outage should depopulate it
because no node can serve write traffic.

### Traffic-shift sequence during failover

When an operator is reacting to a failover event (managed database
promotion, managed Redis promotion, scheduler runner restart):

1. **Do not pre-empt the load balancer's readiness gate.** During the
   outage window, the engine returns errors honestly; readiness will
   fail on its own. Manually draining nodes is not required and may
   prolong recovery.
2. **Wait for `/api/ready` to return 200 on at least one node** before
   resuming external write traffic. The first node to recover its
   substrate connection becomes the readiness anchor.
3. **Re-validate cluster identity** by curling `/api/cluster/info`
   through the load balancer with an admin token; confirm
   `topology.current_shape` and the topology fields are unchanged.
4. **Verify worker registration** by issuing a
   `POST /api/worker/register` against the load-balanced endpoint.
5. **Resume external traffic.** No engine-level "unfreeze" command
   is required.

A failover that completes cleanly leaves the deployment in the same
self-serve shape it had before the failover. There is no manual
reconciliation step against the engine.

## Recovery-time and degraded-mode targets

The engine commits to bounded recovery times for each event class.
Operators can use these as the floor for SLO testing; the actual
observed recovery time is the engine bound plus the managed service's
own promotion or restart latency.

| Event | Engine recovery bound (after substrate / runner is back) | Degraded-mode behavior during the outage |
| --- | --- | --- |
| Managed-database failover | One connection-pool reconnect interval, plus one `task_repair.redispatch_after_seconds` cadence (default 3s), plus one long-poll timeout (default 30s, max 60s) for in-flight pollers. | Writes return errors to callers; reads return errors; lease expiry pauses; no work is silently lost. |
| Managed-Redis failover | One Redis client reconnect interval. | Wake signals dropped → discovery falls back to long-poll timeout (default 30s); compatibility cache falls back to direct DB read; admission locks fall back to "allow"; query-task locks reacquired on next attempt. |
| API node loss (1 of N) | Load-balancer readiness check interval (operator-controlled, typically 5-10s). | Remaining nodes serve full traffic. In-flight requests to the failed node fail at the LB and are retried by the client. |
| Worker loss | Lease expiry interval (5 minutes for activity tasks per `ActivityLease::DURATION_MINUTES`), plus one `task_repair` cadence. | Tasks held by the failed worker pause until lease expiry; other workers continue claiming their own tasks. |
| Scheduler runner loss | Orchestrator restart latency, plus one `ScheduleManager::tick()` cadence (operator-controlled, default 60s). | Schedule fires pause; activity-timeout enforcement pauses; history pruning pauses. All resume on next tick after restart. |

These bounds are **engine guarantees**: they hold whether the
acceleration layer is propagating signals or not, and whether the
deployment is freshly booted or has been running for weeks. They do
**not** include the managed service's promotion latency, the
operator's DNS or load-balancer reconfiguration latency, or
end-to-end client retry latency; those belong in the operator's
recovery packet.

## Operator validation requirements

A deployment that claims this single-region HA contract MUST have
rehearsed the failover events the contract covers and recorded the
evidence in the recovery packet defined in the public
[Operator Operating Envelope](https://durable-workflow.github.io/docs/2.0/operator-operating-envelope).
At minimum the rehearsal MUST prove:

- a managed-database failover completes without acknowledged-write
  loss, and the engine resumes within the bounded recovery time
  above without manual reconciliation;
- a managed-Redis failover does not flap the load-balancer rotation,
  does not lose any acknowledged work, and surfaces only as
  warnings on `backend_capabilities` and
  `long_poll_wake_acceleration`;
- losing one API node during steady-state traffic does not produce
  any acknowledged-write loss and the load balancer removes the
  failed node within the configured readiness interval;
- the scheduler runner can be stopped and restarted on a different
  host without firing duplicate schedules and without leaving any
  schedule unevaluated past its `next_fire_at` plus one tick.

The validation contract is the
[`docs/ha-failover-validation.md`](https://github.com/durable-workflow/server/blob/main/docs/ha-failover-validation.md)
note in the standalone server. A deployment that has not run those
rehearsals is not yet self-serve under this contract; it remains
support-led until the rehearsal evidence is recorded.

## Boundary against unsupported HA claims

The following remain **outside** this contract and remain
support-led; the engine does not promise them and they MUST NOT be
implied by self-serve marketing:

- active/active multi-writer database topologies (RPO=0 cross-writer
  consistency is not an engine primitive);
- automatic regional failover or hands-free cross-region cutover;
- duplicate scheduler/maintenance runners as a steady-state
  topology;
- engine-enforced region-pinned task queues as a routing axis;
- generic "five-nines" or "zero-downtime" SLA claims; the engine's
  contract is *bounded recovery* during named events, not an
  uptime promise that depends on the operator's database, network,
  and orchestrator choices.

The line is intentional: anything in this document is a self-serve
guarantee; anything in the list above is a support-led design
question that depends on the operator's environment.
