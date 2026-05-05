# Workflow V2 Activity Execution Model Replacement Contract

This document tracks the replacement gate for the public Activity
Execution Model docs. The current public page exists to make missing
execution-model features explicit. It must not remain the long-term
home for local-activity, worker-session, or sticky-execution language
after those features have real runtime contracts.

This is a tracking contract, not a runtime contract for any of the
three primitives. It defines the evidence that must exist before the
public docs can replace negative-support language with positive feature
documentation. A runtime implementation that changes activity
placement, affinity, or replay behavior must ship its own workflow-owned
architecture contract and tests before the public docs move search and
LLM discovery to the positive pages.

## Scope

The contract covers the follow-through required when Durable Workflow v2
adds any of these execution-model primitives:

- **Local activities** — an activity-like call that executes in the
  workflow worker process without ordinary activity-task dispatch.
- **Worker sessions** — an explicit lease or affinity model that binds
  more than one activity to the same worker process or host.
- **Sticky execution** — a first-class worker-affinity or replay-cache
  behavior whose public contract goes beyond "ordinary replay is always
  the correctness fallback."

The contract also covers the docs cleanup that follows once all three
features have published contracts. It does not authorize any of those
features by itself.

## Replacement Primitives

Each primitive needs a workflow-owned runtime contract before public docs
can describe it as supported.

### Local Activities

The local-activities contract must define execution semantics,
timeouts, cancellation, heartbeating, failure detection, worker shutdown,
and the exact rule for when an invocation bypasses ordinary task
queueing. It must also explain how a local call records history, how a
workflow task remains healthy while local work is running, and which
activity options are accepted or rejected.

### Worker Sessions

The worker-sessions contract must define session creation, routing,
timeouts, cancellation, heartbeating, failure detection, concurrency
limits, and the behavior when the session-owning worker disappears. It
must also state how sessions interact with activity retries, build
compatibility, deployment drain, and task-queue admission.

### Sticky Execution

The sticky-execution contract must define whether sticky execution is
only a replay optimization or a public affinity feature. If it is public
affinity, the contract must define routing, cache invalidation,
timeouts, cancellation, heartbeating, failure detection, rollout
behavior, and the fallback from sticky miss to ordinary replay.

## Public Docs Gate

The public docs cleanup is blocked until all three primitives have
positive docs and cross-links to their workflow-owned runtime contracts.
The cleanup must cover these public docs sources in
`durable-workflow.github.io`:

- `docs/features/activity-execution-model.md`
- `docs/features/local-activities.md`
- `docs/features/worker-sessions.md`
- `docs/features/sticky-execution.md`
- `docs/defining-workflows/activities.md`
- `docs/defining-workflows/workflow-api.md`
- `docs/constraints/execution-guarantees.md`

After the replacement docs exist, the Activity Execution Model page must
be deleted or reduced to the ordinary queued-activity baseline. It must
not keep serving as a bucket for "not supported yet" disclaimers about
local activities, worker sessions, or sticky execution.

The ordinary queued-activity baseline may remain canonical if ordinary
activity dispatch still works the same way: workflow code records an
activity command, the engine creates a durable activity task, a worker
claims the task under a lease, and the outcome is recorded on history.

## Discoverability Gate

The public docs site must move feature discovery to the supported
feature pages once they exist. The replacement change must update:

- `sidebars.js` so local activities, worker sessions, and sticky
  execution are discoverable as positive feature docs when they are
  public features.
- `scripts/reference-docs-contract.json` so the reference-docs contract
  pins the supported feature pages and no longer requires the negative
  sections on `features/activity-execution-model.md`.
- `scripts/discoverability-contract.json` so tracked searches for local
  activities, worker sessions, and sticky execution target the supported
  feature docs instead of the temporary stance page.
- `scripts/check-llms-ai-surfaces.js` when the LLM surface needs
  explicit assertions for the supported feature docs.
- the generated `llms-2.0.txt` and `llms-full-2.0.txt` output through
  the normal docs build so AI tools see the positive pages.

Until the supported feature docs exist, the public docs may continue to
route these searches to the stance page, but the routing is temporary
and must carry an action that names the replacement target.

## Baseline Queued-Activity Page

If the Activity Execution Model page remains after replacement, it
should describe only the durable queued-activity baseline and link to the
positive feature pages for local activities, worker sessions, and sticky
execution. The retained page may explain how ordinary activity dispatch
coexists with the new primitives, but it must not describe the product
position as "no local activities, no worker sessions, and no
sticky-execution behavior."

## Test Strategy Alignment

`tests/Unit/V2/ActivityExecutionModelReplacementDocumentationTest.php`
pins this tracking contract. Any change that adds one of the replacement
runtime contracts should update this document and the public docs
contract in the same review so the product docs, generated LLM
manifests, and workflow-owned architecture docs stay aligned.

## Changing This Contract

Changing this contract is a docs and runtime-coordination change. A
future change may remove this tracking document only after local
activities, worker sessions, and sticky execution all have workflow-owned
runtime contracts and positive public docs, and after the public Activity
Execution Model stance page has been deleted or reduced to the canonical
queued-activity baseline.
