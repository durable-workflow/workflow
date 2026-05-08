# Webhook and Command Taxonomy Contract

This document freezes the v2 contract for the external command surface
of a workflow deployment: which operator/integrator commands exist,
how they are named, how their HTTP webhook routes are shaped, what
outcomes they expose, and how legacy adapters plug into the same
intake. It is the reference cited across the product when talking about
"what can the outside world ask a workflow to do."

The guarantees below apply to the `durable-workflow/workflow` package
at v2, to the standalone `durable-workflow/server` that embeds it, and
to every host that embeds the package directly or talks to the server
over HTTP. A change to any named command, route shape, or rejection
outcome listed here is a protocol-level change and must be reviewed as
such, even if the class that implements it is `@internal`.

This contract builds on the language frozen in
`docs/architecture/control-plane-split.md` (which role accepts these
commands) and `docs/architecture/security-governance.md` (how command
attribution and webhook authentication are recorded). It is consumed by
`Workflow\V2\Contracts\WorkflowControlPlane`, `Workflow\V2\Webhooks`,
the `WorkflowStub` operator API, the standalone server's API surface,
the `WorkflowControl` Waterline view, and the workflow CLI.

## Scope

The contract covers:

- **command taxonomy** — the closed set of operator-initiated commands
  that exist in first release: `start`, `signal`, `update`, `repair`,
  `cancel`, `terminate`, `archive`. `query` and `describe` are read
  operations that share the same intake but are not durable commands.
- **command outcomes** — the explicit `accepted` / `rejected` plus
  outcome codes (`started_new`, `cancelled`, `archived`, etc.) that
  every command response carries, and the rejection codes that callers
  may rely on for control flow.
- **route shape** — how aliases, public instance ids, and (optional)
  run ids appear in the canonical webhook URLs the package registers.
- **alias registry** — that webhook exposure is driven by an explicit,
  caller-supplied alias map, never by filesystem or class-graph
  scanning.
- **legacy bridge boundary** — what the v1 HTTP matrix is allowed to
  cover, and what it is explicitly not allowed to cover (no v2-alpha
  shapes).
- **start response shape** — that every accepted start exposes the
  outcome, public instance id, and current run id together.

It does not cover:

- the durable workflow-task command set (workflow programs scheduling
  activities, timers, or child workflows). Those are internal to the
  workflow-task contract and are governed by
  `docs/architecture/execution-guarantees.md`, not by this taxonomy.
- the worker plane lifecycle (claim, heartbeat, complete, fail) for
  workflow tasks and activity tasks. Those endpoints live under the
  same route base for transport convenience, but they are bidirectional
  worker protocol, not operator commands.
- "hard delete" of workflow data. Hard deletion is intentionally **out
  of scope** for first release until archival, export, and audit
  surfaces have matured; until then, archive plus retention is the
  closest supported operation.
- `reset`. `reset` is reserved as a separate later-phase operator
  command; it is not part of the first-release taxonomy and must not be
  added to the surfaces below until that phase ships.

## Command taxonomy

The first-release operator command taxonomy is exactly:

| Command     | Intent                                                                                  | Target                              | Durable artifact                       |
|-------------|-----------------------------------------------------------------------------------------|-------------------------------------|----------------------------------------|
| `start`     | Create a workflow instance, its first run, and a ready workflow task.                   | workflow type alias + instance id   | `WorkflowInstance`, `WorkflowRun`, `WorkflowCommand` (`start`), first `WorkflowTask` |
| `signal`    | Deliver a named signal to an open run.                                                  | instance id (default) or run id     | `WorkflowCommand` (`signal`), `WorkflowSignal`, history event |
| `update`    | Submit a synchronous update to an open run with optional completion wait.               | instance id (default) or run id     | `WorkflowCommand` (`update`), `WorkflowUpdate`, history event |
| `repair`    | Re-project run summary, detect liveness issues, and re-create a workflow task when the run is repairable. | instance id (default) or run id     | `WorkflowCommand` (`repair`), repaired `WorkflowTask` |
| `cancel`    | Request cooperative cancellation of an open run.                                        | instance id (default) or run id     | `WorkflowCommand` (`cancel`), history event |
| `terminate` | Force a non-cooperative terminal state on an open run.                                  | instance id (default) or run id     | `WorkflowCommand` (`terminate`), history event |
| `archive`   | Mark a terminal run as archived so its data is eligible for cold storage and retention. | terminal run                        | `WorkflowCommand` (`archive`), history event |

`describe` and `query` are observation surfaces that share the same
HTTP intake and the same authentication model but are explicitly not
listed above: they are not durable commands and do not record a
`workflow_commands` row.

### Out of scope and reserved

- **Hard delete.** No `delete`/`destroy` command exists. Operators that
  need data to be gone must rely on archive plus retention pruning.
  Hard deletion stays out of scope until archival, export, and audit
  surfaces are mature enough to safely tombstone authoritative truth.
- **`reset`.** `reset` is a reserved name for a later-phase operator
  command (replay-from-event, equivalent to a "rewind and continue").
  It must not be added to the surfaces below until that phase ships.

## Command outcomes

Every operator command response is a JSON object with at least:

- `accepted` — boolean, `true` when the command was durably recorded as
  accepted, `false` otherwise.
- `outcome` — a string outcome code from `Workflow\V2\Enums\CommandOutcome`.
- `rejection_reason` — a string reason code when `accepted` is `false`,
  `null` otherwise. (Older payload field names — `reason`, `command_status` —
  remain present for backward compatibility; consumers should treat them
  as aliases of the same data.)
- `workflow_id` / `workflow_instance_id` — the public instance id.
- `run_id` / `workflow_run_id` — the run id the command was applied to,
  when applicable.
- `command_id` / `workflow_command_id` — the durable id of the recorded
  command row.

### Accepted outcomes

| Outcome                        | Source command(s)        | Meaning                                                           |
|--------------------------------|--------------------------|-------------------------------------------------------------------|
| `started_new`                  | `start`                  | A brand-new instance was created.                                 |
| `returned_existing_active`     | `start`                  | An active instance with the same id already existed and was returned (only when `duplicate_start_policy=return_existing_active`). |
| `signal_received`              | `signal`                 | Signal was durably recorded for delivery.                         |
| `update_completed`             | `update`                 | Update completed within the wait window.                          |
| `update_failed`                | `update`                 | Update was admitted and produced a failure result.                |
| `repair_dispatched`            | `repair`                 | Repair re-created a workflow task.                                |
| `repair_not_needed`            | `repair`                 | Run was already healthy; no repair task was needed.               |
| `cancelled`                    | `cancel`                 | Cancellation was durably recorded.                                |
| `terminated`                   | `terminate`              | Terminate was durably recorded.                                   |
| `archived`                     | `archive`                | Archive marker was durably written for the run.                   |
| `archive_not_needed`           | `archive`                | Run was already archived; the command was a no-op.                |

### Rejected outcomes

Rejections are first-class. The intake never throws an exception across
the boundary; instead, every recognised rejection becomes a stable
outcome code so adapters can translate to HTTP, CLI exit codes, or
Waterline banners deterministically.

| Rejection code                              | Trigger                                                                                              |
|---------------------------------------------|------------------------------------------------------------------------------------------------------|
| auth failure (HTTP `401`/`403`)             | Webhook authenticator rejected the request before any command was admitted.                          |
| `rejected_invalid_arguments`                | Argument shape failed validation (`signal`, `update`, `start`).                                      |
| `rejected_unknown_signal`                   | The named signal does not exist on the workflow class.                                               |
| `rejected_unknown_update`                   | The named update does not exist on the workflow class.                                               |
| `rejected_duplicate`                        | Duplicate `start` against an active instance (default `duplicate_start_policy=reject_duplicate`).    |
| `rejected_not_started`                      | Command targeted a reserved instance id whose first run never started.                               |
| `rejected_not_active`                       | Command targeted a closed run that requires an open run (signal, update, cancel, terminate).         |
| `rejected_run_not_closed`                   | `archive` targeted a run that is not yet terminal.                                                   |
| `rejected_not_current`                      | A non-current run was targeted by a command that requires the current run.                           |
| `rejected_compatibility_blocked`            | Routing layer refused to admit the command under the current compatibility envelope.                 |
| `rejected_pending_signal`                   | Signal-with-start admitted a duplicate while a signal was already pending.                           |
| `rejected_workflow_definition_unavailable`  | Local workflow class is required but cannot be resolved (e.g. for `query`).                          |

Webhook validation failures (missing required fields, bad types) emit
the standard Laravel validation envelope at HTTP `422`; these are
distinct from `rejected_invalid_arguments` outcomes, which apply to
argument-shape rejections that do reach the durable command layer.

Unknown alias is a webhook-routing rejection: an unregistered alias
returns HTTP `404` from Laravel routing before any command intake
runs. Aliases must be explicitly registered (see "Alias registry"
below); there is no fallback path that resolves a missing alias.

## Routing model

### Public route shape

The package registers webhook routes under a single configurable base
path (`config('workflows.webhooks_route', 'webhooks')`). The canonical
shapes — and the only shapes documented for callers — are:

```
POST  {base}/start/{alias}
POST  {base}/start/{alias}/signals/{signal}            # signal-with-start
POST  {base}/instances/{workflowId}/signals/{signal}
POST  {base}/instances/{workflowId}/runs/{runId}/signals/{signal}
POST  {base}/instances/{workflowId}/queries/{query}
POST  {base}/instances/{workflowId}/runs/{runId}/queries/{query}
POST  {base}/instances/{workflowId}/updates/{update}
POST  {base}/instances/{workflowId}/runs/{runId}/updates/{update}
GET   {base}/instances/{workflowId}/updates/{updateId}
GET   {base}/instances/{workflowId}/runs/{runId}/updates/{updateId}
POST  {base}/instances/{workflowId}/repair
POST  {base}/instances/{workflowId}/runs/{runId}/repair
POST  {base}/instances/{workflowId}/cancel
POST  {base}/instances/{workflowId}/runs/{runId}/cancel
POST  {base}/instances/{workflowId}/terminate
POST  {base}/instances/{workflowId}/runs/{runId}/terminate
POST  {base}/instances/{workflowId}/archive
POST  {base}/instances/{workflowId}/runs/{runId}/archive
GET   {base}/instances/{workflowId}/describe
GET   {base}/instances/{workflowId}/runs/{runId}/describe
POST  {base}/control-plane/start
```

(Worker-plane endpoints — `activity-tasks/*`, `workflow-tasks/*` — are
governed by the worker protocol contract and are out of scope here.)

Two addressing conventions are guaranteed:

- **Path identifiers are always public ids.** `{alias}` resolves a
  workflow type alias from the explicit registry below; `{workflowId}`
  is the caller-visible workflow instance id; `{runId}` is the public
  run id. Internal numeric primary keys never appear in the route, in
  the response payload, or in URL helpers. URL helpers (named routes
  such as `workflows.v2.signal`) accept the same public ids.
- **Instance addressing is the default.** For every operator command
  that takes a run, the instance-only form (`/instances/{workflowId}`)
  exists alongside the run-scoped form
  (`/instances/{workflowId}/runs/{runId}`). The instance form targets
  the current run; the run-scoped form targets the named run
  explicitly. Adapters must default new clients to the instance form
  unless a specific run is being addressed.

### Canonical names

Each route is registered with a stable, alias-derived name (`workflows.v2.start.{alias}`,
`workflows.v2.signal`, `workflows.v2.runs.signal`, etc.). These are
part of the contract: they are the names used by `route(...)` URL
helpers in PHP and are stable across releases for any registered alias.

### Alias registry

Webhook exposure is driven by an **explicit alias registry** passed to
`Workflow\V2\Webhooks::routes($workflows)`. The contract is:

- The caller passes either bare workflow class strings (in which case
  the alias is the workflow's `#[Type(...)]` value) or `alias =>
  workflow-class` pairs.
- An alias must match `^[A-Za-z0-9._-]+$` and must point at a class
  that extends `Workflow\Workflow`. Anything else throws at registration.
- A workflow without a `#[Type(...)]` attribute and without an
  explicit alias key is a registration error, not a silent skip.

There is **no filesystem scan, no class-graph traversal, and no
auto-discovery** that exposes additional workflows over the webhook
surface. This is deliberate: surface is opt-in so that adding a class
to the codebase never silently adds an external command surface.

The same alias registry feeds the canonical route names; signal,
update, query, repair, cancel, terminate, and archive routes are
derived from stable workflow/signal aliases rather than from random
identifiers, so the operator-facing URL of a given workflow alias
remains stable across deployments.

### Start response shape

A successful `start` response always exposes the trio:

- `outcome` — `started_new` or `returned_existing_active` (never both
  silently merged).
- `workflow_instance_id` (and the legacy alias `workflow_id`) — the
  public instance id, whether caller-supplied or freshly minted.
- `workflow_run_id` (and the legacy alias `run_id`) — the current run
  id of the (possibly already-existing) instance.

This is the contract callers may rely on to chain follow-up commands
without re-querying for the run id, and to distinguish a fresh start
from a returned-existing one without inspecting durable storage.

`start` responses use HTTP `202 Accepted` for `started_new`, `200 OK`
for `returned_existing_active`, and `409 Conflict` for any rejection
(duplicate, validation, unknown alias-derived workflow, etc.). The
`409` body still carries the explicit `outcome` and
`rejection_reason` fields described above.

## Legacy webhook bridge

Some deployments need to keep accepting requests against the v1 URL
shapes that pre-date this taxonomy. The legacy bridge exists to support
that, but is bounded:

- The legacy bridge accepts **literal v1 URLs only** — the URL shapes
  shipped in workflow v1. It is not a "v2-alpha-shaped" bridge.
- The legacy bridge maps v1 start and v1 signal URLs into the modern
  command intake (so they get the same durable command rows, the same
  outcome codes, and the same authentication path as native v2
  webhooks).
- The HTTP matrix the legacy bridge supports is **frozen at the v1
  matrix**. Adding a new v1-shaped route, or changing the response
  shape of an existing v1 route, is a contract change that requires a
  named exception. The intent is that the legacy bridge is a
  compatibility shim for existing v1 callers, not a parallel surface
  that grows alongside v2.
- v2-alpha URL shapes are explicitly **not** part of the legacy bridge
  and are not migrated. v2-alpha is discarded on upgrade and is
  documented as a release-note item rather than as a code path. There
  is no v2-alpha-to-v2 migration adapter.

In short: the legacy bridge covers v1, and only v1; v2-alpha is a
release-note concern.

## Adapters over command intake

The HTTP webhook surface is one adapter on top of a single command
intake. Other adapters (CLI, the standalone server's API, integration
tests, the legacy bridge) plug into the same intake and inherit the
same outcome model. Any adapter the package documents must therefore:

- Resolve type aliases and public instance ids before reaching intake
  (no internal numeric ids leaking into the adapter contract).
- Default to instance addressing for `signal`, `update`, `cancel`,
  `terminate`, and `archive`; expose run-scoped variants only when the
  caller explicitly addresses a non-current run.
- Surface the same `outcome` and `rejection_reason` codes that intake
  produces. Adapters may translate them to transport-specific exit
  codes or banners but must not invent new outcome names or hide
  rejection codes behind generic errors.
- Record `command_context.source` so the durable command row reflects
  which adapter admitted the request (e.g. `webhook`, `cli`,
  `waterline`, `php`).

## Compatibility and change control

Adding a new operator command, a new accepted outcome, or a new
rejection code is a contract change covered by this document and by
the protocol-stability guarantees in `docs/api-stability.md`. Renaming
or removing any of the names above — including the rejection codes —
requires a major-version bump for the affected adapter surface.

`reset` and any future `delete`/`destroy` command must come back
through this contract before they appear on any surface.
