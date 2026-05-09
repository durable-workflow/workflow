# Task Queue Priority and Fairness

Task queues are shared infrastructure. When workers are saturated and contention
builds, two things must remain true:

1. **Urgent work runs first.** A workflow tagged "urgent" cannot be stuck behind
   a backlog of best-effort work just because the best-effort work arrived first.
2. **No tenant or workload class starves another.** If one tenant bursts in a
   thousand workflows, a quieter tenant must still see throughput on the same
   queue.

The package implements both with two declarative dispatch fields on every
workflow and activity task: `priority` and `fairness_key`.

## Wire surface

The fields are part of the workflow/activity start contract so every SDK can
express them without a server-version handshake.

* `priority` — integer in the range 0..9, lower numbers run first.
  Default is `5`. Priority `0` is reserved for high-urgency control-plane work;
  user code typically uses `1..9`.
* `fairness_key` — short URL-safe string identifying the workload class
  (commonly a tenant id, team name, or workflow type). Tasks without a
  fairness key share a single implicit class.
* `fairness_weight` — relative scheduling weight for a class, integer in the
  range 1..1000 (default `1`). A class with weight 3 receives a proportionally
  larger share of dispatch attention than a class with weight 1.

The fields appear on `StartOptions` and `ActivityOptions`. Activity options
override the parent run's values when set; otherwise an activity task inherits
the run's priority and fairness key.

## Persistence

`workflow_runs` and `workflow_tasks` each carry the three columns. A composite
index on `(queue, status, priority, available_at)` makes priority-ordered
dispatch a single index seek per poll. A separate index on
`(queue, status, fairness_key)` supports the operator observability surface.

## Dispatch ordering

The bridge's `poll()` query orders ready tasks by
`(priority asc, available_at asc, id)`. The two production poll endpoints
(`workflow-tasks/poll` and `activity-tasks/poll`) then run the candidate
batch through `TaskFairnessScheduler->reorder()` and call `recordDispatch`
on the shared `TaskFairnessState` for each chosen entry. The reorder is
strictly inside a priority tier — urgency always wins; fairness only
redistributes among same-priority candidates.

Workflow-task and activity-task dispatches keep separate fairness buckets
on the same queue, so a noisy workflow class does not borrow against an
activity class budget.

The fairness reorder is a deficit-style algorithm:

1. Group the batch by priority tier.
2. Within each tier, group by fairness key.
3. For each output slot, pick the class whose recent-dispatch score divided by
   weight is lowest. Ties break by insertion order so the FIFO property is
   preserved within a class.
4. Record the dispatch against the chosen class so the next poll naturally
   yields to the under-served classes.

Recent-dispatch scores live in a process-local store with an exponential
half-life (default 30 seconds), so a class that stops sending work
naturally re-enters the rotation without an external scheduler tick.

## Observability

Operators verify behavior under load through one surface, registered under
the configurable webhooks route prefix (default `webhooks`):

* `GET {webhooks_route}/task-queues/{queue}/priority-fairness` returns the
  current ready-task counts grouped by priority tier and fairness class,
  plus the recent-dispatch breakdown so the operator can confirm both that
  priority is honored (urgent tiers dominate dispatch counts) and that
  fairness is applied (counts are roughly balanced subject to declared
  weights).
* The same window is broken down separately for `workflow_task` and
  `activity_task` dispatches.

## Out of scope

The dispatch-ordering algorithm is an implementation detail of the package.
The wire surface and the observability surface are the contracts consumers
depend on; the rebalancing strategy can evolve without breaking SDKs.
