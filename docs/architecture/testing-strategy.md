# Workflow V2 Testing Strategy Contract

This document is the normative testing-strategy contract for Durable
Workflow v2. It pins the supported testing API surface that workflow
authors and consumers may rely on, and the required test buckets that
implementations must cover with executable tests so the durable kernel,
matching, scheduler, history, projection, and operator surfaces stay
consistent across the package, the standalone server, the SDKs, the
CLI, Waterline, and managed cloud.

The clarity rule is the same one that governs the rest of v2: every
guarantee that ships under a stable name must be inspectable. A test
strategy that only lives in commit messages, screenshots, or prose is
not enough. Each named bucket below MUST have at least one executable
suite — package-level fixtures, contract suites, golden-history replay
proofs, server feature/unit suites, Waterline V2 suites, or cross-SDK
conformance fixtures — and that suite is the durable home for the
guarantee.

## Scope

This contract covers:

- the v2 PHP authoring testing API exposed by the package (`WorkflowStub`,
  `Workflow\V2\Testing`, time travel, ready-task drainage, unhandled
  exception policy);
- the required test-bucket matrix every workflow v2 implementation must
  cover with executable tests;
- the cross-repo conformance contract that proves the package, server,
  SDKs, CLI, Waterline, and managed cloud honor the same buckets;
- the documentation pins that keep this contract aligned with the
  feature-mapping matrix in [`docs/workflow/plan.md`](../workflow/plan.md)
  and the multi-node hardening roadmap in
  [`docs/architecture/multi-node-hardening-roadmap.md`](multi-node-hardening-roadmap.md).

Implementation-language-specific test ergonomics (PHPUnit lifecycle,
Pest groups, Vitest harness names, Python pytest fixtures) are not
frozen here. The buckets, surface contracts, and guarantees are.

## Status

The first release v2.0 testing strategy is complete when every
required bucket below has at least one executable suite that is wired
into CI or release validation, when the testing API surface stays
documented and observable, and when the documentation-pin test below
keeps the bucket inventory and surface contract aligned with the rest
of the v2 contracts.

## Testing API Surface

The following testing helpers are documented package behavior.
Implementations MUST keep them available, observable, and replay-safe
under v2.0; cross-SDK ports MAY rename ergonomics but MUST preserve the
guarantees.

- **`WorkflowStub::fake()`**. Switches the package into in-process test
  mode without touching the durable backend. Activity dispatch,
  signals, and updates are recorded for later assertions. Child
  workflows execute as real nested v2 runs under fake mode rather than
  being silently stubbed.
- **`WorkflowStub::mock($activity, $result)`**. Replaces an activity's
  side-effecting body with an injected result or callable. Mocking a
  workflow class is rejected with a typed error — child workflows are
  exercised through their observable output, not by replacing them.
  Callable mocks receive an `ActivityFakeContext` that exposes the
  same shape the runtime activity context exposes (heartbeat, attempt
  metadata, cancellation observation) so authoring code under test
  does not branch on the test mode.
- **`WorkflowStub::assertDispatched(...)`,
  `assertDispatchedTimes(...)`, `assertNotDispatched(...)`,
  `assertNothingDispatched()`**. Pin which activities ran during a
  faked execution and how many times. These are the supported
  observation primitives; relying on raw dispatch lists or framework
  internals is not part of the contract.
- **Framework time travel**. Tests advance virtual time to fire
  timers, retry windows, schedules, heartbeats, and lease expiry
  deterministically. Real wall-clock waits MUST NOT be required by the
  testing API.
- **`WorkflowStub::runReadyTasks($limit = 100)`**. Drains the queue of
  ready tasks deterministically with a positive bound. Calling it
  outside fake mode raises a `LogicException` so accidental
  production-side use is not silently accepted.
- **Delayed-callback hooks**. Authoring code under test may register
  callbacks that fire at virtual-time boundaries to inject mid-workflow
  signals, updates, queries, or cancellations. The hook surface is the
  supported way to script multi-event scenarios; tests MUST NOT poke
  durable rows directly to simulate those events.
- **Unhandled-exception policy**. In test mode unhandled workflow
  exceptions fail the test fast by default. Suppressing this policy is
  opt-in and named, so a missing `catch` does not become a green test
  by accident.

The current `ActivityFakeContext` lives at
`Workflow\V2\Testing\ActivityFakeContext` and is constructed by both the
local-activity executor and the activity-task runner so the same
context shape is observable in fake mode and in production.

## Bucket Status Conventions

Every required bucket below has a status:

- **proven** — a documented executable suite is wired into CI or
  release validation today.
- **partial** — at least one suite exists but the bucket is not yet
  fully covered (for example, a contract surface exists but a related
  policy outcome is not yet asserted end-to-end).
- **planned** — the bucket is part of the v2.0 strategy and MUST be
  added before the bucket can be promoted to **proven**. A planned
  bucket MUST still have a named home (the suite or harness that will
  own it) so the gap is inspectable.

Statuses change as suites land. The documentation-pin test below
asserts the inventory of bucket names; bucket text is the authoritative
description, but the names below are the wire-format the cross-repo
conformance layer reads.

## Required Test Buckets

Every implementation that claims to support workflow v2 MUST cover the
following buckets with executable tests. The buckets are grouped by
the surface they exercise; ordering inside each group is alphabetical
for stable diffs and is not a priority signal.

### Replay and command correctness

- **Deterministic replay** — replaying frozen history events through
  the v2 executor reproduces the same command stream and terminal
  outcome bit-for-bit. Suite home: golden-history replay tests plus
  the cross-SDK replay diff harness. Status: proven.
- **Command idempotency** — accepted/rejected command outcomes are
  recorded once per durable command id; retried external attempts
  MUST NOT double-apply. Suite home: command bridge and replay
  contract suites. Status: proven.
- **Typed command-result compatibility** — command outcomes named in
  the feature mapping matrix stay shape-stable across versions. Suite
  home: typed-result contract suites and surface-stability assertions.
  Status: proven.
- **Workflow-task-failure taxonomy** — workflow-task failures are
  classified into the documented categories (deterministic terminal,
  retryable transient, structural limit, codec failure) and routed to
  the right durable home. Suite home: failure-category and
  workflow-task contract suites. Status: proven.
- **Side-effect purity** — `sideEffect()` results survive replay
  unchanged and never schedule activities, timers, children, signals,
  updates, memos, or search-attribute mutations. Suite home: side-effect
  workflow tests plus replay contract suites. Status: proven.
- **Structural-limit failures** — oversize serialized payloads for
  continue-as-new input, workflow output, activity output, signal
  input, and update input are rejected before completion or
  acceptance history is recorded. Suite home: structural-limits feature
  tests. Status: proven.
- **Workflow-mode guardrails** — non-replay-safe primitives (random,
  clock, environment, network) used inside workflow code fail fast in
  test mode. Suite home: workflow-mode guard suites. Status: proven.

### Identity, lifecycle, and routing

- **Duplicate-start policy** — repeated starts against the same
  workflow id resolve under the configured duplicate-start policy
  with typed accepted/rejected outcomes. Suite home: duplicate-start
  feature tests. Status: proven.
- **Instance-vs-run targeting** — external commands default to
  instance-targeted active-run resolution; explicit run-targeted
  commands are honored. Suite home: command bridge and webhook tests.
  Status: proven.
- **Cancellation-scope propagation** — cancel/terminate flow through
  parent runs, child runs, activities, timers, and waits with the
  documented cooperative-vs-immediate split. Suite home: cancellation
  scope and parent-close policy suites. Status: proven.
- **Compatibility-set routing** — runs and tasks stay on compatible
  workers across retries, child calls, and continue-as-new. Suite
  home: worker-compatibility feature tests. Status: proven.
- **Routing precedence** — connection, queue, compatibility, and
  namespace inheritance follow the precedence rules named by the
  routing-precedence contract. Suite home: routing-precedence suites.
  Status: proven.
- **Webhook routing** — operator commands routed via webhook surfaces
  resolve to the typed engine command taxonomy. Suite home: webhook
  feature and control-plane tests. Status: proven.
- **Type-key collision detection** — duplicate workflow, activity, or
  child type keys fail registration with a typed error. Suite home:
  type-registry contract suite. Status: proven.

### Time, timers, schedules, and updates

- **Timer ordering and fan-out** — multiple concurrent timers fire in
  scheduled order with stable timer ids; fan-out and barrier
  reductions stay deterministic. Suite home: timer-workflow feature
  tests. Status: proven.
- **Timeout taxonomy** — start-to-close, schedule-to-start,
  schedule-to-close, heartbeat, run-deadline, and execution-deadline
  timeouts each surface as the right typed terminal event. Suite
  home: activity-timeout and workflow-timeout suites. Status: proven.
- **Schedule lifecycle** — create, pause, resume, update, trigger,
  delete, and skipped-trigger paths emit the documented schedule
  history events and durable start commands. Suite home: schedule
  feature tests. Status: proven.
- **Schedule degraded-mode** — schedule fires continue under
  scheduler-degraded conditions without producing duplicate or
  silently-skipped runs. Suite home: scheduler-degraded-mode tests.
  Status: proven.
- **Update lifecycle** — accepted/rejected/applied/completed update
  outcomes are observable through both wait-for-accepted and
  wait-for-completed modes. Suite home: update-workflow feature tests.
  Status: proven.
- **Signal-with-start ordering** — signal-with-start delivers the
  signal after the workflow has accepted its first task and before
  any external signal racing the start. Suite home: signal feature
  tests. Status: proven.
- **Query behavior** — queries replay from committed history and
  never schedule activities, timers, children, signals, updates,
  memos, or search-attribute mutations. Suite home: query-workflow
  feature tests. Status: proven.

### Children, sagas, and parallel coordination

- **Child failure / parent close / continue-as-new** — child failure,
  parent-close-policy enforcement, and continue-as-new lineage record
  the documented typed events on both sides of the parent/child
  boundary. Suite home: parent-close-policy and continue-as-new
  metadata feature tests. Status: proven.
- **Fan-in barriers** — `async`/`all` reductions wait for the full
  parallel group, capture deterministic group ids, and surface the
  configured failure-selection policy. Suite home: parallel-failure
  selector tests. Status: proven.
- **Saga compensation** — registered compensations execute in reverse
  order through normal v2 steps without a hidden saga log. Suite
  home: saga-workflow feature tests. Status: proven.

### Visibility, metadata, and projections

- **Visibility indexing, filters, and saved views** — search-attribute
  filters, run-list pagination, and saved-view payloads project from
  the documented run-summary fields. Suite home: visibility-filters
  and run-list-item-view contract suites. Status: partial — saved-view
  payload migration coverage planned alongside the visibility-metadata
  contract suite expansion.
- **Search-attribute and memo behavior** — search attributes are
  indexed and filterable, memos are returned-only, and both fail
  closed on size, count, and type limits. Suite home: search-attribute
  and memo contract suites. Status: proven.
- **Lifecycle event compatibility** — lifecycle events emitted by the
  package match the typed history-event wire format, including legacy
  event compatibility for v1-bridge consumers. Suite home: lifecycle
  event and legacy event compatibility tests. Status: proven.
- **Waterline projection and adapter correctness** — Waterline V2
  projection adapters render run summaries, timelines, lineage,
  failures, commands, waits, timers, and metadata from the typed
  durable rows. Suite home: Waterline V2 dedicated test suites.
  Status: proven.
- **History budgets** — soft and hard thresholds for event count,
  payload size, and parallel fan-out drive the documented `pressure`
  indicator and `continue_as_new_recommended` field. Suite home:
  history-budget contract suite. Status: proven.

### Worker protocol, transport, and operator surfaces

- **HTTP/JSON worker protocol** — worker poll, claim, complete, fail,
  heartbeat, and cancel calls match the frozen request/response
  shapes. Suite home: worker-protocol-version and matching
  conformance suites. Status: proven.
- **Heartbeats and lease expiry** — activity heartbeats renew the
  attempt lease; missed heartbeats time out the attempt with the
  typed terminal event. Suite home: heartbeat-progress and activity
  timeout suites. Status: proven.
- **Transport repair** — repair, redelivery, and durable-next-resume
  paths recover stalled tasks without producing double-apply or
  silently-stalled runs. Suite home: long-poll coordination and
  redelivery contract suites. Status: partial — additional
  cross-process repair drills planned in coordination with the
  operational-liveness contract.
- **Liveness bootstrap** — fresh nodes acquire ownership through the
  documented bootstrap sequence rather than relying on legacy timers
  or shared cache. Suite home: liveness bootstrap contract suite.
  Status: planned — owns the bootstrap drill alongside the operational
  liveness contract.
- **Message-stream cursors** — durable cursors survive continue-as-new
  and replay without in-memory counters. Suite home: message-stream
  cursor and continue-as-new tests. Status: proven.
- **Transaction and after-commit boundaries** — durable rows commit
  before observable side effects fire; after-commit hooks do not
  resurrect rolled-back state. Suite home: command-bridge and
  projection contract suites. Status: proven.
- **Operator command auditability** — operator-issued start, signal,
  update, repair, cancel, terminate, and archive commands write
  durable audit rows naming the principal and outcome. Suite home:
  operator queue visibility and command audit suites. Status: proven.
- **Auth/audit boundaries** — namespace, IAM, and certificate-filter
  rejections fail closed before revealing whether a hidden namespace,
  service, queue, or endpoint exists. Suite home: cross-namespace
  service-policy and security-governance contract suites. Status:
  proven.

### Service catalog and cross-namespace calls

- **Namespace service catalog** — the durable catalog rows, boundary
  policy snapshots, and audit recorder behave as the policy authority
  for cross-namespace traffic. Suite home: service-catalog and
  default-service-boundary-policy suites. Status: proven.
- **Cross-namespace service calls** — every invocation has a durable
  service-call id with the documented lifecycle and outcome taxonomy.
  Suite home: service-execution-contract and default-service-control-plane
  tests. Status: proven.

### Backups, imports, and migration

- **Backend capability validation** — doctor/readiness checks block
  unsupported database, queue, cache, serializer, and migration
  combinations. Suite home: backend-capabilities and health-check
  suites. Status: proven.
- **Backup/restore/projection-rebuild exercises** — projection
  rebuilds reconstruct waits, timers, timeline, lineage, failures,
  commands, and metadata from frozen history; backup and restore
  preserve durable identity. Suite home: history-export, replay-diff,
  bundle-integrity, and embedded v2 history-import tests. Status:
  proven.
- **Embedded v2 history import** — imported runs preserve durable
  identity, history events, and projection state. Suite home: embedded
  v2 history import contract suite. Status: proven.
- **Cross-service compatibility** — package, server, CLI, Waterline,
  SDK, and managed cloud honor the same wire formats and outcome
  taxonomies. Suite home: platform-conformance-suite and
  sdk-neutrality contract tests. Status: proven.
- **Model-payload codec** — payload envelopes round-trip through the
  configured codec, external payload references, and bundle integrity
  verification. Suite home: payload-envelope-resolver and
  polyglot-codec-roundtrip suites. Status: proven.
- **Launch/wait helpers** — wait-for-completed, wait-for-update, and
  query-replay helpers in the package and SDKs return the documented
  results without opening hidden spinners. Suite home: workflow-stub
  and update wait helper feature tests. Status: proven.
- **Scoped execution contexts** — workflow, activity, child, signal,
  update, query, and side-effect contexts honor their replay-safety
  scope and cannot leak across boundaries. Suite home: scoped
  execution-context contract suites. Status: proven.

## Suite Layering

Every required bucket above is covered by at least one of the
following layers; the table is the inventory of where to look.

| Layer | Owner | Purpose |
| --- | --- | --- |
| Workflow package fixtures and feature tests | `durable-workflow/workflow` `tests/` | Author-facing runtime, replay, codec, command, and projection behavior. |
| Workflow package unit and contract suites | `durable-workflow/workflow` `tests/Unit/V2/` | Documentation pins, surface stability, and durable-row contracts. |
| Server feature and unit suites | `durable-workflow/server` server tests | HTTP API, worker protocol, control-plane, and operator audit behavior over the wire. |
| Waterline V2 dedicated suites | `durable-workflow/waterline` v2 tests | Projection-adapter correctness for run summaries, timelines, lineage, failures, commands, waits, timers, and metadata. |
| Platform conformance fixtures | `docs/architecture/platform-conformance-suite.md` | Cross-repo proof that package, server, CLI, Waterline, SDKs, and managed cloud honor the same buckets. |
| Replay-debug bundles | `WorkflowReplayer`, `BundleIntegrityVerifier`, replay-diff tooling | Frozen replay proofs that may be carried into other SDKs without rewriting suites. |

When a bucket gains a new suite, the suite's repo MUST add or update
the layer entry above so the inventory stays inspectable from a single
contract.

## Test-Mode Defaults

These defaults are load-bearing testing-strategy choices for v2.0:

- Activity dispatch, signal sending, and update sending are recorded
  in fake mode so authoring tests can assert what the workflow asked
  for without driving a real worker.
- Child workflows under fake mode execute as real nested v2 runs;
  child stubs would be additive and are explicitly out of scope for
  the first release.
- Time travel advances virtual time deterministically; real
  wall-clock waits MUST NOT be required to assert workflow behavior.
- `WorkflowStub::runReadyTasks()` is the one supported drainage
  primitive and refuses to run outside fake mode.
- Unhandled workflow exceptions fail the test fast in test mode by
  default; suppressing the policy is opt-in and named.
- Mocking a workflow class is rejected; child workflows are observed
  through their output rather than replaced.

## Documentation Pins

`tests/Unit/V2/TestingStrategyDocumentationTest.php` pins this
contract. The pin asserts:

- the document exists at the canonical path and declares the required
  top-level headings;
- the testing API surface section names every supported helper above,
  including `WorkflowStub::fake()`, `WorkflowStub::mock()`,
  `assertDispatched`/`assertDispatchedTimes`/`assertNotDispatched`/
  `assertNothingDispatched`, `runReadyTasks()`, `ActivityFakeContext`,
  framework time travel, delayed-callback hooks, and the unhandled
  exception policy;
- every required test bucket name above is present in the document;
- the v2.0 test-mode defaults are stated;
- the suite-layering table names the workflow package fixtures and
  contract suites, the server suites, the Waterline V2 suites, the
  platform conformance fixtures, and the replay-debug bundles;
- the document keeps a `## Relationship To Other Contracts` section
  that points at the feature mapping matrix and the multi-node
  hardening roadmap.

## Relationship To Other Contracts

- [`docs/workflow/plan.md`](../workflow/plan.md) is the v1 → v2
  feature mapping matrix. Every required test bucket above corresponds
  to a feature row, a new-in-v2 capability, or a v2.0 default in that
  matrix.
- [`docs/architecture/multi-node-hardening-roadmap.md`](multi-node-hardening-roadmap.md)
  names this contract as adjacent to Phase 1 (`docs/architecture/execution-guarantees.md`).
  The roadmap is the authority for ordering; this contract is the
  authority for which buckets must be proven by tests.
- [`docs/architecture/execution-guarantees.md`](execution-guarantees.md)
  defines deterministic replay, idempotency, lease expiry, and
  redelivery semantics that the replay and command-correctness
  buckets above prove.
- [`docs/architecture/operational-liveness.md`](operational-liveness.md)
  defines repair, redelivery, durable-next-resume, and bootstrap
  behavior that the transport-repair and liveness-bootstrap buckets
  above prove.
- [`docs/architecture/platform-conformance-suite.md`](platform-conformance-suite.md)
  defines the cross-repo fixture catalog and harness contract that
  the cross-service compatibility bucket above proves.
- [`docs/architecture/sdk-neutrality.md`](sdk-neutrality.md) defines
  language-neutral surface guarantees that cross-SDK ports of the
  testing API surface MUST preserve.
- [`docs/api-stability.md`](../api-stability.md) is the source of
  truth for public API and history-event wire-format rules consumed
  by the typed-command-result-compatibility bucket above.

## Changing This Contract

Adding, removing, or renaming a required test bucket, changing the
testing API surface, or relocating the suite layers requires updating
this document and `tests/Unit/V2/TestingStrategyDocumentationTest.php`
in the same change. Promoting a bucket from `planned` or `partial` to
`proven` requires updating the bucket's status text in the same change
that lands the suite. Demoting a bucket without removing the suite is
not allowed; if a suite is retired, the bucket text MUST name the
replacement home in the same change.
