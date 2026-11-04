# Proposed pre-claim and raw-input boundaries

Status: decision proposal for [#1030](https://github.com/durable-workflow/workflow/pull/1030),
following [Security's revised HOLD](https://github.com/durable-workflow/workflow/pull/1030#issuecomment-6636304884).
No discriminator, routing, producer, capture, reader or input-limit change is
implemented. The existing unknown-event proposal is insufficient by itself.
The accepted portions of the [receive contract](update-message-receive-observations.md)
remain unchanged. This document requests separate General/Security disposition.

The [guard-first Security disposition](https://github.com/durable-workflow/workflow/pull/1030#issuecomment-6636758952)
supersedes any one-step task-kind rollout. Security accepts the technical
boundaries below; General's affirmative product/sequencing decision is pending.
The [guard-schema checkpoint](https://github.com/durable-workflow/workflow/pull/1030#issuecomment-6637007789)
accepts the run-level storage shape only subject to that decision and the
precise constraints below. It is not implementation clearance.

## Phase one: guard-only compatibility floor

First propose a guard-only source phase with one engine-owned durable run
discriminator: `workflow_runs.runtime_profile`, a nullable bounded ASCII scalar
of at most 64 bytes. This storage choice is a **proposed schema contract**, not
an implemented migration. Null means the existing legacy profile; empty,
malformed and unknown non-null values must refuse rather than normalize to
legacy. The guard-only engine supports no protected profile. No configurable
wildcard, worker compatibility string or capture flag can override that refusal.

Use the exact byte grammar `\A[a-z][a-z0-9._-]{0,63}\z` with no Unicode mode,
trimming, case folding, normalization or scalar coercion. Compare profile
constants byte-for-byte, independently of database collation. A valid future
token is still unsupported in the guard-only phase. The later producer token
must be a separately reviewed versioned constant, not an operator-defined
extension of the supported set.

Guard-only admission continues to create legacy runs with null profile. It
does not accept a caller-supplied protected profile, register a protected task
kind/event/job, or produce receive observations. Negative fixtures may seed
future profiles directly to prove refusal. Existing runs are never converted.
The current run model permits mass assignment, so production create/admission
and import paths must construct the legacy null value internally. Never copy
this attribute from caller metadata, generic model-fill arrays, exports or
request/import input. Model assignment restrictions alone are not sufficient:
test direct mutation, dirty-model saves and bulk updates, including configured
models. Fixture-only seeding must use explicit setup helpers rather than a
production escape hatch.

The discriminator must be read authoritatively with connection, namespace and
instance/run binding; a stale model, omitted selected column or projection is
not authority to infer null. It must remain available when every task row is
missing. It is immutable after admission, and dropping it while non-null
profiles exist must refuse before schema changes.

Use the repository's configured-model migration pattern for both the actual
configured run table/connection and the default table. An absent guard column,
unknown schema coverage or an unreadable/wrong storage lookup is refusal or
health failure, never legacy. Authoritative reads must bound the raw value
before casting or traversing it, without trusting accessors or cached models.
The down migration must preflight the complete in-scope table/connection
inventory before any DDL; any non-null value or incomplete/unreadable inventory
refuses the whole operation. Null-to-non-null updates on existing runs,
non-null changes and clearing a protected value are forbidden; ordinary writes
cannot erase or switch the admission profile. Immutability needs enforceable
source/storage controls and backend counterproof, not application convention.

The guard runs before any write or application invocation on all creation,
dispatch, poll/claim, repair/watchdog, projection, query/replay, continuation,
redrive and import paths. Include missing-task creation and failure/catch paths.
The refusal must not become a task/run failure, repair attempt or projection
update. Perform it before per-job heartbeat, tracing/application construction,
service-delivery writes or continuation/cache observation when those are
effects of the rejected operation. SQL candidate filters are an optimization;
the authoritative no-write guard must also cover direct/manual operations.

Prove the complete pre-job/pre-repair snapshots required by Security against
this guard-only source, including supported projection roles and deleted task
rows. A later enum exception is not a substitute. Guard tests and a published
guard-only artifact are still not evidence that every application executor and
maintenance process has adopted it.

The negative matrix includes SQLite, MySQL and PostgreSQL; default and
configured run models, tables and storage connections; missing columns and
omitted/stale projections; valid future, empty, malformed, non-ASCII and
overlength values; direct/manual repair and claim paths; and mutation, bulk
update, down-migration and failure injection. Every non-null value is refused
with zero durable, application and continuation/cache effects in phase G.

The producer remains absent until a separately reviewed deployment/admission
policy enforces this minimum floor for every in-scope executor, repairer,
watchdog, import/query route and rollback path. A heartbeat inventory, user
boolean, cooperative shutdown or README instruction is not enforcement proof.
The profile column cannot make unmodified 2.20.3 honor a future requirement.
Once protected runs exist, 2.20.3 is **not a safe rollback target**. A safe
rollback may go only to the proved/adopted guard-only floor, which refuses those
profiles before effects. If that minimum cannot be enforced, return a reviewed
storage/routing-isolation design instead. No deployment, credential or isolation
operation is authorized by this source proposal.

## Phase two: newly admitted selected runs

Reserve observation-aware execution when a **new run is admitted** under
explicit application/workflow selection. Do not convert an already-started run,
rewrite its tasks or infer that an expired lease means an old process stopped.
An existing run keeps legacy behavior even if capture selection changes later.
Its historical #1024 limitation remains unresolved.

This narrows the earlier newly-executed-handler proposal. It is a product and
compatibility decision, not already accepted scope. It avoids a specific race:
an old job can commit its ordinary task claim before a new handler writes the
first receipt. Changing that task's kind later cannot undo the earlier claim
or make old `executeClaim()` repeat its task-kind admission check.

For a selected new run, propose these durable discriminators:

| Surface | Proposed value and obligation |
| --- | --- |
| Core task kind | Add `TaskType::WorkflowUpdateReceiveV1`, wire value `workflow_update_receive_v1`. All workflow tasks belonging to a protected run use it from their creation. |
| Queued job | Use a distinct `RunUpdateReceiveWorkflowTask` class for the protected kind. Do not serialize it as the old `RunWorkflowTask`. |
| History capability | Record `UpdateMessageReceiveObservationsRequired` before `WorkflowStarted`, atomically with run admission and before publishing its first task. This is the read requirement, not automatic capture selection. |
| Capture policy | The existing default-off explicit selection still controls new live handler capture. Disabling capture never removes the task/read discriminators or permits old readers to ignore recorded receipts. |

The accepted old queued job reads the cast `task_type` before changing task
status, lease, attempt, service delivery or projections. An unknown kind throws
at that read; a model returning a raw string also fails the strict comparison
to `TaskType::Workflow`. Old service polling selects literal `workflow`, and
old explicit service claims check task kind before claim mutation. Old
`TaskDispatcher` constructs the job from task kind before recording dispatch
attempt metadata. These are source reasons to investigate this discriminator,
not an executed complete counterproof.

The history event remains necessary for direct query/replay callers, which do
not go through task admission. Placing it before `WorkflowStarted` also lets the
old fingerprint scan encounter it before construction when fingerprint pinning
is enabled. The later old version-marker scan is not relied on as a claim
guard. Countercontrols must cover disabled pinning, configured model casts,
constructors, entry/update/query calls and every supported query route. No
application workflow method may run and no logical query state may be returned.

Phase one introduces a separately reviewed run-discriminator schema contract;
phase two additionally changes task and history protocols. The task-kind enum,
queued class and event must remain unregistered until that exact expansion and
floor-adoption proof are reviewed. No codec identifier is repurposed to smuggle
a reader requirement.

## Required lifecycle and downgrade audit

The source inventory has 42 files referring to `TaskType::Workflow`. Replacing
that value globally would expose the new histories to legacy service workers.
Instead, review every creation, dispatch, poll, claim, repair, resume, timeout,
cancel, projection and terminal path by execution mode.

**Known incomplete path, not merely an unrun test:** accepted
`TaskRepair::createMissingTask()` can create a literal plain `workflow` task
when no task remains and the supplied summary says `repair_needed`. Its final
fallback does not consult the proposed protected kind or unknown history event.
A protected kind only on existing task rows therefore cannot be the complete
repair/downgrade fence. The new-run restriction avoids old in-flight claims,
but does not solve this missing-row path. Return a reviewed maintenance/run
admission discriminator or routing isolation for that path before treating the
task-family proposal as viable. Do not silently add another status, summary
value, codec or database fence in response.

- Every new workflow task for a protected run must retain the protected kind.
  No repair or recovery path may recreate a plain `workflow` task for that run.
- Portable SDK polling/claim/fetch/completion remains unable to select the new
  embedded kind until separately reviewed capability negotiation exists. This
  proposal does not extend the portable SDK protocol or qualify Server imports.
- Audit generic old repair and task-delivery paths, not just the successful
  queued-job path. A kind that is rejected after repair metadata changes does
  not meet the zero-effects requirement. No safety claim follows merely from
  the enum definition or a separate queue name.
- Do not migrate ready or leased tasks on existing runs. No old lease/cache
  drain assumption, process-presence assertion or cooperative shutdown is an
  activation fence.
- Continue-as-new and redrive must preserve any inherited read requirement
  whenever replay lineage depends on an observed source. Marker inheritance
  must not opt in new capture. Before those paths are qualified, refuse the
  protected operation before writes rather than creating an unfenced child.
  Existing unselected/legacy recovery remains unchanged.
- The ordinary history event sequence increases for the capability event.
  It is not a workflow step, and no artificial receive/cursor offset is added.
- A dedicated queue may reduce accidental mixed delivery, but cannot replace
  the task-kind check. No queue resource or deployment is created by this plan.

The exact-old-source control must start before the whole job/poll/claim/repair
operation. Compare tasks including leases/sticky/claim/dispatch/repair fields,
service delivery, run state, summaries/projections, failures, messages, history,
cursor and continuation/cache state. Include default and supported custom or
cached projection paths, warm continuation, retries and catch/failure handlers.
If any supported old path mutates through the discriminator, this proposal
remains held. An exception after a committed claim is not a passing refusal.

## Raw ingress: request an explicit shared admission decision

Security accepts one fixed raw history-input ceiling for **all versions** at
official embedded import, verification and simulation entry points. Propose
the separately named security boundary `HistoryBundleInput::MAX_BYTES`, fixed
at 16 MiB. Its value happens to match the existing default transaction budget,
but it is not an alias for that budget and does not inherit its configurable
or zero/disabling semantics. It is also independent of runtime capture
selection. A version marker can be anywhere in JSON; it cannot choose the
pre-decode limit safely. Do not classify a falsely declared or reordered v3
body as harmless legacy input to bypass the bound. This is technical acceptance
only; General still owns the affirmative legacy-admission decision.

For files and request streams, read at most the ceiling plus one detection
byte before decoding; do not trust file size or Content-Length alone. Handle
short reads, replacement/growth and chunked requests without allocating the
whole claimed body. Reject oversize before JSON decode, canonicalization,
signature validation, recursive semantic traversal or row lookup. Then use the
existing decoder and bounded depth, validate version/capabilities and apply the
existing no-write v3 import refusal. The receipt's tighter admitted/count/ID
bounds still apply.

Official untrusted decoded-array entry points also need bounded depth, nodes,
scalar bytes and checked traversal work before semantic validation or lookup.
Re-encoding an arbitrary already-decoded graph cannot establish a pre-decode
guarantee. V3 processing needs bounded-source provenance; a caller-owned decode
outside the official boundary cannot be advertised as protected by this API.

**This intentionally narrows legacy admission.** Previously valid version-2
inputs above the ceiling would now be refused. Contents and behavior of
admitted v2 bundles remain unchanged, but that is not byte-for-byte unchanged
acceptance. The same disclosure applies to newly bounded legacy decoded-array
entry points. General must explicitly accept or reject that change before it
is implemented, and the release/compatibility decision remains provisional.
Do not hide it behind the observation flag, a new version number, header order
or an import error after an unbounded decode.

The alternative is a separately reviewed globally bounded streaming
discriminator/envelope and parser design that preserves valid legacy-v2
admission. No such parser, dependency or framing primitive is proposed or
implemented here. A streaming preflight would still need its own bounded-work
proof, including a version at the tail of a large common prefix. The existing
JSON Schema is not that proof.

## Requested disposition and remaining gates

General/Security need to decide whether to authorize preparation of:

1. the guard-only run-discriminator/source phase, including its new schema
   contract and complete no-effects controls, without any producer; and
2. the independent fixed 16 MiB raw/decoded-input security boundary, with the
   explicitly narrower legacy-v2 acceptance, or a different reviewed parser
   design.

Protected new-run production, task/protocol registration and minimum-floor
deployment/adoption are later, separate decisions. There is no direct jump from
source guard tests to producer activation or a safe rollback claim for 2.20.3.

These are proposals, not implementation or qualification approvals. Producer
fields, capture, replay, import writes, runtime qualification, source pushes,
merge and release remain held. No conformance, downgrade, raw-ingress or full
quality pass is claimed. The corrected receive identity, atomicity, selection,
budget, redaction, corpus and ordinary quality requirements still apply.
