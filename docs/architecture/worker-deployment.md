# Workflow V2 Worker Deployment Contract

This document freezes the v2 contract for the **first-class worker
deployment surface** that operators and agents use to promote, drain,
resume, and roll back worker fleets. It is the reference cited by
product docs, CLI reasoning, Waterline diagnostics, server deployment
guidance, cloud orchestration, and test coverage so the whole fleet
speaks one language about deployment lifecycle, long-lived workflow
compatibility policy, and machine-readable rollout blockage.

The guarantees below apply to the `durable-workflow/workflow` package
at v2, to the standalone `durable-workflow/server` that embeds it, and
to every host that embeds the package directly or talks to the server
over HTTP. A change to any named guarantee is a protocol-level change
and must be reviewed as such, even if the class that implements it is
`@internal`.

This contract builds on the semantics frozen in
`docs/architecture/execution-guarantees.md` (Phase 1), the routing
guarantees frozen in `docs/architecture/worker-compatibility.md`
(Phase 2), the matching and dispatch guarantees frozen in
`docs/architecture/task-matching.md` (Phase 3), the role-split
contract frozen in `docs/architecture/control-plane-split.md`
(Phase 4), the cache-independence contract frozen in
`docs/architecture/scheduler-correctness.md` (Phase 5), and the
rollout-safety contract frozen in
`docs/architecture/rollout-safety.md` (Phase 6). Build-id cohort
operations, drain semantics, fingerprint pinning, the fleet
snapshot, and the rollout-safety envelope keep the language they
have there; this document adds the language for how those primitives
roll up into a coherent deployment lifecycle.

## Scope

The contract covers:

- **deployment identity** — what a deployment is, how it is named,
  and which scope (namespace, task queue, build id, optional workflow
  types) it binds to.
- **deployment lifecycle** — the explicit state machine
  (`Pending`, `Active`, `Promoted`, `Draining`, `Drained`,
  `RolledBack`) that replaces the implicit "infer the rollout state
  from worker counts and drain intent" behavior.
- **workflow compatibility policy** — the per-deployment choice
  between `Pinned` (the default safe posture: long-lived runs stay on
  the deployment they were started against) and `AutoUpgrade` (opt-in
  forward migration within the single-step compatibility window).
- **blockage diagnoses** — the machine-readable reason codes returned
  when a promote, drain, resume, or rollback is refused.
- **promotion safety** — how the deployment surface consults the
  fleet snapshot, the recorded workflow definition fingerprint, and
  the replay-safety guardrail before a promotion can succeed.
- **server-controlled rollout semantics** — how the standalone
  server exposes the lifecycle as a single coherent surface rather
  than as a sprawl of one-off build-id endpoints.

It does not cover:

- the worker compatibility marker contract itself; Phase 2 froze
  what `WorkerCompatibility::current()` and
  `TaskCompatibility::required()` mean and Phase 7 inherits that
  meaning. A deployment's `required_compatibility` IS that marker.
- the build-id rollout durable storage shape; Phase 6 froze the
  `workflow_worker_build_id_rollouts` table as the rollout substrate
  and Phase 7 reads through it rather than redefining it.
- replacement of the matching role's claim-time enforcement. The
  deployment surface decides whether a deployment is **eligible** to
  receive new work; the matching role still enforces that decision at
  claim time per Phase 3 and Phase 6.
- multi-region active/active deployment coordination. Cross-region
  deployment is a future roadmap topic and is not covered.
- automatic promotion controllers (canary splitters, percentage
  ramps, traffic shifters). Those are deployment **tooling** that
  consumes this contract; they do not define it.

## Terminology

- **Deployment** — the long-lived envelope around a `(namespace,
  task_queue, build_id)` cohort. A deployment carries lifecycle
  state, a compatibility policy, an optional set of workflow type
  bindings, and the audit timestamps needed for the lifecycle
  surface. A deployment is the unit operators promote, drain,
  resume, and roll back.
- **Lifecycle state** — the named state on
  `Workflow\V2\Enums\DeploymentLifecycleState`. Promote, drain,
  resume, and rollback each name a transition; refusals carry a
  machine-readable reason code.
- **Compatibility policy** — the named value on
  `Workflow\V2\Enums\WorkflowCompatibilityPolicy` that governs whether
  long-lived runs may auto-upgrade to a newer compatible deployment
  (`AutoUpgrade`) or must remain on the deployment they were started
  against (`Pinned`, the default).
- **Promotion** — the operator action that marks a deployment as the
  current target for fresh workflow starts. Promotion is gated by
  fleet, fingerprint, and replay-safety checks; refusals surface as
  `DeploymentBlockage` records.
- **Drain** — the cooperative action that stops a deployment from
  accepting new work. Phase 6 already froze the worker-side drain;
  Phase 7 lifts it to the deployment surface so operators do not
  have to drain build-ids one at a time.
- **Resume** — the inverse of drain: a deployment that was
  `Draining` (but has not yet reached `Drained`) returns to its
  prior accepting-work state.
- **Rollback** — the operator action that surrenders the promoted
  slot. The promoted deployment becomes `RolledBack`; a different
  deployment must be promoted before fresh work routes again.
- **Blockage** — a machine-readable diagnosis of why a lifecycle
  transition cannot proceed safely. Every blockage carries a stable
  reason enum value, a human-readable message, an `affected scope`
  map, and a concrete `expected_resolution` description.
- **Affected scope** — the namespace, task queue, build id,
  workflow type set, or compatibility marker the blockage applies
  to. Scope is structured (a map), not embedded in the message
  string, so operator UIs can route the diagnosis without parsing
  text.

## Deployment identity

Every deployment is named by the tuple `(namespace, task_queue,
build_id)`. The deployment **name** rendered to operators is
`namespace/task_queue@build_id`, and `unversioned` substitutes for a
null build id (the pre-rollout default). The name is stable across
restarts and is what the CLI, Waterline, and cloud display verbatim.

`Workflow\V2\Support\WorkerDeployment` is the authority on the
deployment shape. The value object is constructed via two named
constructors:

- `WorkerDeployment::forActiveBuild()` — used when a fresh deployment
  is registered from a worker heartbeat or operator action.
- `WorkerDeployment::fromRolloutRow()` — used when reading the
  rollout state back out of the durable
  `workflow_worker_build_id_rollouts` table. The shape is a map so
  the standalone server can pass its Eloquent row through directly
  without an adapter.

Guarantees:

- The `name()` method returns the operator-visible identifier and
  MUST NOT change shape across releases. Renaming the format
  (`namespace/queue@build_id`) is a protocol-level change.
- The deployment is the single value object the lifecycle surface
  reads and writes; consumers MUST NOT reach back into the rollout
  row directly to mutate state.
- Workflow type bindings are normalized: leading/trailing whitespace
  is trimmed, duplicates are collapsed, the result is sorted.
  Operators reading the workflow type list see a deterministic order.

## Deployment lifecycle

`Workflow\V2\Enums\DeploymentLifecycleState` defines the explicit
state machine. The states form a small DAG:

```
   Pending ──promote──> Active ──promote──> Promoted
      │                   │                    │
      │                   └─drain──> Draining ─drain─> Drained
      │                   │                    │
      │                   └────────resume──────┘  (back to Active)
      │                                       │
      └──────────rollback to Active or Promoted of a prior deployment
```

Guarantees:

- **`Pending`, `Active`, and `Promoted` accept new work.** The
  matching role MUST NOT route fresh tasks to a deployment whose
  state is `Draining`, `Drained`, or `RolledBack`.
  `DeploymentLifecycleState::acceptsNewWork()` is the single boolean
  the rest of the system consults; a state added in a later phase
  MUST update that method rather than re-deriving the answer in each
  caller.
- **`Drained` and `RolledBack` are terminal.** A terminal deployment
  cannot be promoted or resumed; operators must create a new active
  deployment for the same build id instead.
  `DeploymentLifecycleState::isTerminal()` is the authority on the
  terminal set.
- **Promotion is fail-closed under safety checks.** The planner
  consults the fleet snapshot, the recorded workflow definition
  fingerprint, and the replay-safety guardrail; refusals surface as
  one or more `DeploymentBlockage` records. The matching role MUST
  NOT route work to a promoted deployment whose blockages would have
  refused the promotion in the first place.
- **Drain is cooperative.** Per Phase 6, drain stops the deployment
  from accepting **new** claims; in-flight work runs to completion.
  Drain MUST NOT cancel or fail durable runs.
- **Resume is only valid against `Draining`.** A `Drained` deployment
  has already lost its workers; resuming it is a contract violation
  that surfaces as `IncompatiblePolicy`.
- **Rollback surrenders the promoted slot.** The deployment moves to
  `RolledBack`; the matching role stops considering it for fresh
  starts. Operators MUST then promote a different deployment to
  resume normal routing.
- **Lifecycle transitions are logged.** The deployment row records
  `promoted_at`, `drained_at`, and `rolled_back_at` so operators can
  reconstruct the deployment history without log archaeology.

## Workflow compatibility policy

`Workflow\V2\Enums\WorkflowCompatibilityPolicy` defines the per-deployment
choice that governs long-lived runs:

- **`Pinned` (default)** — runs started against this deployment
  remain on its recorded `workflow_definition_fingerprint`. The
  matching role refuses claims whose worker fingerprint does not
  match (per Phase 6's `DW_V2_PIN_TO_RECORDED_FINGERPRINT`
  guarantee). `Pinned` is the safe default: long-lived workflows do
  not silently move to a newer build.
- **`AutoUpgrade`** — runs are allowed to migrate forward within the
  single-step compatibility window guaranteed by Phase 2. The
  matching role may route the next workflow task to a worker
  advertising the next compatible marker even if the run was started
  against an older deployment.

Guarantees:

- The policy is per-deployment, not per-namespace. Operators can opt
  one workflow type into auto-upgrade without forcing every other
  workflow in the namespace to follow.
- `WorkflowCompatibilityPolicy::allowsAutoUpgrade()` and
  `::requiresFingerprintPin()` are the two booleans the rest of the
  system consults. Adding a third policy is allowed; renaming or
  removing one is a contract-level change.
- A deployment that lists a `workflow_types` binding restricts the
  policy to those workflow types. A deployment with an empty
  `workflow_types` binding applies the policy to every workflow
  type the deployment serves on its task queue.
- The default policy is `Pinned`. A deployment whose row does not
  declare a policy resolves to `Pinned` so an unmigrated rollout row
  cannot silently opt into auto-upgrade.

## Blockage diagnoses

`Workflow\V2\Support\DeploymentBlockage` is the value object every
lifecycle refusal carries. Every blockage has:

- `reason` — a `Workflow\V2\Enums\DeploymentBlockageReason` enum value
  (machine-readable; pinned by this contract).
- `message` — the human-readable string that names the affected scope
  inline. Operators read this in the CLI; Waterline surfaces it.
- `scope` — a structured map containing at minimum `namespace`,
  `task_queue`, `build_id`, and `state`, plus any
  blockage-specific keys (`required_compatibility`, `recorded_fingerprint`,
  `workflow_types`). Consumers route the diagnosis off the scope
  map, never by parsing the message.
- `expected_resolution` — a one-line description of the concrete
  next action (e.g. "Roll a worker that supports compatibility [v3]
  before promoting."). The CLI prints this verbatim so operators
  know exactly what to do next.

### Frozen blockage reason codes

The following values on `DeploymentBlockageReason` are part of this
contract. Renaming or removing any of them is a protocol-level
change. Adding a new code is allowed.

| Code | Meaning |
| ---- | ------- |
| `no_compatible_workers` | Fleet has zero live workers in the deployment's `(namespace, task_queue)` scope. |
| `fleet_is_draining` | The deployment itself is `Draining` or `Drained` and the requested transition would resurrect it. |
| `fingerprint_mismatch` | The deployment's recorded `workflow_definition_fingerprint` is not advertised by the live fleet. |
| `replay_safety_failed` | `WorkflowModeGuard` (or another replay-safety check) reported an `error` severity issue against the deployment's workflow types. |
| `missing_worker_heartbeat` | The fleet has live workers in scope but none advertise the deployment's `required_compatibility` marker. |
| `incompatible_policy` | The requested transition is invalid for the current lifecycle state (e.g. promote against `RolledBack`). |
| `unknown_deployment` | No rollout row exists for the requested `(namespace, task_queue, build_id)` tuple. |

`Workflow\V2\Support\DeploymentLifecyclePlan` is the planner that
returns the blockage list for each transition. It is a pure function
of the deployment value object plus a fleet snapshot map; callers
that already hold the fleet snapshot (server, cloud) MUST consult the
planner rather than re-deriving the diagnosis themselves.

### Promotion check matrix

`DeploymentLifecyclePlan::evaluatePromote()` evaluates, in order:

1. Whether the deployment's lifecycle state allows promotion. A
   terminal deployment (`Drained`, `RolledBack`) yields
   `incompatible_policy`. A `Draining` deployment yields
   `fleet_is_draining`.
2. Whether the fleet has live workers at all. Zero active workers
   yields `no_compatible_workers`.
3. Whether the live workers advertise the deployment's required
   compatibility marker. Some workers but none compatible yields
   `missing_worker_heartbeat`.
4. Whether the recorded fingerprint matches the live fleet. A
   mismatch yields `fingerprint_mismatch`.
5. Whether `WorkflowModeGuard` reports an `error`-severity replay
   safety issue. An error yields `replay_safety_failed`.

The planner returns **every** applicable blockage, not just the
first one. The CLI and Waterline render them as a list so operators
see the full diagnosis in one round trip.

### Drain, resume, and rollback checks

- `evaluateDrain()` only refuses against `RolledBack`, where drain
  is meaningless. Draining a `Promoted` deployment is allowed; the
  planner trusts the operator to have a follow-on promotion ready.
- `evaluateResume()` refuses against `Drained` and `RolledBack`
  (both terminal); resume against an already-`Active` deployment is
  a no-op rather than a blockage so operators may invoke resume
  idempotently.
- `evaluateRollback()` refuses against `Pending` (nothing to roll
  back) and against `RolledBack` (already terminal). Rollback against
  `Active` or `Promoted` is allowed.

## Server-controlled rollout semantics

The standalone server exposes the lifecycle through a coherent HTTP
surface so operators do not have to know which legacy build-id
endpoint they need:

| Method | Route | Purpose |
| ------ | ----- | ------- |
| `GET` | `/api/deployments` | List every deployment in the namespace, projected from the rollout table plus the live fleet snapshot. |
| `GET` | `/api/deployments/{name}` | Fetch a single deployment by name (`namespace/task_queue@build_id`). |
| `POST` | `/api/deployments/{name}/promote` | Promote the named deployment after consulting the lifecycle plan. |
| `POST` | `/api/deployments/{name}/drain` | Move the named deployment to `Draining`. Returns the rolled-up worker counts the planner consulted. |
| `POST` | `/api/deployments/{name}/resume` | Move a `Draining` deployment back to `Active`. |
| `POST` | `/api/deployments/{name}/rollback` | Surrender the promoted slot. |

Guarantees:

- Each lifecycle endpoint MUST return a `200` with the new
  deployment shape on success and a `409` with the
  `DeploymentBlockage::toArray()` shape on refusal. The shape is
  stable; `409` is reserved for "the deployment is in a state that
  refuses this transition."
- Every refusal includes the **full** blockage list, not just the
  first reason. The list is ordered: configuration blockages
  (`incompatible_policy`, `fleet_is_draining`) appear first,
  followed by fleet blockages
  (`no_compatible_workers`, `missing_worker_heartbeat`,
  `fingerprint_mismatch`), followed by safety blockages
  (`replay_safety_failed`).
- The legacy `POST /api/task-queues/{taskQueue}/build-ids/drain` and
  `/resume` endpoints continue to work unchanged so existing
  operators are not broken; the new deployment surface is **additive**
  on top. A future major version may retire the legacy build-id
  routes.
- Server-controlled rollout means the server is the authority on
  whether a deployment may be promoted, not a worker-count heuristic.
  A deployment with zero matching workers MUST refuse promotion
  through the lifecycle plan; the server MUST NOT silently treat
  worker-count drift as an implicit promotion.

## Connecting replay-safety and rollout-safety to promotion

The Phase 6 rollout-safety contract froze the
`worker_compatibility`, `routing_health`, and `backend_capabilities`
health checks; Phase 7 connects them to the promotion decision:

- A deployment's promotion plan reads
  `WorkerCompatibilityFleet::detailsForNamespace()` to populate the
  fleet snapshot. The `active_workers_supporting_required` count and
  the advertised marker list are exactly the keys frozen in
  `docs/architecture/rollout-safety.md` under the
  `workers.required_compatibility` and `workers.fleet` rows on
  `OperatorMetrics::snapshot()`.
- The replay-safety severity is the worst severity reported by
  `WorkflowModeGuard::check()` against the deployment's workflow
  types. A deployment with no `workflow_types` binding falls back
  to the namespace-wide guardrail result so an empty binding cannot
  mask a replay-safety regression.
- A `replay_safety_failed` blockage carries the raw
  `WorkflowModeGuard` messages in the `replay_safety_messages`
  fleet-snapshot key so operators see the same wording the boot-time
  admission would have surfaced.

This is the explicit connection the Phase 6 contract pointed forward
to: replay-safety and rollout-safety checks now gate promotion
decisions through one stable surface rather than being a mix of
boot-time warnings and after-the-fact metric watching.

## Long-lived workflow compatibility decisions

The compatibility policy is the long-lived decision Phase 7 names
explicitly:

- **`Pinned`** is the safe default. A run started against a
  `Pinned` deployment continues replaying on the deployment's
  recorded `workflow_definition_fingerprint` for its entire lifetime.
  The matching role refuses claims with a fingerprint drift; the
  refusal surfaces through the `claim_failed` and
  `compatibility_blocked_runs` metrics frozen in Phase 6.
- **`AutoUpgrade`** opts the deployment into Phase 2's single-step
  compatibility window. Long-lived runs may migrate forward to the
  next compatible deployment without operator intervention. The
  promoter takes responsibility for proving the new build is
  replay-safe — Phase 7 does not weaken Phase 1's at-most-once and
  at-least-once guarantees.
- A deployment whose `compatibility_policy` is unset resolves to
  `Pinned`. The legacy build-id rollout rows are interpreted as
  `Pinned` so an unmigrated row cannot silently opt into
  auto-upgrade.

A change of compatibility policy is itself a deployment-shape
change: operators MUST recreate the deployment (with a fresh
`(namespace, task_queue, build_id)` tuple) rather than mutating an
in-place row. This keeps the audit history honest about which
posture each generation of the fleet ran under.

## Test strategy alignment

- `tests/Unit/V2/WorkerDeploymentTest.php` covers the value object's
  named constructors, name format, accepts-new-work projection, and
  `withState` audit-stamp behavior.
- `tests/Unit/V2/DeploymentLifecyclePlanTest.php` covers the
  planner's blockage matrix for each transition (promote, drain,
  resume, rollback) against a deterministic fleet snapshot.
- `tests/Unit/V2/WorkerDeploymentDocumentationTest.php` pins the
  contract document itself: the named headings, the lifecycle
  states, the compatibility policies, the frozen blockage reason
  codes, and the HTTP route table. Renaming any named guarantee
  must update this test and the documented contract in the same
  change so deployment behavior does not drift silently.
- The standalone server's deployment HTTP surface is covered by the
  server repo's own feature tests; the contract here pins the route
  shape and the `409` blockage response shape so server-side and
  package-side tests cannot disagree.

## What this contract does not yet guarantee

The following are explicitly deferred to later roadmap work and
MUST NOT be assumed:

- Automatic canary or percentage-ramp promotion. The deployment
  surface lets operators promote a deployment; deciding what
  fraction of fresh starts route to the newly promoted deployment is
  a follow-on roadmap topic. Phase 7 promotes 100% on success.
- Cross-region active/active deployment coordination. The
  lifecycle, fleet snapshot, and rollout-safety surfaces above
  assume a single workflow database.
- Per-workflow-run policy overrides. The compatibility policy is a
  deployment property; per-run overrides are not part of the Phase 7
  contract. A future phase may add a policy-override field on the
  workflow start surface.
- Replacement of the build-id rollout durable surface. Phase 7 reads
  through the Phase 6 `workflow_worker_build_id_rollouts` table; a
  schema replacement is a future roadmap topic.
- Any change to the Phase 4 role split. Phase 7 must be implementable
  inside the existing roles as defined; it does not move authority
  between roles.

## Changing this contract

A change to any named guarantee in this document is a
protocol-level change for the purposes of `docs/api-stability.md`
and downstream SDKs. Reviewers should treat unmotivated changes to
the language above as breaking changes and require explicit
cross-SDK coordination before merge. The Phase 7 roadmap owns
updates to this contract; any follow-on architecture phase must
extend the contract rather than silently redefine it. The Phase 1,
Phase 2, Phase 3, Phase 4, Phase 5, and Phase 6 contracts remain
the foundation this document builds on.
