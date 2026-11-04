# Proposed pre-claim and raw-input boundaries

Status: decision proposal for [#1030](https://github.com/durable-workflow/workflow/pull/1030),
following [Security's revised HOLD](https://github.com/durable-workflow/workflow/pull/1030#issuecomment-6636304884).
No discriminator, routing, producer, capture, reader or input-limit change is
implemented. The existing unknown-event proposal is insufficient by itself.
The accepted portions of the [receive contract](update-message-receive-observations.md)
remain unchanged. This document requests separate General/Security disposition.

## Proposed first scope: newly admitted selected runs

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

No SQL column migration is proposed, but this **does change task and history
protocols**. The task-kind enum, queued class and event must remain unregistered
until that exact expansion is reviewed. No codec identifier is repurposed to
smuggle a reader requirement.

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

Recommend one fixed raw history-input ceiling for **all versions** at official
embedded import, verification and simulation entry points, using the existing
`StructuralLimits::DEFAULT_HISTORY_TRANSACTION_BYTES` value (16 MiB). This is
separate from runtime capture selection and cannot be disabled by turning
capture off. A version marker can be anywhere in JSON; it cannot choose the
pre-decode limit safely. Do not classify a falsely declared or reordered v3
body as harmless legacy input to bypass the bound.

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

1. further design of the new-run-only protected task family plus history read
   fence, including a separately resolved old missing-task-repair boundary,
   rather than authorization to implement a known incomplete fence; and
2. the shared 16 MiB raw/decoded-input admission boundary, with the explicitly
   narrower legacy-v2 acceptance, or a different reviewed parser design.

These are proposals, not implementation or qualification approvals. Producer
fields, capture, replay, import writes, runtime qualification, source pushes,
merge and release remain held. No conformance, downgrade, raw-ingress or full
quality pass is claimed. The corrected receive identity, atomicity, selection,
budget, redaction, corpus and ordinary quality requirements still apply.
