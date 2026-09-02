# Multi-Region Deployment Contract

## Overview

This document publishes the self-serve multi-region contract for Durable
Workflow v2. It covers the standalone server distribution and any host that
embeds the `durable-workflow/workflow` package directly. The goal is to make
the supported multi-region shape concrete enough to operate without a
support-led design pass, while keeping the boundaries that the v2 correctness
substrate actually enforces.

The v2 engine separates **correctness substrate** from **acceleration layer**
(see [`scheduler-correctness.md`](../architecture/scheduler-correctness.md)).
The shared workflow database is the correctness substrate; Redis and other
shared cache backends are acceleration. Multi-region operation does not change
that split — it stretches it across regions. Every guarantee below follows
from keeping the substrate single-writer and treating cross-region operation
as an operator responsibility, not a hidden product behavior.

The single-region contract in
[`multi-node-requirements.md`](./multi-node-requirements.md) is the foundation
this document builds on. Multi-region is a region-aware extension of the
multi-node contract; it does not replace it.

## Supported Topology: Active/Passive With Regional Failover

The one self-serve multi-region topology is **active/passive with
operator-driven regional failover**.

- One **active region** serves all workflow starts, signals, updates,
  workflow-task delivery, activity-task delivery, scheduler/maintenance
  passes, and visibility reads. The active region holds the writable workflow
  database, the active Redis, the singleton scheduler/maintenance runner, and
  the API + worker fleet that normally carries traffic.
- One **standby region** holds an asynchronously replicated standby of the
  workflow database, optional standby Redis, no scheduler/maintenance
  process, and zero or more pre-provisioned API and worker containers that
  are idle until promotion.
- A **regional failover** is an explicit operator action. It promotes the
  standby database to writable, starts the singleton scheduler/maintenance
  runner in the standby region, switches worker and operator endpoints, and
  shifts external traffic to the new active region. There is no automatic
  cross-region cutover.
- A **failback** is the symmetric operation, run when the original active
  region returns to service. It is also explicit operator work.

Two shapes are explicitly **not** part of the self-serve multi-region
contract:

- **Active/active across regions.** The v2 correctness substrate assumes one
  workflow database. Cross-region active/active would require a multi-master
  substrate that the engine does not yet model: claim fencing, lease expiry,
  scheduler fire evaluation, mixed-build admission, and the workflow worker
  build-id rollouts table all assume a single writer. Running two active
  regions against asynchronous replicas violates those assumptions.
- **Automatic regional failover.** Promoting a standby database, restarting
  the singleton scheduler in a new region, switching worker endpoints, and
  declaring the new region authoritative are deliberate steps with explicit
  checkpoints. The engine does not provide a controller that performs them
  unattended.

If you need either shape, the topology itself is the product risk and lands
under [support-led design](https://durable-workflow.com/docs/2.0/support).

## Data Authority And Replication

### Workflow database

- The workflow database is the **single durable source of truth** for
  workflow history, task rows, task leases, namespaces, search-attribute
  bindings, schedule definitions, build-id rollouts, deployment lifecycle
  state, and admission/coordination state.
- Exactly one region writes to it at any given time. The standby region
  **must** be configured as a read replica (asynchronous physical or logical
  replication is acceptable) and **must not** accept writes until it has
  been promoted as part of an explicit failover.
- Recovery point objective (RPO) is replication lag at the moment authority
  is withdrawn from the failed region. Operators should publish the RPO they
  observe in steady state and the alerting threshold that triggers a
  failover decision. The engine has no opinion about that threshold; it only
  guarantees that committed rows survive promotion.
- Recovery time objective (RTO) is dominated by operator runbook execution
  time, not engine behavior. The engine resumes from the last durably
  replicated history record once the new primary accepts writes.

Supported substrates remain MySQL 8.0+, PostgreSQL 13+, and MariaDB 10.5+.
SQLite is single-region single-node and is not a multi-region substrate.

### Redis and the acceleration layer

- Redis is **region-local acceleration**. Wake signals, query-task queue
  locks, task-queue admission locks, and queue state do not propagate across
  regions and **must not** be expected to.
- Each region runs its own Redis instance. The standby region's Redis is
  cold or warm at the operator's discretion; correctness does not depend on
  it being preserved across the failover.
- A region whose Redis is degraded continues to make correct progress at
  the durable poll cadence, exactly as documented in the single-region
  multi-node contract. Multi-region operation does not change that
  guarantee.

### Visibility surfaces

- Visibility (workflow listing, search-attribute queries, history exports)
  is served by the active region's workflow database. Reads from the
  standby region are not part of the contract; promote first, then read.
- Projections, search-attribute indexes, and any external visibility export
  driven by the database are derived data; the operator runbook below names
  the rebuild step after failover.

## Namespace, Task-Queue, And Worker-Registration Behavior

- A **namespace** is global within the workflow database. Failover preserves
  every namespace exactly as it was at the last replicated commit;
  namespace identity, default-namespace bindings, and namespace-scoped auth
  do not change.
- A **task queue** is a row-level construct in the workflow database. The
  same task queue exists in the new active region after promotion. Workers
  re-register to the new active region's API endpoint and pick up the same
  queue.
- **Worker registration** is durable. After failover, every worker must
  re-register against the new active API endpoint. Pre-existing
  registrations from the failed region remain in the database and expire
  through the normal worker-expiry path; they do not need manual cleanup.
- **Build-id rollouts and deployment lifecycle state** survive failover
  because they live in the workflow database. A deployment that was
  `Promoted` in the active region remains `Promoted` after promotion of the
  standby; an in-flight rollout resumes from the last replicated row.
- **Sticky regional task queues are not a product feature.** A task queue is
  not regionally partitioned. Operators who want region-local work
  isolation should model it as separate namespaces or task-queue names,
  not as a hidden routing axis.

## Failover, Failback, And Operator Runbook Semantics

A regional failover is a sequenced operator action. The runbook below names
the minimum sequence the engine relies on. Operators may automate any step,
but the engine does not perform the sequence on its own.

### Failover (active → standby)

1. **Stop write traffic to the failed region.** Withdraw the active
   region's API endpoint from external traffic management. If the failed
   region is reachable, stop the singleton scheduler/maintenance runner
   first.
2. **Confirm replication state.** Verify the standby database has applied
   replication up to the last commit acceptable under the published RPO.
   If replication lag exceeds the RPO, the operator must decide between
   accepting data loss and waiting for the lag to drain.
3. **Promote the standby database.** Use the database's native promotion
   path (e.g. `mysql replication promote`, `pg_promote`, managed-database
   provider failover). The promoted database becomes the new authoritative
   workflow database.
4. **Bootstrap the new active region.** Run any migration or bootstrap
   commands required by the running release on the new primary. Confirm
   `/api/ready` and `/api/cluster/info` against an API container in the
   new active region.
5. **Start the singleton scheduler/maintenance runner** in the new active
   region. Exactly one scheduler/maintenance runner must be running across
   the entire deployment after promotion; the failed region's runner must
   not be reachable to the database.
6. **Switch worker endpoints.** Workers in the new active region register
   against the local API endpoint. External workers (in either region) are
   reconfigured to point at the new active region's API.
7. **Switch external traffic.** Move operator traffic, dashboard traffic,
   and any application traffic that starts workflows to the new active
   region's API endpoint.
8. **Rebuild derived projections.** If the deployment maintains
   visibility projections, search-attribute indexes, or external exports
   outside the workflow database, run the documented repair pass.

### Failback (former primary returns)

1. **Treat the returning region as the new standby.** Re-establish
   asynchronous replication from the new active region into the original
   region's database. Do not start a scheduler in the returning region
   until step 5.
2. **Drain in-flight work.** Workflow runs that started in the failed-over
   region must drain naturally or be allowed to continue under the new
   active region. There is no in-engine merge of histories from a region
   that diverged.
3. **Confirm replication catch-up.** Once replication has caught up,
   reverse the runbook above, treating the new active region as the
   source and the returning region as the promotion target.
4. **Verify build-id rollouts and deployment lifecycle state** match
   expectations on both sides before promoting again. A deployment whose
   lifecycle state changed during the failover window must be re-asserted
   against the fleet snapshot before traffic returns.
5. **Promote the returning region** through the same numbered sequence as
   the original failover.

### Split-brain prevention

The contract guarantees correctness only when at most one region's
database accepts writes at a time. If the failed region's database
recovers while the standby is also writable, **the operator must fence
the recovered primary** before reconnecting it. Fencing is operator work;
the engine cannot detect a divergent timeline because both databases
appear authoritative locally. Use the database's native fencing (revoke
write user, demote with `read_only=on`, sever replication, or restore
from a known-good snapshot) before re-attaching as a standby.

A divergent timeline that is reconciled outside the engine — for example
by replaying writes from the recovered primary into the new active
database — is **not** a supported reconciliation mode. The recovered
primary's diverging rows must be discarded or treated as a separate
deployment.

## Consistency And Latency Tradeoffs

| Operation | Authority | Steady-state latency | Failover impact |
| --- | --- | --- | --- |
| Workflow start | Active-region database commit | Active-region commit latency | Refused while authority is withdrawn; resumes after promotion |
| Workflow-task delivery | Active-region database + Redis acceleration | Sub-second when Redis is healthy; long-poll cadence otherwise | Resumes after promotion; in-flight leases survive |
| Activity-task delivery | Same as workflow-task delivery | Same | Same |
| Signal | Active-region database commit | Active-region commit latency | Refused while authority is withdrawn |
| Update | Active-region database commit + activity replay path | Active-region commit latency plus activity round-trip | Refused while authority is withdrawn |
| Visibility read | Active-region database | Database read latency | Available after promotion completes |
| Schedule fire | Active-region scheduler | Scheduler tick cadence | Paused while no scheduler runs; fires resume from durable schedule rows after promotion |

### What the engine does not promise across regions

- **Cross-region read-your-writes.** A client in the standby region cannot
  observe its own writes against the active region until standard
  database replication has applied them. This is the same property the
  underlying database gives you and is not a separate engine guarantee.
- **Cross-region wake propagation.** A workflow created in the active
  region does not produce wake signals in the standby region's Redis.
  Standby workers, if any are running, fall back to the durable poll
  cadence — and per the single-region contract, that is correct, not
  degraded.
- **Sub-region-failover RPO of zero.** Asynchronous replication implies
  a non-zero RPO. The engine does not provide a synchronous-replication
  primitive. Operators who need RPO=0 should treat the topology as
  support-led.

## Disaster Recovery Boundaries

Multi-region active/passive is a recovery-time topology, not a substitute
for backups. Steady-state expectations and disaster-recovery expectations
are separate contracts:

- **Steady state** is the active region serving traffic with the standby
  region holding a replicated database. Replication lag is the only
  inter-region coupling. The single-region contract — claim fencing,
  scheduler correctness, rollout safety, mixed-build admission — applies
  unchanged inside the active region.
- **Disaster recovery** is what happens when the active region is lost.
  The contract is that committed history survives promotion of the
  standby database, lease state continues to be governed by the
  promoted database, and workflows resume from the last replicated
  history record. RPO is the replication lag at the failure instant;
  RTO is operator runbook execution time.
- **The recovery packet** described in
  [Operator Operating Envelope](https://durable-workflow.com/docs/2.0/operator-operating-envelope)
  remains required. Multi-region operation adds replication-lag SLO,
  promotion-runbook latency, last successful failover rehearsal date,
  and the fencing procedure for the recovered primary to that packet.

## Boundaries That Remain Support-Led

The self-serve multi-region contract above does **not** cover:

- Active/active multi-region execution.
- Automatic regional failover or hands-free regional traffic management.
- Synchronous cross-region replication (RPO=0).
- Cross-region active/active visibility, including federated search
  attributes or cross-region history merge.
- Region-aware namespaces or region-pinned task queues as a routing
  axis. Region-affinity through namespace or queue naming is fine; the
  engine does not enforce it.
- Multi-cluster Helm charts and provider-specific managed-database
  failover automation.

These remain
[support-led](https://durable-workflow.com/docs/2.0/support) because the
shape of the topology is part of the product risk.

## Operator Pre-Flight

Before declaring a multi-region deployment self-serve under this contract,
prove the following at minimum:

1. The standby database is replicating from the active database with a
   published, monitored lag SLO.
2. Promotion has been rehearsed end-to-end against a non-production
   environment, and the rehearsal date plus elapsed RTO are recorded in
   the recovery packet.
3. Exactly one scheduler/maintenance runner is running in the active
   region; the standby region's scheduler/maintenance container is stopped
   or scaled to zero.
4. Worker endpoints can be redirected to the standby region's API without
   redeploying worker containers (DNS, service mesh, or env-driven
   endpoint configuration).
5. Operator traffic and external traffic management have a documented
   switch path that does not depend on the failed region being reachable.
6. The fencing procedure for the recovered primary is documented and the
   operator running the failover has the credentials to execute it.

If any of those is missing, the deployment is still in support-led
territory until the gap is closed.
