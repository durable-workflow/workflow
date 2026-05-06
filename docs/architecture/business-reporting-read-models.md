# Workflow V2 Business Reporting and App Read-Model Contract

## Scope

This contract defines how v2 applications build business reporting and
app-facing read models without treating workflow runtime state as
business state. It is the reference used by workflow authors, embedded
hosts, product docs, Waterline diagnostics, CLI reasoning, and server
deployment guidance when explaining where reporting data should live.

It builds on:

- `docs/architecture/execution-guarantees.md` for deterministic workflow
  code and durable idempotency surfaces.
- `docs/api-stability.md` for stable workflow and run identity APIs.
- `docs/search-attributes-architecture.md` and
  `docs/workflow-memos-architecture.md` for operator visibility metadata.
- `docs/architecture/control-plane-split.md` and
  `docs/architecture/rollout-safety.md` for operator-facing runtime
  visibility.

## Terminology

- **Technical workflow state** - engine-owned state that tracks workflow
  instances, runs, tasks, activities, timers, waits, commands, history,
  repair, compatibility, and projection health.
- **Business state** - application-owned state that names domain facts:
  order accepted, payment authorized, invoice issued, shipment booked,
  claim approved, case escalated, and similar product milestones.
- **App read model** - an application table, document, cache, stream, or
  search index designed for product views, customer support views,
  analytics, or business dashboards.
- **Milestone** - a domain event or durable boundary where the
  application has enough information to update an app read model
  meaningfully.
- **Projection reference** - the stable identifiers an app stores beside
  a business record so it can link a product view back to runtime
  diagnostics: `workflow_id` and `run_id`.
- **Waterline** - a technical runtime UI for operators. It reads
  workflow runtime projections so teams can operate the fleet; it is not
  the source of truth for product or business reporting.

## Authority Boundary

Technical workflow state is not business state.

The workflow engine owns correctness for execution: command admission,
history append, task dispatch, activity attempts, durable waits,
continue-as-new, repair, archive, retention, compatibility, and
operator-visible health. Waterline, CLI, cloud, and server operator
surfaces consume this state to answer runtime questions such as:

- which runs are blocked, waiting, repairing, or failed;
- whether queues, workers, leases, schedules, and projections are
  healthy;
- whether a command was accepted, rejected, delayed, or repaired;
- which run generated the technical history needed for incident review.

The application owns correctness for business reporting. Product
dashboards, customer-facing status pages, finance reports, support
queues, analytics facts, and SLA rollups should read app read models,
not `workflow_runs`, `workflow_run_summaries`, Waterline JSON, or
workflow history exports. Runtime projections may carry correlation
metadata such as `business_key`, labels, search attributes, and memos,
but those values exist so operators can find and inspect technical work.
They do not become the authoritative product data model.

## Stable Projection References

Applications should store both identifiers when a workflow contributes
to a business record:

| reference | source API | lifetime | use |
| --- | --- | --- | --- |
| `workflow_id` | `Workflow::workflowId()`, `WorkflowStub::workflowId()`, `CommandResult::workflowId()` | stable logical workflow id across continue-as-new | business aggregate correlation |
| `run_id` | `Workflow::runId()`, `WorkflowStub::runId()`, `CommandResult::runId()` | one execution generation | audit, support, and runtime incident correlation |

`workflow_id` is the app projection's durable logical reference. It
survives continue-as-new and should normally be the foreign key from a
business record to a workflow-backed process.

`run_id` is a generation reference. It lets an app read model name the
specific execution generation that observed or produced a milestone. If
a workflow continues as new, the same app record keeps the same
`workflow_id` and records the newer `run_id` on the next milestone.

`instanceId()` remains a compatibility name for the same logical
identifier. New app projection code should prefer `workflowId()` so the
application vocabulary matches workflow authoring code.

## Milestone-Based Read-Model Pattern

Applications should update business read models at meaningful workflow
milestones, not by polling workflow runtime tables.

Recommended pattern:

1. Name business milestones in domain language, not engine language:
   `order_accepted`, `payment_authorized`, `shipment_booked`,
   `claim_reviewed`, `case_escalated`.
2. Cross each milestone through a deterministic workflow decision and a
   side-effect boundary that is legal under
   `docs/architecture/execution-guarantees.md`. Workflow code must not
   write external state directly; use an activity, command handler, or
   application event consumer to perform the read-model write.
3. Upsert app read-model rows idempotently. Choose a key such as
   `(workflow_id, milestone)` for latest-state projections or
   `(workflow_id, run_id, milestone, sequence)` for append-only audit
   facts.
4. Store the business fields the product needs directly in the app read
   model. Do not make the product dashboard reconstruct domain state
   from workflow history payloads, run summaries, Waterline endpoints, or
   operator metrics.
5. Store `workflow_id`, `run_id`, and any app `business_key` beside the
   read-model row so support and operators can pivot from a business
   screen into Waterline or history export during an incident.

Example app table:

```sql
CREATE TABLE order_read_models (
    order_id VARCHAR(64) PRIMARY KEY,
    workflow_id VARCHAR(191) NOT NULL,
    run_id VARCHAR(26) NOT NULL,
    status VARCHAR(64) NOT NULL,
    payment_status VARCHAR(64) NOT NULL,
    last_milestone VARCHAR(64) NOT NULL,
    last_milestone_at TIMESTAMP(6) NOT NULL,
    updated_at TIMESTAMP(6) NOT NULL
);
```

Example activity boundary:

```php
final class ProjectOrderMilestone
{
    public function handle(
        string $orderId,
        string $workflowId,
        string $runId,
        string $milestone,
        array $fields,
    ): void {
        OrderReadModel::query()->updateOrCreate(
            ['order_id' => $orderId],
            [
                'workflow_id' => $workflowId,
                'run_id' => $runId,
                'last_milestone' => $milestone,
                ...$fields,
            ],
        );
    }
}
```

The workflow passes `workflowId()` and `runId()` into that activity when
the domain milestone is reached. The activity owns the external write and
must be idempotent under the activity execution rules.

## Search Attributes, Memos, and Business Keys

Search attributes, memos, labels, and `business_key` are runtime
visibility metadata. They help operators find a run, filter a queue, or
diagnose a failure. They are intentionally bounded, typed, and shaped for
technical fleet visibility.

Use them for:

- finding the workflow related to an app record;
- grouping technical work by tenant, region, product area, priority, or
  support case;
- showing enough runtime context that an operator does not have to query
  the app database during an incident.

Do not use them for:

- product dashboards;
- financial reports;
- customer-facing status pages;
- analytics facts;
- replacing app tables that model domain state.

If a field is needed by a business dashboard, write it to the app read
model at the milestone where the application knows that fact. Duplicating
a correlation key into search attributes is acceptable; making Waterline
or runtime projection data the business reporting source is not.

## Waterline and Runtime Operations

Waterline is a technical runtime UI. Its job is to keep fleet
visibility strong enough that teams do not misuse app reporting as their
operations console.

Runtime operations should continue to rely on workflow operator
surfaces:

- `OperatorMetrics::snapshot()` for health, backlog, projection lag,
  repair, stale work, worker compatibility, and queue risk indicators.
- `OperatorQueueVisibility::forNamespace()` and `::forQueue()` for
  ready, leased, failed-claim, dispatch, and per-partition queue state.
- `RunDetailView`, `HistoryTimeline`, `HistoryExport`, `RunTaskView`,
  `RunWaitView`, and `RunLineageView` for per-run diagnosis.
- Waterline dashboards, saved views, filters, and run detail screens for
  operator workflows over the same runtime contracts.

Business reporting should not be asked to answer runtime questions such
as "which lease expired?", "which task is dispatch-overdue?", "which run
needs repair?", or "which worker compatibility marker blocked this
queue?". Those questions belong to Waterline and the operator APIs.

## Test Strategy Alignment

- API identity coverage pins `workflowId()` and `runId()` on command
  results and workflow handles.
- Documentation tests pin this contract so future plan updates keep the
  Waterline/runtime boundary and app read-model pattern explicit.
- Existing execution-guarantees tests continue to enforce that workflow
  code does not perform external writes directly.
- Operator visibility tests continue to cover runtime metrics, queue
  visibility, run detail, history export, and projection health so app
  teams do not need to repurpose business read models for operations.

## Changing This Contract

Changes that rename `workflow_id`, `run_id`, `workflowId()`, or
`runId()` for app projection references are API breaks and must follow
`docs/api-stability.md`.

Changes that make Waterline, workflow run summaries, history exports, or
operator metrics authoritative for business dashboards must update this
contract, the visibility metadata docs, API stability docs, product
docs, and tests in the same change so the authority transfer is reviewed
deliberately.
