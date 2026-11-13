# Continue-as-new onto the promoted build (draft contract)

Draft contract for [workflow issue 880](https://github.com/durable-workflow/workflow/issues/880),
following General's 2026-11-04 decision there and server's answer of
2026-11-13. Nothing here is shipped. By default, continue-as-new still copies
the run's compatibility marker to the successor.

## Problem

A continued chain stays on the build it started on forever. The successor
starts with empty history and only its arguments, so nothing from the old
build needs replaying, but there is no way to say "start the next run on the
current build". Old builds stay deployed for as long as any chain lives.

## Authoring

The continue-as-new request carries a target:

- `inherited` (the default): today's behavior. The successor keeps the
  predecessor's `compatibility`.
- `promoted`: the successor starts on the promoted build for the exact
  namespace, task queue and workflow type, resolved at admission.

```php
// Embedded (proposed)
return continueAsNew($state)->onPromotedBuild();
```

```json
// Service mode (proposed, protocol field)
{"type": "continue_as_new", "arguments": "…", "target_build": "promoted"}
```

The author is responsible for the arguments: the successor's code is the
promoted build's, so the arguments must be compatible with what that build
accepts. The guides will say this plainly.

## Resolution at admission

Resolution happens inside the transaction that closes the predecessor and
creates the successor, under the same run lock:

- **Native Server:** uses the durable `promoted_cohort` lookup for namespace
  and queue, then proves that the selected cohort serves the exact workflow
  type within this admission transaction. The lookup alone does not prove
  type eligibility. A native pin is the build id, stored in `compatibility`.
  Native does not require a live heartbeat: a valid promoted cohort can have
  zero workers during a restart or scale from zero.
- **Embedded:** has no promotion registry. The first slice reads
  `workflows.v2.compatibility.promoted` (`DW_V2_PROMOTED_COMPATIBILITY`).
  Every build's workers must see it, because a pinned chain's continue-as-new
  runs on a worker of the *old* build. The target is admissible only if a live
  eligible worker heartbeat matches the exact namespace, queue, workflow type
  and compatibility marker, using the existing freshness cutoff and worker
  status authority. A marker-bearing heartbeat alone cannot prove type
  eligibility. Missing or unsupported eligibility evidence refuses admission.
  A later slice can replace the config with a promotion row the deploy step
  writes.

If the resolved build equals the predecessor's, the transition records it as
`promoted`, and nothing else changes.

### Refusal

With no promoted build, or none serving the workflow type, or (in embedded) no
live eligible worker supporting the promoted marker, the continue-as-new
admission transaction refuses before any write. That transaction leaves the
predecessor, task lease and history unchanged, creates no successor, and records
no build transition.

The worker may then report the typed reason `promoted_build_unavailable` in a
separate ordinary task-failure operation. That report uses the existing task
attempt, lease and retry fences. Its normal failure, lease-release and retry
bookkeeping may write history; those writes are not part of the refused
continue-as-new transaction. A later attempt can resolve promotion again,
within the original execution deadline.

For service mode the proposed admission response is HTTP 409 with
`reason: promoted_build_unavailable`, `retryable: true` and
`request_admitted: false`. Native must qualify worker handling of that response
before advertising support. It is neither an accepted transition nor a failed
workflow.

The embedded failure report retains one bounded structured record at
`workflow_tasks.payload.continue_as_new_refusal`, containing the reason, task
attempt number and recording time. The run-detail task view exposes that
record together with the task id, status and existing `available_at` retry
time. It reads the indexed task row rather than scanning history or parsing
`last_error`. Only the matching current task attempt can expose a current
refusal. The next claim clears it under the claim fence; a stale report cannot
restore it. A supported observation returns `continue_as_new_refusal: null`
after that clear so an open detail view removes the old refusal. Unsupported
observations remain unavailable. Show `available_at` as a retry time only when
the current task status and remaining retry policy schedule another attempt;
an exhausted or terminal task must not get a countdown from a retained value.
Retained failure history remains historical after recovery.
Native exposes the same observation from its fenced task-failure record.
Before a failure report is admitted, the response itself supplies the refusal;
run detail must not invent a persisted refusal.

Waterline keeps the predecessor's actual open state and displays the task
refusal separately, without a successor link. Operator guidance is to verify
the promoted build's exact namespace, queue and type, then observe normal
retry. Continuing with `inherited` remains an author choice; a UI retry action
must not silently rewrite the pending command.

## Recorded choice; replay never rereads promotion

Both `WorkflowContinuedAsNew` (predecessor) and the successor's `WorkflowStarted` record:

```json
"build_transition": {
  "from_compatibility": "build-a",
  "to_compatibility": "build-b",
  "resolution": "inherited | promoted"
}
```

Both marker values may be null for unpinned runs. Missing legacy metadata
cannot invent a named build. The successor's `workflow_runs.compatibility` is
`to_compatibility`. Replay,
query replay, history export and redrive read only these recorded values, and
no replay path looks at the current promotion. With `inherited`, the field is
recorded with equal `from`/`to` when the opt-in protocol is in use. Histories
from earlier releases have no field and mean `inherited`.

Waterline shows the transition on both runs: "continued onto build-b
(promoted) from build-a", with exact recorded predecessor/successor links in
the existing lineage section. A bounded run-detail observation exposes the
complete recorded transition without a history scan for each list refresh.
Partial or unsupported metadata stays unavailable. Rendering never resolves
current promotion, including when both recorded markers are equal.

## Budgets and lineage

The execution deadline is inherited unchanged and the run deadline is derived
per run, as for any continue-as-new. Child supervision hand-over ([workflow issue 868](https://github.com/durable-workflow/workflow/issues/868)) is
unchanged: handed-over children keep their own builds. Lineage links are
unchanged; the transition is an attribute of the existing continue-as-new
link.

## Promotion races

Promotion is read once, under the admission transaction. A promotion that
commits during the transition is either seen (the successor gets the new
build) or not (it gets the previous promoted build). Either result is recorded
and final for that successor; the next continue-as-new resolves again. A
rollback after the transition doesn't move an already started successor.

## Vectors

| # | Setup | Action | Expected |
| --- | --- | --- | --- |
| 1 | Chain on A, promoted B serves the exact type; embedded has eligible live B workers | `continueAsNew()->onPromotedBuild()` | Successor `compatibility: B`; both events record `{from: A, to: B, resolution: promoted}`; the successor's first task is claimed only by B workers |
| 2 | Same | Plain `continueAsNew()` | Successor on A, `resolution: inherited` (or no field without the opt-in protocol) |
| 3 | Chain on A, no promoted build | `onPromotedBuild()` | Admission refuses with `promoted_build_unavailable`; predecessor, history and lease unchanged; no successor or transition writes. A separately fenced failure report may record the refusal and normal retry bookkeeping |
| 4 | Embedded, promoted B configured, no live B heartbeat | `onPromotedBuild()` | Same refusal as #3 |
| 5 | Run 1 continued from A to B (recorded) | Cold replay of run 1 and run 2 after promotion moves to C | Run 1 replays to `ContinueAsNewCall`; run 2 replays on its recorded B; nothing reads C |
| 6 | Promotion A→B commits while the continue-as-new transaction runs | — | The successor is on exactly A or B, recorded; no successor on an unrecorded build |
| 7 | Chain on A with an open request-cancel child | `onPromotedBuild()` | Child handed over ([workflow issue 868](https://github.com/durable-workflow/workflow/issues/868)) on its own build; successor on B |
| 8 | History from 2.27 or earlier | Replay | No `build_transition` field, treated as `inherited` |
| 9 | Native promoted B serves the type, no live B worker | `onPromotedBuild()` | Admission succeeds on durable promotion; worker restart does not require a new transition |
| 10 | Promoted B has only a wrong-type, stale or otherwise ineligible embedded heartbeat | `onPromotedBuild()` | Refused before transition writes; marker presence alone is insufficient |
| 11 | Current refusal recorded, then a new claim or successful retry | Run-detail refresh and delayed old failure report | Current refusal clears under the claim fence; old report cannot restore it; retained failure remains historical |
| 12 | Unpinned predecessor, recorded transition has a null marker | Replay and run-detail rendering | Preserve nullable marker and exact lineage; no invented build or live promotion lookup |
| 13 | Exhausted or terminal task retains `available_at` | Bounded run-detail refresh | Report the actual task status and no scheduled retry; the retained timestamp does not create a countdown |

## Ownership and activation

Workflow owns embedded admission, recorded transitions and bounded task/run
observations. Server owns native type eligibility, admission refusal and worker
retry qualification. Waterline consumes those recorded observations. These
vectors and worker replacement/replay fences must pass before either runtime
advertises the opt-in; this draft enables no capability.
