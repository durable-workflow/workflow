# Continue-as-new onto the promoted build (contract)

Contract for [workflow issue 880](https://github.com/durable-workflow/workflow/issues/880),
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

### Closed target values

`target_build` is a closed enum, and only its absence selects the legacy
default:

- **Absent:** `inherited`, exactly as today.
- **Present:** the value must be exactly the string `inherited` or `promoted`.
  Explicit `null`, booleans, numbers, arrays, objects, empty strings, other
  casings and unknown strings are invalid. No parser, normalizer or
  compatibility layer may coerce an invalid value to the absent default.

An invalid value is refused before promotion lookup and before any predecessor,
task, lease, history, successor or transition write. The refusal is
non-retryable and is distinct from `promoted_build_unavailable`:

- service mode returns HTTP 422 with `reason: invalid_target_build`,
  `retryable: false` and `request_admitted: false`;
- embedded `continueAsNew()` raises the same reason at the authoring call
  before the task completes.

An invalid target is never reported as a promotion refusal. It records no
`continue_as_new_refusal`, schedules no promotion retry and shows no retry
countdown. Any later task failure the worker reports follows the existing
invalid-command handling.

### Capability negotiation

Runtimes released before this contract drop unknown continue-as-new fields:
`DefaultWorkflowTaskBridge::normalizeContinueAsNewCommand()` on current main
keeps only `arguments`, `payload_codec`, `workflow_type` and `queue`. Sending
`target_build` to such a runtime would silently continue on the inherited build.
Those releases cannot be fixed after the fact, so the emitting side carries the
gate:

- A runtime that supports this contract advertises the exact capability
  `continue_as_new_target_build` in its server capabilities, alongside a worker
  protocol version bump. A worker or client may put `target_build` (either
  value) on a command only when the runtime it is talking to advertised that
  capability for the current session. Without it, the SDK raises
  `unsupported_target_build` locally at the authoring call and sends nothing.
- A runtime that recognises `target_build` but has not activated the opt-in,
  for example because native worker handling is not yet qualified, refuses any
  command carrying the field with HTTP 422 `reason: unsupported_target_build`,
  `retryable: false`, `request_admitted: false`. It must not ignore the field
  or fall back to `inherited`.
- Embedded: `onPromotedBuild()` exists only in package versions that implement
  this contract. If the embedded runtime has the opt-in disabled, the call
  raises `unsupported_target_build`; it never continues on the inherited build.

This capability check happens before, and is separate from, the
promoted-build eligibility check in the next section.

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

Promoted resolution uses the same selectable-for-new-starts predicate that a
start uses. A cohort that start would not select, such as one that is drained,
draining or retired, is never chosen, and the request is refused as below.

If the resolved build equals the predecessor's, the transition records it as
`promoted`, and nothing else changes.

### Build retirement

Native build and task-queue retirement
([server PR 1241](https://github.com/durable-workflow/server/pull/1241)) runs
its inventory and guarded write inside the same serialized transition that
starts and continue-as-new take. Retirement therefore cannot interleave with a
promoted transition. Until the transition commits, the predecessor counts
against its own build's `open_runs` and `continued_runs`. Afterwards the
successor counts against the promoted build. Retirement refuses a build that is
`selected_for_new_starts`, so the current promoted target cannot be retired from
under a chain, and a retired build is never a promoted target. This is the
operational payoff: chains that opt in leave old builds at their next
continue-as-new, so an old build's retirement inventory can reach zero while
long-lived chains continue. Handed-over children keep their own pins and keep
counting against their own builds until they close.

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
per run, as for any continue-as-new. Child supervision hand-over
([workflow issue 868](https://github.com/durable-workflow/workflow/issues/868)
in embedded, and its native parity in
[server PR 1246](https://github.com/durable-workflow/server/pull/1246)) is
unchanged: handed-over children keep their own builds, recorded identities and
parent-close policies, and the chain's final run enforces those policies
whatever build it runs on. Lineage links are
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
| 7 | Chain on A with an open request-cancel child | `onPromotedBuild()` | Child handed over ([workflow issue 868](https://github.com/durable-workflow/workflow/issues/868); native [server PR 1246](https://github.com/durable-workflow/server/pull/1246)) on its own build; successor on B |
| 8 | History recorded before this contract shipped | Replay | No `build_transition` field, treated as `inherited` |
| 9 | Native promoted B serves the type, no live B worker | `onPromotedBuild()` | Admission succeeds on durable promotion; worker restart does not require a new transition |
| 10 | Promoted B has only a wrong-type, stale or otherwise ineligible embedded heartbeat | `onPromotedBuild()` | Refused before transition writes; marker presence alone is insufficient |
| 11 | Current refusal recorded, then a new claim or successful retry | Run-detail refresh and delayed old failure report | Current refusal clears under the claim fence; old report cannot restore it; retained failure remains historical |
| 12 | Unpinned predecessor, recorded transition has a null marker | Replay and run-detail rendering | Preserve nullable marker and exact lineage; no invented build or live promotion lookup |
| 13 | Exhausted or terminal task retains `available_at` | Bounded run-detail refresh | Report the actual task status and no scheduled retry; the retained timestamp does not create a countdown |

Target-value and capability vectors. Each expects zero predecessor, task, lease,
history, successor and transition writes, no promotion lookup, no
`continue_as_new_refusal`, no promotion retry and no successor:

| # | Setup | Command | Expected |
| --- | --- | --- | --- |
| 14 | Supporting runtime, opt-in active | `target_build: null` | 422 `invalid_target_build`, `retryable: false`, `request_admitted: false` |
| 15 | Same | `target_build` is `true`, `false`, `0`, `1`, `[]` or `{}` | Same as vector 14 |
| 16 | Same | `target_build` is `""`, `"Promoted"`, `"latest"` or `"promoted "` | Same as vector 14 |
| 17 | Same | Field absent | Accepted as `inherited` (vector 2), proving that only absence selects the default |
| 18 | SDK connected to a runtime that does not advertise `continue_as_new_target_build` | Author calls the promoted-build option | SDK raises `unsupported_target_build` locally; no command is sent |
| 19 | Runtime recognises the field, opt-in not active | `target_build: "promoted"` or `"inherited"` | 422 `unsupported_target_build`, `retryable: false`, `request_admitted: false`; never inherited |
| 20 | Embedded runtime with the opt-in disabled | `continueAsNew()->onPromotedBuild()` | Raises `unsupported_target_build` at the authoring call; the task does not complete on the inherited build |
| 21 | Promoted cohort B is drained, draining or retired | `onPromotedBuild()` | Treated as unavailable: `promoted_build_unavailable` (vector 3), never a successor on B |
| 22 | Native retirement of A races a promoted transition off A | — | Serialized: either retirement sees the open predecessor and returns `live_dependencies`, or it runs after the commit and does not count the chain against A |

Vectors 21 and 22 are the eligibility and retirement cases; unlike 14 to 20 they
reach the promotion lookup.

## Ownership and activation

Workflow owns embedded admission, closed target validation, recorded
transitions and bounded task/run observations. Server owns native type
eligibility, closed target validation, admission refusal, capability
advertisement and worker retry qualification. The PHP, Python and Rust SDKs
own the emitting-side capability gate. Waterline consumes those recorded
observations. All vectors above and worker replacement/replay fences must pass
before either runtime advertises `continue_as_new_target_build`; this contract
enables no capability.
