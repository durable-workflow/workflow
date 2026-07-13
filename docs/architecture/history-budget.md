# History Budget

Workflow runs accumulate history events across activities, timers, signals,
updates, side effects, child workflows, and message cursor advances. Without
explicit budgets, long-running runs can grow until replay cost or persistence
limits cause failures that are hard to diagnose and impossible to retroactively
fix on a single run. The history budget contract gives operators and workflow
code an inspectable signal long before that boundary is reached.

## Dimensions

The budget is computed across three dimensions, each with a soft (warning)
threshold and a hard (continue-as-new) threshold:

| Dimension | Counter | Default soft | Default hard | Config root |
| --- | --- | --- | --- | --- |
| Event count | `history_event_count` — number of `workflow_history_events` rows | 8 000 | 10 000 | `workflows.v2.history_budget.event_warning_threshold` / `.continue_as_new_event_threshold` |
| Payload size | `history_size_bytes` — serialized event-type + JSON payload size | 4 MiB | 5 MiB | `.size_bytes_warning_threshold` / `.continue_as_new_size_bytes_threshold` |
| Fan-out | `history_fan_out` — largest `parallel_group_size` recorded in any parallel group | 160 | 200 | `.fan_out_warning_threshold` / `.continue_as_new_fan_out_threshold` |

Setting any threshold to `0` disables that dimension; warning thresholds clamp
to the corresponding hard threshold so a misconfigured warning cannot fire
after continue-as-new is already recommended.

## Pressure indicator

Each run has a derived `history_budget_pressure` value with three states:

- `ok` — every dimension is below its soft threshold.
- `approaching` — at least one dimension is at or above its soft threshold,
  but no dimension has crossed its hard threshold.
- `continue_as_new_recommended` — at least one dimension is at or above its
  hard threshold. `continue_as_new_recommended=true` also provides the direct
  boolean recommendation used by workers and workflow code.

The pressure value is computed from the same counters that drive
`continue_as_new_recommended`, so operators see the same authoritative signal
across waterline, the run detail view, and operator metrics.

## Surfaces

- `Workflow::historyLength()`, `historySize()`, `historyFanOut()`,
  `historyBudgetPressure()`, and `shouldContinueAsNew()` are advisory
  authoring signals exposed on the v2 workflow base class.
- `WorkflowRunSummary` persists `history_event_count`, `history_size_bytes`,
  `history_fan_out`, `continue_as_new_recommended`, and
  `history_budget_pressure`. `RunListItemView` and `RunDetailView` project
  these directly.
- `RunDetailView` additionally returns the active soft and hard thresholds
  (`history_event_threshold`, `history_event_warning_threshold`,
  `history_size_bytes_threshold`, `history_size_bytes_warning_threshold`,
  `history_fan_out_threshold`, `history_fan_out_warning_threshold`) and the
  list of dimensions that triggered the current pressure
  (`history_budget_pressure_dimensions`) so operators can explain *why* a run
  is approaching the boundary.
- Full and paginated `WorkflowTaskBridge` history responses carry the complete
  canonical budget as `total_history_events`, `history_size_bytes`,
  `history_fan_out`, `continue_as_new_recommended`,
  `history_budget_pressure`, and `history_budget_pressure_dimensions`.
  `WorkerHistoryPayloadContract` publishes the required current response
  schema under `WorkerProtocolVersion::describe().workflow_history_budget`,
  and the paginated bridge resolves it through the bounded canonical budget
  path without hydrating the run's history collection.
  Custom bridge implementations must return every required field in that
  schema for both response kinds; omitted fields are not a supported response
  variant.
- `OperatorMetrics::history` reports
  `continue_as_new_recommended_runs`, `approaching_budget_runs`,
  `max_event_count`, `max_size_bytes`, `max_fan_out`, and the configured
  thresholds for each dimension.

## Replay-safety

Counters come straight from frozen history-event payloads. Fan-out is derived
by taking the maximum `parallel_group_size` across distinct
`parallel_group_id` values recorded in the run's history events; the value is
deterministic for a given history and re-derives correctly on any replay.
Workflow code that branches on `historyBudgetPressure()` or
`shouldContinueAsNew()` therefore reaches the same decision on the original
attempt and on every subsequent replay.

## What this contract does *not* cover

- Snapshot or history compaction is intentionally out of scope for the first
  release. The budget contract ships first so archive can land on top of an
  inspectable correctness signal before any compaction protocol is committed.
- Reset semantics (truncating history at a chosen sequence) remain a reserved
  operator command and are tracked separately in the v2 plan.
