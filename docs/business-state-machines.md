# Business state-machine transitions

This is the first implementation slice of the composable business state-machine proposal. It provides an optional embedded V2 transition protocol with a four-method step contract, recorded assessments, versioned profiles and active-run native Saga integration. Existing workflows and `Workflow\States\StateMachine` are unchanged. Symfony Workflow is not a dependency.

**Experimental.** The `Workflow\V2\StateMachine` API is under review and may change in a minor release until the nested slices land; it carries no backward-compatibility promise yet. The complete state-bound binding runtime, retained compensation adapter, business-state database schema and application persistence integration remain unfinished. This does not complete the proposal.

## Responsibilities and flow

| Type | Responsibility and position in the flow |
| --- | --- |
| `Definition\PolicyProfile` | Pinned machine/catalogue and integration profile, including allowed edges and handler versions. Its fingerprint detects changed configuration under an old execution reference. |
| `Definition\TransitionDefinition` | Event/source eligibility, target state, registered handler type/version and whether work exists. |
| `Execution\ExecutionReference` | Original application scope, business subject, execution identity, policy fingerprint and expected source state/revision. Independent of a DW run or activity attempt. |
| `Transition\FactSnapshot` | Recorded target and permission assessments, evidence references and snapshot identity. Bound to the exact execution, transition and phase. |
| `Transition\SnapshotPhase` | Distinguishes the before-work, after-work and compensation snapshots so evidence from one phase cannot substitute for another. |
| `Rules\Decision` / `Verdict` | Satisfied, Unsatisfied or Unknown with a stable reason and immutable evidence references. |
| `Transition\TransitionContext` | Explicit input to the four methods. Carries the exact assessments, with no shared last-result cache. |
| `Transition\TransitionStep` | Entity-independent target check, permission check, work and compensation. |
| `Transition\AssessedTransitionStep` | Optional base that projects recorded decisions into the two boolean methods. The runner rejects a contradictory custom projection. |
| `Execution\TransitionRuntime` | Application boundary for recorded snapshots, live execution admission and protected business commits. |
| `Execution\ActivityTransitionRuntime` | Implements that boundary through existing V2 activities and portable arrays. Objects stay inside workflow code. |
| `TransitionRunner` | Selects a unique edge, runs the four steps and registers the original owner with native Saga before work. |
| `Execution\ExecutionReceipt` | Work acknowledgement correlated to the original execution. It does not establish readiness to commit. |
| `Persistence\CommitResult` | Explicit protected-commit acknowledgement, revision conflict or accepted cancellation blocking completion. |
| `Execution\TransitionResult` / `TransitionStatus` | Inspectable result and reason. These are processing outcomes, not the application's business state catalogue. |

```text
Event + trusted original execution + pinned profile
  -> select a unique event/source edge
     no candidate: Unavailable
     multiple candidates: Ambiguous
  -> recorded snapshot and pure target guard [1]
     Satisfied -> skip work -> protected commit [4]
     Unknown -> Unknown, no effects
     Unsatisfied
       -> no declared work: Pending
       -> pure execution guard
          refused/unknown: Blocked/Unknown
       -> recorded live admission
          refused/unknown: Blocked/Unknown
       -> register original compensation with native Saga
       -> execute prescribed work [2]
       -> new recorded snapshot and target guard [3]
          stale source/revision: Conflict
          unsatisfied/unknown: Pending/Unknown
          satisfied: protected commit [4]

Explicit application compensation decision
  -> existing Workflow::compensate(), sequential default
  -> fresh recorded compensation snapshot
  -> original step->compensate(context, original execution)
     verified: advance to the earlier Saga callback
     unresolved: durable wait or explicit failure, never successful return
```

`execute()` and `compensate()` orchestrate recorded DW activities or durable waits. Wrap an existing synchronous provider/application handler in an activity before calling it. Calling that handler directly from workflow code could repeat its effects during replay. Admission records authority and operation permission only. It must not perform the forward business effect before Saga registration.

The runner does not retry a whole transition or automatically compensate a blocked transition. Application policy selects waiting, retries, reconciliation or compensation. Existing DW activities, timers and Saga execute those decisions. Activities must be idempotent and reconcile uncertain external outcomes. Budget and circuit-breaker decisions belong to application admission and action policies.

A satisfied initial target skips work but still goes through protected commit. This does not automatically adopt ownership of pre-existing external effects. Such effects must already have an owner, or require an explicit adoption protocol in a later slice.

## Authoring

The four business methods are unchanged across machine types:

```php
interface TransitionStep
{
    public function canTransition(TransitionContext $context): bool;
    public function canExecute(TransitionContext $context): bool;
    public function execute(TransitionContext $context): ExecutionReceipt;
    public function compensate(TransitionContext $context, ExecutionReference $originalExecution): void;
}
```

Existing application contracts can be bridged by a step/runtime adapter. Keep application domain objects behind that adapter rather than serializing them into workflow history. Reuse the same adapter and runner across integration profiles.

Guards perform no I/O. Their boolean result must agree with the corresponding recorded assessment. The application supplies the shared state catalogue and enforces machine-wide invariants in its evaluator and protected commit across every profile; this slice validates structural definitions, not business-invariant semantics. Rules can be arbitrarily complex inside the application's evaluator, but the evaluator must retain its pinned rule versions, fact provenance and explainable result. This slice carries that assessment rather than introducing a rule-expression language.

Inside a V2 workflow's `handle()` method:

```php
$transition = new TransitionDefinition(
    'process', 'received', ['Received'], 'Processing', 'prepare', '1',
);
$profile = new PolicyProfile(
    'orders', '1', 'integration-a', '1',
    ['Received', 'Processing'], [$transition],
);
$execution = new ExecutionReference(
    $authorizedScope, $subjectId, $decisionId, $pinnedFingerprint,
    'Received', $expectedRevision,
);

$result = (new TransitionRunner())->run(
    $this,
    $profile,
    $execution,
    'received',
    new ActivityTransitionRuntime(
        CollectSnapshot::class, AdmitWork::class, CommitBusinessState::class,
    ),
    [$transition->handlerKey() => new PrepareStep()],
);
// Continue the owning business workflow according to $result->status.
// Keep it open while forward work or future compensation obligations remain.
```

`CollectSnapshot`, `AdmitWork`, `CommitBusinessState` and `PrepareStep` above are application implementations, not package classes. The application records the selected fingerprint before starting work and supplies it again on replay. Do not compute the expected fingerprint from the newest mutable configuration when resuming an existing execution.

Use `TransitionResult::toArray()` across workflow/history boundaries. The typed result remains an in-memory object. A transition result is not permission to terminate the owning workflow. Pending, unknown, conflict and cancellation decisions after work can still carry unresolved effects; even a committed transition can require later compensation. The application must keep that owner alive, perform durable waits or explicitly resolve its obligations before closing the run. This runner does not implement an event loop, a durable pending-transition registry or retained scope ownership.

Handlers are selected from the explicitly supplied type/version registry. Stored data cannot provide an arbitrary PHP class or callable to this registry. Retain compatible implementations for active runs. A changed profile or removed handler fails explicitly before application I/O. The fingerprint covers the definition/profile metadata, graph and handler versions. It does not hash PHP implementations or replace deployment/version compatibility discipline.

The scope string is identity, not authentication. Live application activities must authorize it against trusted credentials and the original business subject before reading protected data or performing effects.

## Activity payload boundary

`ActivityTransitionRuntime` uses normal V2 activity history. During replay, completed activities return their recorded results. It adds no new worker command or history event.

Snapshot request:

```text
execution: {scope, subject, executionId, policyFingerprint, sourceState, sourceRevision}
transition: {key, event, from, to, handler, handlerVersion, hasWork}
phase: before | after | compensation
```

Snapshot result:

```text
execution: the exact original reference
transitionKey: selected transition key
phase: requested phase
snapshotId: immutable fact/assessment identity
state: observed business state
revision: observed business-state revision
target: {verdict, reason, evidence: [immutable references]}
permission: {verdict, reason, evidence: [immutable references]}
```

Snapshots carry assessments and references, not mutable business entities. The application retains the referenced facts, rule versions and evaluated condition structure. An `after` snapshot must use a different identity and freshly collected facts. A new ID alone does not prove the underlying evidence is current.

Admission and commit requests include the execution, transition, snapshot ID, phase, expected state and expected revision. Admission returns `execution` plus `decision: {verdict, reason, evidence}`. The permission evidence can carry a protected admission/capability reference. Each external action must validate its current authority and enforce provider idempotency/fencing where supported.

Commit returns `execution`, `status` and `reason`. Supported statuses are `committed`, `conflict` and `cancellation_requested`. The last status means that accepted cancellation prevented this commit. It does not mean that compensation finished or that the business entity is Cancelled. Unknown commit acknowledgement must be reconciled by the adapter using the same decision identity rather than returned as success.

Malformed or substituted results fail explicitly. These typed adapters do not make an untrusted application activity authoritative.

## Persistence and concurrency contract

An application commit adapter must:

1. Bind a decision ID to its original scope, subject, policy, transition and canonical content. An exact duplicate returns the recorded result. Reusing the ID for different content refuses.
2. Re-establish live authority and compare the expected source state/revision and accepted cancellation under the authoritative transaction/fence.
3. Atomically record business state, decision outcome and publication intent if they share one store.
4. After a lost acknowledgement, query/retry that exact decision rather than repeat effects.
5. Treat a stale revision, authority loss or accepted cancellation as an explicit outcome.

An immutable snapshot is not a concurrency fence. The runner checks snapshot correlation and source consistency, but the application activity must implement the transaction, operation admission and provider reconciliation. A database fence cannot recall a request already sent to a provider.

For separate application and DW stores, the application's decision record is the business commit authority. Use an explicit durable handoff and idempotent outcome lookup. No distributed transaction is implied. Shared-store and cross-store persistence race guarantees are not demonstrated by this slice's in-memory runner tests.

## Compensation and nested-machine boundary

Registration happens before `execute()` can suspend or fail. The callback captures the original execution and step, not the eventual receipt. Compensation requests a fresh recorded snapshot and receives the original execution separately. Return from `compensate(): void` only after required effects or their absence are authoritatively verified. Unknown, pending and refused results require waiting or explicit failure. Technical retry exhaustion does not establish business cancellation.

This uses the existing active-run Saga. Use sequential compensation and keep error propagation enabled when earlier callbacks depend on later ones. The package does not change the workflow's existing Saga configuration, retry behavior or cancellation defaults.

The durable Saga compensation-scope work owns registration durability, recovery ownership and retained obligations after originating runs close. A run-local callback is not such a registry. This slice must not be used to claim closed-child/closed-parent compensation recovery or retained evidence after pruning.

The next nested slice must preserve the same four methods and add:

- A binding of authorized scope, parent instance and original owner, named slot, immutable generation, host state, child identity, pinned policies and required business outcome.
- Separate start/adopt and observe/await operations. Starting must confirm the durable child identity before the parent commits the hosting state. Observation cannot create a second compensation owner.
- Original-generation routing after state changes/re-entry. Old requests never redirect to the newest child.
- Verified child compensation before earlier parent compensation. Technical child completion, accepted signals and timeouts are insufficient evidence.
- Acyclic bounded binding graphs, resource limits and explicit adoption ownership.

Existing `child()` can be called from a step and durably await a child result. The separate native admission primitive below supplies a confirmed start milestone; it does not itself implement the state-bound binding protocol. Both paths use the existing child scheduler and history.

## Confirmed native child admission

Embedded V2 workflows can separate native child admission from waiting for its result:

```php
$child = self::startChild(ChildMachineWorkflow::class, $input);
self::activity(RecordChildAdmission::class, $child->toArray());
// Continue the parent, then observe this exact child when its result is needed.
$result = $child->await();
```

The child class and activity above are application implementations. `startChild()` accepts the same optional `ChildWorkflowOptions` as `child()`. The child may still be pending execution when the receipt returns: its native instance, run, lineage and task have been admitted, rather than merely queued as a request to create them. A cold duplicate resumes with the same admission. The later await creates neither another child nor another compensation owner, and works whether the child completes before or after the await.

`StartedChildWorkflow::toArray()` is the version-1 serialized receipt. It binds namespace, original parent instance/run, owning start sequence, child instance, initial child run, child-call ID and workflow type. `fromArray()` validates its shape; the runtime separately checks it against that parent's paired schedule/start history before awaiting. Modified, foreign-parent and missing-admission receipts refuse. Awaiting is scoped to the original parent execution. Query replay uses committed parent resolution history, not a live terminal row as an unrecorded result.

Native retries and continue-as-new remain attached only through the recorded descendant chain rooted at that receipt. A later current run in the same instance does not authorize substitution. Await cancellation records both its own workflow step and the original child operation, so cold replay preserves the selected child cancellation policy.

This primitive is experimental and embedded-only. Worker-protocol execution refuses these calls. It preserves ordinary `child()` terminal-wait behavior and parent-close/cancellation policies. `RecordChildAdmission` still owns application authorization, expected state/revision checks and a protected idempotent business commit; a receipt does not implement those boundaries. The scope/slot/generation binding, policy pinning, adoption ownership, business-outcome verification and retained recovery in the next section remain required before claiming nested business-machine support.

## Follow-up implementation sequence

1. Qualify this transition slice against the upstream quality gates and review its public names and persistence boundary before expanding the API.
2. Add state-bound machine definitions and a binding port with separate start/adopt and observe/await operations. Preserve scope, original parent owner, slot and generation. First prove duplicate-start and lost-acknowledgement recovery, stale-generation rejection and reuse across parent states with persisted worker tests.
3. Integrate the accepted durable compensation-scope implementation. Register the original owner before dispatch; route cancellation to the exact bound child and wait for verified child resolution before earlier parent Saga obligations. Do not replace this dependency with a private registry or compensation executor.
4. Complete the two-integration application example and persistence crash/race matrix: cancelled-before-dispatch, cancellation-versus-commit, partial effects, lost commit acknowledgement, worker restart, closed-run recovery, failed compensation and resource limits. Only then claim end-to-end nested business-machine support.

Each slice must preserve the four-method contract and existing DW workflow APIs. Supporting a child call inside `execute()` does not satisfy the binding and retained-recovery milestones by itself.

## Executable examples and evidence

The [workflow fixture](../tests/Fixtures/V2/TestBusinessStateMachineWorkflow.php) uses the production V2 fiber runner with portable activity payloads. It illustrates a preparation profile with two actions and an import profile with its own action and compensation; either profile can skip work when the evidence already exists. The policy tests additionally exercise differing edges with the same state catalogue. Activity responses are controlled test inputs, not external provider or persistence implementations. The fixture returns outcomes to assertions; it is not a complete application workflow that keeps obligations alive across business events.

```bash
vendor/bin/phpunit tests/Unit/V2/StateMachine
```

Tests exercise refusal/unknown outcomes, source and scope substitution, changed profile fingerprints, unavailable handlers, stale snapshots, protected-commit outcomes, compensation without a receipt, ordering and unresolved compensation. Cold replay reconstructs the original Saga after a recorded activity failure and reuses completed transition activities without scheduling new effects.

| Proposal acceptance item | Evidence in this slice | Remaining work |
| --- | --- | --- |
| 1–3 | Same generic contract, different profile actions, compensation, edges and fingerprints | Full nested example, rule/action configuration registry and policy migration |
| 4–7 | Skip work, refuse execution, fresh recheck, typed decisions, explicit ambiguity | Application rule evaluator integration |
| 8 | Two actions, partial failure and active-run compensation without receipt | Authoritative retained operation evidence through durable compensation scopes |
| 9–13 | Contract permits existing child calls | State-bound bindings, start/adopt/observe protocol and durable compensation-scope integration |
| 14–16 | Unknown/refused results do not manufacture success; commit rejection is preserved | Provider reconciliation and real persistence cancellation/commit race tests |
| 17 | Cold V2 replay consumes recorded activity results | Full nested and retained-recovery replay cases |
| 18 | Snapshot/admission/commit scope substitution rejects | Live authorization, binding generation and adversarial persistence tests |
| 19 | Invalid definitions and missing pinned handlers reject | Nested cycle/depth/budget limits and handler migration policy |
| 20 | Structured decision status, reason and evidence references | Binding and retained-compensation inspection |
| 21–22 | Runnable V2 regression example, existing APIs unchanged | Full integration example, persistence crash tests and upstream qualification |

This table reports scope, not completion of the issue's unchecked acceptance criteria.

## Native child reservation boundary

The experimental `Binding\ChildReservationStore` provides the native reservation needed before state-bound child admission. Call `reserve()` and `close()` inside recorded application activities, outside caller-owned SQL transactions. Each method owns its transaction so a returned closure is committed and host authorization runs before native locks. The supplied `BindingAuthorization` must validate the authenticated principal, original application/business owner, generation and requested operation before protected storage is read. Identity values and a serialized reservation are not credentials.

A reservation pins the original parent instance/run, business execution, slot/generation, policies, required outcome, portable input fingerprint, child definition fingerprint and child options. Repeating the exact reservation returns the stored descriptor. Reusing that lookup key with changed content refuses. New reservations require the active original parent; retrieving an existing reservation does not follow a newer current run or state entry.

Within the original parent workflow, `ChildReservation::start(...$arguments)` uses the normal confirmed native child-start command. Admission locks the reserved child instance before payload storage or child creation, checks the actual executing parent and pinned definition/input/options, and commits the original child receipt with the run and paired parent history. Replay compares the original reservation and reuses its original admission. It does not start another child.

`close()` locks that same instance. If admission committed first, it returns the exact `ChildBinding`. Otherwise it permanently closes the unused reservation and returns `NeverAdmittedChild` with the stored closure identity. Repeating close after a lost acknowledgement returns the same result. Late native work and ordinary start/signal-with-start cannot reopen that reservation. A missing record, malformed state or uncertain run pointer refuses rather than proving absence. Closed unused instances must remain retained; the reservation migration refuses rollback while any reservation exists.

This primitive does not resolve an existing `dw.child-scope` participant whose child scope is missing. The retained-scope adapter must independently reread authoritative closure evidence or wait for the exact admitted child's verified compensation. A deserialized `NeverAdmittedChild`, technical cancellation or lookup absence is insufficient. That adapter, protected business cancellation/commit integration, real database race qualification and the two-integration example remain required before the full binding contract is delivered. Forward ownership transfer through continue-as-new or redrive is not supported for these reserved children.
