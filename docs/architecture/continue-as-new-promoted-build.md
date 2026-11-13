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

- **Native Server:** resolves the promoted cohort for (namespace, queue,
  workflow type), the same `promoted_cohort` lookup as a start. A native pin is
  the build id, and it is stored in `compatibility`.
- **Embedded:** has no promotion registry. The first slice reads
  `workflows.v2.compatibility.promoted` (`DW_V2_PROMOTED_COMPATIBILITY`).
  Every build's workers must see it, because a pinned chain's continue-as-new
  runs on a worker of the *old* build. The target is admissible only if a live
  worker heartbeat on that queue supports the marker (the existing
  worker-compatibility fleet). A later slice can replace the config with a
  promotion row the deploy step writes.

If the resolved build equals the predecessor's, the transition records it as
`promoted`, and nothing else changes.

### Refusal

With no promoted build, or none serving the workflow type, or (in embedded) no
live worker supporting the promoted marker, continue-as-new is refused **before
any write**. The predecessor stays open, its workflow task fails with the typed
reason `promoted_build_unavailable`, and its lease and history are unchanged.
The task is retried under the normal workflow-task retry. The refusal is
visible in run detail as a task failure with that reason, never as a silently
stranded successor. The author can retry later, or continue with `inherited`.

## Recorded choice; replay never rereads promotion

Both `WorkflowContinuedAsNew` (predecessor) and the successor's `WorkflowStarted` record:

```json
"build_transition": {
  "from_compatibility": "build-a",
  "to_compatibility": "build-b",
  "resolution": "inherited | promoted"
}
```

The successor's `workflow_runs.compatibility` is `to_compatibility`. Replay,
query replay, history export and redrive read only these recorded values, and
no replay path looks at the current promotion. With `inherited`, the field is
recorded with equal `from`/`to` when the opt-in protocol is in use. Histories
from earlier releases have no field and mean `inherited`.

Waterline shows the transition on both runs: "continued onto build-b
(promoted) from build-a".

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
| 1 | Chain on A, promoted B, live B workers | `continueAsNew()->onPromotedBuild()` | Successor `compatibility: B`; both events record `{from: A, to: B, resolution: promoted}`; the successor's first task is claimed only by B workers |
| 2 | Same | Plain `continueAsNew()` | Successor on A, `resolution: inherited` (or no field without the opt-in protocol) |
| 3 | Chain on A, no promoted build | `onPromotedBuild()` | Refused with `promoted_build_unavailable`; predecessor open with unchanged history and lease; no successor row |
| 4 | Embedded, promoted B configured, no live B heartbeat | `onPromotedBuild()` | Same refusal as #3 |
| 5 | Run 1 continued from A to B (recorded) | Cold replay of run 1 and run 2 after promotion moves to C | Run 1 replays to `ContinueAsNewCall`; run 2 replays on its recorded B; nothing reads C |
| 6 | Promotion A→B commits while the continue-as-new transaction runs | — | The successor is on exactly A or B, recorded; no successor on an unrecorded build |
| 7 | Chain on A with an open request-cancel child | `onPromotedBuild()` | Child handed over ([workflow issue 868](https://github.com/durable-workflow/workflow/issues/868)) on its own build; successor on B |
| 8 | History from 2.27 or earlier | Replay | No `build_transition` field, treated as `inherited` |

## Open questions

- **Server:** should native also refuse when the promoted cohort has no live
  worker, as embedded does, or is a promoted cohort assumed served?
- **Waterline:** wording and placement for the transition on run detail.
