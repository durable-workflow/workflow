# Workflow V2 Worker Compatibility and Routing Contract

This document freezes the v2 contract for worker build identity,
compatibility markers, and how in-flight workflow and activity work is
routed to compatible executors. It is the reference cited by the v2
docs, CLI reasoning, Waterline diagnostics, server deployment guidance,
and test coverage so the whole fleet speaks one language about mixed
builds, rollout, rollback, and the absence of a compatible worker.

The guarantees below apply to the `durable-workflow/workflow` package at
v2 and to every host that embeds it or talks to it over the worker
protocol. A change to any named guarantee is a protocol-level change
and must be reviewed as such, even if the class that implements it is
`@internal`.

This contract builds on the semantics frozen in
`docs/architecture/execution-guarantees.md`. Duplicate execution,
retries, and redelivery keep the language they have there; this document
adds the language for which builds are allowed to execute which work.

## Scope

The contract covers:

- **worker build identity** — what each workflow-task worker and
  activity-task worker process presents to the engine so that operators
  and routing logic can reason about the running fleet.
- **compatibility markers** — the named string that a run is pinned to
  and that a worker advertises as supported. One marker is one
  compatibility family.
- **task and run compatibility** — how compatibility is recorded on
  workflow runs, workflow tasks, and inherited through retries,
  continue-as-new, and child-workflow starts.
- **routing of in-flight work** — how polling, claim, and dispatch
  interact with compatibility so that no task is silently executed by an
  incompatible worker.
- **operator-visible compatibility state** — the fleet and queue
  surfaces that report which markers are live and where.

It does not cover:

- the dedicated task-matching service described by the Phase 3
  roadmap. The Phase 3 surface will replace broad database polling
  with explicit match/dispatch, but must preserve the compatibility
  guarantees below.
- the control-plane/data-plane role split described by Phase 4.
  The split will move compatibility heartbeating onto the control plane
  but must preserve the observable state named here.
- the scheduler independence work described by Phase 5.
- host-level deployment orchestration such as container image selection
  or rolling-restart choreography. Those are deployment concerns that
  consume this contract; they do not define it.

## Terminology

- **Worker build identity** — the tuple `(worker_id, host, process_id,
  namespace, connection, queue, supported[])` recorded by a live worker
  heartbeat. `worker_id` is the stable identifier for one worker
  process; `supported[]` is the set of compatibility markers the worker
  will accept work for.
- **Compatibility marker** — an opaque, operator-chosen string such as
  `build-2026-04-17` or `api-v3`. The engine does not interpret the
  string beyond equality. The special marker `*` means "accept any
  marker" and is reserved for single-build fleets and test harnesses.
- **Compatibility family** — the set of builds that share one
  compatibility marker. Two workers that advertise the same marker are
  interchangeable for routing purposes; the engine guarantees nothing
  else about their code parity.
- **Required marker** — the marker a given workflow task or activity
  task requires. Required markers are resolved from
  `workflow_tasks.compatibility` first, then from the parent run's
  `workflow_runs.compatibility`, and `null` means "no marker required".
- **Pinned run** — a workflow run whose `workflow_runs.compatibility`
  column is set to a non-null marker. A pinned run is routed to workers
  that advertise that marker until the run terminates or is explicitly
  continued-as-new onto a different marker.
- **Fingerprint pinning** — the `workflow_definition_fingerprint`
  recorded on `WorkflowStarted` that pins one run to the class
  definition it started under, independent of the compatibility marker.
  See
  `Workflow\V2\Support\WorkflowDefinitionFingerprint::resolveClassForRun()`.

## Worker build identity

Every live worker maintains a heartbeat row under the
`workflow_worker_compatibility_heartbeats` table (or the legacy fallback
cache when the table is unavailable). The row is owned by one
`worker_id` and carries:

- **`worker_id`** — `hostname:pid:ulid`, generated on first heartbeat
  and stable for the life of the worker process. The ULID segment keeps
  the id unique across hostname/pid collisions.
- **`host`** — the process's hostname as reported by `gethostname()`.
  May be `null` when the host cannot be determined.
- **`process_id`** — the operating-system pid. May be `null` in
  environments where a pid is not meaningful.
- **`namespace`** — the value of
  `workflows.v2.compatibility.namespace` (env
  `DW_V2_COMPATIBILITY_NAMESPACE`). Used to scope one workflow database
  across multiple cooperating apps.
- **`connection`**, **`queue`** — the queue-connection and queue name
  the worker is draining. Either may be `null` when the worker is
  connection- or queue-agnostic.
- **`supported`** — the JSON list of compatibility markers the worker
  will accept. Either the literal `*` (accept any) or a non-empty set
  of markers.
- **`recorded_at`**, **`expires_at`** — the heartbeat timestamp and
  expiry computed from `workflows.v2.compatibility.heartbeat_ttl_seconds`
  (default 30 seconds, configured by
  `DW_V2_COMPATIBILITY_HEARTBEAT_TTL`).

Worker identity is a runtime fact, not a configuration contract. The
only configured inputs are the compatibility markers and namespace; the
rest of the identity is discovered from the process.

## Compatibility markers

A worker's compatibility configuration is two keys:

- **`workflows.v2.compatibility.current`**
  (`DW_V2_CURRENT_COMPATIBILITY`) — the marker this process advertises
  as its own build. When a workflow run is started from this process,
  its `workflow_runs.compatibility` is stamped with this value.
- **`workflows.v2.compatibility.supported`**
  (`DW_V2_SUPPORTED_COMPATIBILITIES`) — the comma-separated list of
  markers this worker will accept when claiming tasks. `*` means
  "accept any marker". Empty/`null` defaults to the current marker.

Guarantees:

- The marker is opaque. The engine performs only exact-string equality
  and the `*` wildcard. It does not order markers, does not interpret
  semver, and does not diff their contents.
- A run stamped with marker `M` is routable only to workers whose
  `supported` list includes `M` or `*`. The engine refuses to dispatch
  or claim it on any other worker and reports the mismatch as an
  explicit operational state rather than running it silently.
- A run stamped with `null` (no required marker) is routable to any
  worker. Pinning is opt-in — single-build fleets do not need to set
  any compatibility config.
- The marker is recorded exactly once per run, at start, from
  `WorkerCompatibility::current()`. Subsequent workflow tasks, activity
  tasks, child runs, retry runs, and continue-as-new runs inherit the
  recorded value. Changing `DW_V2_CURRENT_COMPATIBILITY` on the starter
  process only affects newly-started runs; in-flight runs stay on the
  marker they were stamped with.
- The wildcard marker `*` is an advertisement surface for workers only.
  Runs are never stamped with `*`; that would defeat the purpose.

## Compatibility inheritance

Compatibility flows through the run lifecycle as follows:

- **Start** — a new run is stamped with
  `WorkerCompatibility::current()` on the starter process and the
  value is written to `workflow_runs.compatibility` in the same
  transaction as `WorkflowStarted`. See `DefaultWorkflowControlPlane`
  for the dispatch site.
- **Workflow tasks** — each `workflow_tasks` row carries a
  `compatibility` column. Existing tasks are synced to the owning run's
  compatibility on claim via `TaskCompatibility::sync()` so repair and
  re-enqueue keep the same marker the run was started under.
- **Activity tasks** — activity tasks inherit their run's compatibility
  through the same mechanism. An activity task that cannot yet be
  matched to a compatible worker stays in the task table with its
  marker until one appears; it is never silently redirected to an
  incompatible worker.
- **Retry runs** — when a failed run is retried, the retry run's
  `compatibility` is inherited from the source run. The retry
  continues on the same marker family unless an operator explicitly
  creates a new run on a different marker.
- **Continue-as-new** — the continued run inherits the previous run's
  `compatibility` column. Continue-as-new is the explicit surface for
  moving long-running work onto a new marker; to do that, start a
  fresh workflow from a process that advertises the new marker, rather
  than relying on continue-as-new to translate between markers.
- **Child workflows** — child runs inherit the parent run's
  `compatibility` column. A child started by a parent on marker `M`
  runs on marker `M` so a mixed-version deployment does not split a
  parent/child pair across incompatible workers.
- **Fingerprint pinning** runs in parallel with compatibility pinning.
  Fingerprint pinning guarantees that a run executes against the same
  class *definition* snapshot it started with; compatibility pinning
  guarantees that the run runs on a compatible *worker build*. Both
  guarantees survive redeploy independently.

## Routing and claim enforcement

Routing happens at two surfaces. Both enforce the same marker contract.

### Poll-time filtering

Workers that long-poll the task surfaces pass the
`?compatibility=marker` query parameter to
`GET /workflow-tasks/poll` and `GET /activity-tasks/poll`. The server
filters the eligible task set to rows whose `compatibility` column
matches the requested marker. A worker advertising `*` does not send
the filter and sees the full eligible set.

Poll-time filtering is a performance optimisation. It is not the
correctness boundary — a task that leaks through the filter is still
rejected at claim time by the enforcement below.

### Claim-time enforcement

At claim time, both bridges call `TaskCompatibility::supported()` /
`TaskCompatibility::sync()`:

- `Workflow\V2\Support\DefaultWorkflowTaskBridge::claim()` rejects a
  workflow task with the reason code `compatibility_blocked` when the
  claiming worker's `supported` list does not include the task's
  required marker.
- `Workflow\V2\Support\ActivityTaskClaimer::claimDetailed()` rejects
  an activity task with the reason code `compatibility_unsupported`
  and returns the human-readable mismatch string on the claim
  response.

A rejected claim leaves the task on the queue with its original
compatibility marker. The worker that attempted the claim does not
retry; another worker whose `supported` list covers the marker may
claim it. When no live worker advertises a compatible marker, the task
remains eligible and the condition is observable through the fleet
visibility surfaces below.

### Dispatch-time routing

`Workflow\V2\Support\TaskDispatcher` routes tasks to the Laravel queue
via `connection`/`queue` fields on the task row. Compatibility is not
encoded into the queue name; instead, every worker on that queue
applies claim-time enforcement and parks tasks it cannot run. Operators
who want stronger isolation between compatibility families should use
separate queues per family; the contract above keeps that policy
choice out of the engine.

## Operator-visible state

The fleet and queue surfaces must make mixed-version state explicit to
operators and automation:

- `Workflow\V2\Support\WorkerCompatibilityFleet::summaryForNamespace()`
  returns `active_workers`, `active_worker_scopes`, the live queue
  list, the live `build_ids` list, and the per-worker roll-up. `build_ids`
  is the union of advertised markers across the namespace.
- `Workflow\V2\Support\WorkerCompatibilityFleet::detailsForNamespace()`
  returns one row per `(worker_id, connection, queue)` scope with a
  `supports_required` flag when a required marker is passed. Automation
  should use this call to detect the absence of a compatible worker
  for a pinned run.
- `WorkerCompatibility::mismatchReason()` and
  `WorkerCompatibilityFleet::mismatchReason()` return the canonical
  human-readable mismatch string. CLI, Waterline, and cloud
  diagnostics must surface this string verbatim rather than inventing
  their own language.

Guarantees:

- The absence of a compatible worker is an explicit operational
  state, not an error. It reports as `supports_required=false` on the
  fleet surface and as `compatibility_blocked` /
  `compatibility_unsupported` on the claim path. Product docs, CLI,
  and Waterline should describe it as "no compatible worker is
  registered yet" rather than as "the task failed".
- The heartbeat TTL (`heartbeat_ttl_seconds`, default 30) is the
  upper bound on how stale the fleet view may be. Operators should
  size rollout windows so that the old fleet continues to heartbeat
  until all runs that need it have terminated or been continued onto
  the new marker.

## Rollout and rollback guidance

The contract above is designed to support operator-driven rollout and
rollback without the engine guessing intent:

- **Add a new marker** — deploy a new fleet with a new
  `DW_V2_CURRENT_COMPATIBILITY` value and leave its `supported` list
  set to advertise both the new marker and any markers still in use
  for in-flight runs. The new fleet will start accepting tasks for
  both old and new runs. Starter processes that point at the new
  fleet will stamp newly-started runs with the new marker.
- **Drain an old marker** — stop stamping new runs with the old
  marker (change the starter process's current marker), let pinned
  runs either terminate or continue-as-new onto the new marker, and
  only then remove the old marker from any worker's `supported`
  list.
- **Roll back** — the old fleet still advertises its old marker in
  `supported`; restart the starter processes pointing back at the old
  marker. In-flight runs on the new marker will keep running on the
  new fleet until they finish; no run is quietly rerouted to an
  incompatible build.
- **Observe safety** — automation watching
  `WorkerCompatibilityFleet::detailsForNamespace()` with the
  pinned-run marker should require `supports_required=true` on at
  least one live heartbeat before declaring the rollout healthy. The
  same signal identifies stuck rollbacks.

## What this contract does not yet guarantee

The following are explicitly deferred to later roadmap phases and must
not be assumed:

- Per-task queue routing based on build identity is not provided by
  the engine. Deployments that need stronger isolation across
  compatibility families should use separate queue names.
- Automatic detection of "no compatible worker" as a blocker that
  halts scheduling upstream commands is not provided. The absence is
  observable but operator automation owns the response.
- Protocol-level compatibility negotiation between a worker and the
  engine is not part of this contract. The worker protocol version is
  frozen separately in `Workflow\V2\Support\WorkerProtocolVersion` and
  is independent of the compatibility marker.
- Managed-mode or hosted-mode topology (control-plane / data-plane
  split) is outside this contract. See Phase 4.

## Test strategy alignment

- `tests/Feature/V2/V2CompatibilityWorkflowTest.php` exercises the
  pinning, mismatch, and fleet summary paths end-to-end against the
  workflow engine.
- `tests/Feature/V2/V2OperatorQueueVisibilityTest.php` and
  `tests/Feature/V2/V2OperatorMetricsTest.php` cover the operator
  surfaces that expose `build_ids` and worker scopes.
- This document is pinned by
  `tests/Unit/V2/WorkerCompatibilityDocumentationTest.php`. A change
  that renames, removes, or narrows any named guarantee (marker
  inheritance, claim-time enforcement, the `supports_required` flag,
  the heartbeat TTL contract, or the wildcard marker semantics) must
  update the pinning test and this document in the same change so
  the contract does not drift silently.

## Changing this contract

A change to any named guarantee in this document is a protocol-level
change for the purposes of `docs/api-stability.md` and downstream
SDKs. Reviewers should treat unmotivated changes to the language above
as breaking changes and require explicit cross-SDK coordination before
merge. The Phase 2 roadmap owns updates to this contract;
Phases 3–5 must extend the contract rather than silently redefine it.
