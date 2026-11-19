# Business state-machine transitions

The optional embedded V2 transition protocol provides a four-method step contract, recorded assessments, shared machine definitions and versioned integration profiles. It uses existing native activities, children and Saga. Existing workflows and `Workflow\States\StateMachine` are unchanged. Symfony Workflow is not a dependency.

**Experimental.** The `Workflow\V2\StateMachine` API is under review and may change in a minor release; it carries no backward-compatibility promise yet. The [recorded Order/Shipment example](../examples/configured-state-machines/README.md) connects two integration profiles to retained compensation and protected SQL decisions. Optional definition and forward-execution budgets are described below. Provider integration and published-package recovery qualification remain unfinished. There is no in-flight profile migration API. These experimental additions first ship in 2.29.0. This does not complete the proposal.

## Responsibilities and flow

| Type | Responsibility and position in the flow |
| --- | --- |
| `Definition\MachineDefinition` / `MachineState` | Shared state identities and meanings, transition identities, mandatory invariant condition and named child slots. |
| `Definition\PolicyProfile` | Integration-specific edges and handler versions within its optional shared definition. Its fingerprint detects changed configuration under an old execution reference. |
| `Rules\MachineAssessment` | Recorded invariant decision bound to the definition, condition and exact fact snapshot. |
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
  -> recorded snapshot and shared invariant gate
     unsatisfied/unknown: Blocked/Unknown, no work or commit
  -> pure target guard [1]
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
       -> new recorded snapshot, shared invariant gate and target guard [3]
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

Guards perform no I/O. Their boolean result must agree with the corresponding recorded assessment. A definition-bound runner enforces its shared invariant before either work or commit, including when the initial target is already satisfied. The application evaluates the condition from recorded facts and enforces current authority and concurrency at its protected boundaries.

## Shared definitions and invariants

Use one `MachineDefinition` for the machine's state meanings and common constraints. Profiles may choose different edges, actions and handlers, but cannot add an undeclared state or transition identity. The definition's fingerprint covers the state meanings, invariant condition fingerprint and slot declarations. Changing any of them changes every attached profile fingerprint.

```php
$definition = new MachineDefinition(
    'orders', '1',
    [new MachineState('Received', 'The original order input was accepted.'),
     new MachineState('Processing', 'The original shipment admission was confirmed.')],
    ['process'],
    ConditionDefinition::rule(new RuleReference('order.owner-current', '1')),
    [new ChildSlot('shipment', ['Processing'], 'shipments', '1', 'delivered')],
);
```

The rule above is application-provided and registered through `RuleRegistry`. In the recorded snapshot activity, collect `RecordedFacts`, evaluate `$definition->assess($registry, $facts)`, retain the complete `ConditionEvaluation`, and return `MachineAssessment::fromEvaluation($definition, $evaluation)->toArray()` as `machineAssessment`. Every assessment must use that snapshot's exact ID. A missing assessment, changed definition or changed condition refuses before work. An unsatisfied invariant returns `Blocked`; unknown remains `Unknown`. Neither result automatically compensates effects already performed.

Named profile conditions cannot replace the shared invariant. A fresh post-work snapshot must assess it again before commit. Compensation still receives a correlated snapshot even when the forward invariant is no longer satisfied, so revoking forward permission does not prevent reconciliation of the original effects.

The assessment is recorded host evidence, not a signature or a proof that external facts are true. Authorize fact collection, retain its provenance and immutable explanation, and validate current authority, revision and cancellation under the application's authoritative commit fence. A satisfied recorded assessment cannot replace those live checks.

`$definition->assertBinding($parentProfile, $binding, $childProfile)` checks declared slot, hosting state, child machine/version, pinned profiles and required outcome. It performs no native lookup, authorization, admission, outcome verification or compensation. The binding's original owner/generation and joint scope authorization remain required.

Selecting a definition is explicit. Existing profiles and activity runtimes without one retain their original fingerprints and payloads. Attaching a definition creates a new profile fingerprint; it does not migrate an existing run. Retain the original profile, rules and handlers for that run.

Inside a V2 workflow's `handle()` method:

```php
$transition = new TransitionDefinition(
    'process', 'received', ['Received'], 'Processing', 'prepare', '1',
);
$profile = PolicyProfile::forDefinition(
    $definition, 'integration-a', '1', [$transition],
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
        CollectSnapshot::class, AdmitWork::class, CommitBusinessState::class, $definition,
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

## Registered condition rules

Configure a `Rules\RuleRegistry` in trusted application bootstrap with a map of registered type, version and `ConditionRule` object. Configuration selects those keys and validated parameters. Keep older implementations available for active profiles. A missing version refuses evaluation instead of selecting a newer implementation.

`RuleReference` pins the type, version and parameters. `ConditionDefinition::all()`, `any()` and `not()` combine registered leaves. Groups retain every child explanation, including unknown children when another child determines the result. For `all`, an unsatisfied child makes the group unsatisfied; otherwise an unknown child makes it unknown. For `any`, a satisfied child makes the group satisfied; otherwise an unknown child makes it unknown. Negating unknown remains unknown. Permission remains a separately evaluated decision.

Attach named conditions to a profile through its optional `conditions` constructor argument:

```php
$target = ConditionDefinition::all([
    ConditionDefinition::rule(new RuleReference('receipt.confirmed', '1', ['kind' => 'delivery'])),
    ConditionDefinition::rule(new RuleReference('account.allowed', '2')),
]);
$profile = new PolicyProfile(
    'fulfilment', '1', 'integration-a', '1', $sharedStates, $transitions,
    conditions: ['finish.target' => $target],
);
```

The registered types and variables above are application configuration. A condition's structure, parameters and rule versions contribute to the profile fingerprint. Existing profiles without named conditions retain their original fingerprints. Renaming a condition or changing its content requires selecting the new profile explicitly; `condition($name)` refuses a missing name.

Inside the recorded snapshot activity, collect `RecordedFacts` with a snapshot identity, a named value map and an evidence reference for every provided fact. A provided `null` remains distinct from an absent fact. Evaluate the pinned condition with `RuleRegistry::evaluateCondition()`, then return its decision through the existing snapshot `target` or `permission` field. Retain the complete `ConditionEvaluation::toArray()` with the immutable assessment for inspection. It includes the structure fingerprint, snapshot identity, rule versions, reasons, evidence and missing facts.

Constructors copy configuration, facts, evidence and child lists so that later changes through PHP array references cannot rewrite a validated profile or explanation. The registry also retains its original type/version mapping. Rule implementation objects remain trusted application code; their purity and version compatibility are application responsibilities.

Rules validate their parameters and evaluate only the supplied recorded facts. Live database/provider reads, action dispatch and waits belong in their recorded activity boundaries. The registry rejects substituted rule configurations, snapshots, foreign evidence and claims that a provided fact is missing. The application still owns authentication, evidence collection and validation at the protected commit; a serialized explanation is not an authority token.

Condition definitions have at most 64 nodes and also use the existing portable descriptor's byte, value and nesting bounds. Inputs contain portable data only. Parameter incompatibility and resource-limit refusals are explicit exceptions, without invoking an unregistered handler.

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

Snapshots carry assessments and references, not mutable business entities. The application retains the referenced facts, rule versions and evaluated condition structure. An `after` snapshot must use a different identity and freshly collected facts. A new ID alone does not prove the underlying evidence is current. When the runtime selects a definition, its snapshot request also includes `machineDefinitionFingerprint` and requires the corresponding `machineAssessment` response. Admission and commit requests carry that same assessment; a legacy runtime refuses this unselected channel.

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

For separate application and DW stores, the application's decision record is the business commit authority. Use an explicit durable handoff and idempotent outcome lookup. No distributed transaction is implied.

The [SQL business-decision example](../examples/business-state-machines/README.md) supplies an application-owned reference boundary and real database tests for cancellation/completion, authority revocation, atomic publication intent and lost acknowledgement. It does not replace application authentication, evidence validation, provider fencing or publication delivery. The in-memory runner tests alone do not establish persistence race guarantees.

## Compensation and nested-machine boundary

Registration happens before `execute()` can suspend or fail. The callback captures the original execution and step, not the eventual receipt. Compensation requests a fresh recorded snapshot and receives the original execution separately. Return from `compensate(): void` only after required effects or their absence are authoritatively verified. Unknown, pending and refused results require waiting or explicit failure. Technical retry exhaustion does not establish business cancellation.

This uses the existing active-run Saga. Use sequential compensation and keep error propagation enabled when earlier callbacks depend on later ones. The package does not change the workflow's existing Saga configuration, retry behavior or cancellation defaults.

The durable Saga compensation-scope work owns registration durability, recovery ownership and retained obligations after originating runs close. A run-local callback is not such a registry. This slice must not be used to claim closed-child/closed-parent compensation recovery or retained evidence after pruning.

The nested binding and retained-scope APIs below preserve the same four methods and provide:

- A binding of authorized scope, parent instance and original owner, named slot, immutable generation, host state, child identity, pinned policies and required business outcome.
- Separate start/adopt and observe/await operations. Starting must confirm the durable child identity before the parent commits the hosting state. Observation cannot create a second compensation owner.
- Original-generation routing after state changes/re-entry. Old requests never redirect to the newest child.
- Verified child compensation before earlier parent compensation. Technical child completion, accepted signals and timeouts are insufficient evidence.
- Explicit ownership of an admitted child. General graph/depth/resource budgets and adoption of arbitrary pre-existing external effects remain application design work.

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

## Configured resource budgets

Trusted application configuration can attach a `DefinitionLimits` to a shared
`MachineDefinition`. The definition checks its state, transition-identity and
child-slot counts before validating their contents. Each bound profile also
checks its expanded source edges and total condition nodes, including the shared
invariant. A transition allowing several source states consumes one edge per
source. The existing per-condition and portable-data limits still apply.
For example:

```php
use Workflow\V2\StateMachine\Definition\DefinitionLimits;
use Workflow\V2\StateMachine\Definition\ExecutionLimits;

$definitionLimits = new DefinitionLimits(
    states: 8, transitions: 16, childSlots: 2, ruleNodes: 128,
);
$executionLimits = new ExecutionLimits(
    commands: 64, historyEvents: 1_000, historyBytes: 1_048_576, groupDepth: 8,
);
```

Pass `limits: $definitionLimits` when constructing the shared definition. Create
a bounded profile with `$profile->withLimits($executionLimits)`, which returns a
new immutable profile, or pass `limits: $executionLimits` to
`PolicyProfile::forDefinition()`. Both limits enter their respective pinned
fingerprints. Omitting them preserves the previous serialized definition and
profile fingerprints; attaching or changing a budget requires an explicitly
selected compatible version for new executions. Rewriting an active assignment
does not migrate it or replace its outstanding compensation version.

The command budget covers every durable suspension inside `TransitionRunner`,
including snapshots, admission, actions and commit. It counts groups and their
leaves before emitting any member, so an oversized nested group dispatches no
prefix. There are at most 4,096 commands and 64 group levels. Reentering the same
original execution in the same workflow Fiber does not renew its allowance.
Replay reconstructs the same count from the recorded command path.

History values are **admission thresholds** measured with
`Workflow::historyBudgetAtStep()` before each command. They compare the recorded
run's event count and serialized history bytes at that replay-stable position.
At or above either selected threshold, the next forward command is refused.
They do not predict the size of the next result, impose an absolute post-write
byte ceiling, or replace the runtime's transaction-level history limits.
An unavailable measurement refuses rather than being treated as zero.

A limit reached during execution returns `TransitionStatus::Blocked`, with a
stable `state_machine_<dimension>_limit_exceeded` reason. Catching the original
refusal inside an action cannot authorize another command under that budget.
Invalid definitions raise `ResourceLimitExceeded` with the observed dimension,
count and configured limit before invoking a handler. Neither outcome means
business cancellation succeeded or an earlier external effect did not happen.

Existing Saga registrations remain intact. `Workflow::compensate()` runs
outside these forward budgets, while existing cleanup deadlines, technical
cancellation rules and global runtime limits remain in force. The application
must explicitly select compensation or retained recovery and preserve unresolved
obligations; a blocked transition does not automatically clear them or commit a
business `Cancelled` state. The existing retained-scope graph bounds and explicit
retention release remain separate from a definition's declared child-slot count.
Provider retries, fixed business deadlines and reconciliation policy still use
the existing DW facilities and application integration contract.

The [native budget fixture](../tests/Feature/V2/V2StateMachineResourceBudgetTest.php)
checks recorded activities, partial-work compensation and cold replay with a
synthetic SQL effect journal. It also closes a budget-blocked origin with an open
retained scope, then verifies recovery of that exact ledger without modifying
the original terminal history. Retention across closure requires those
`DurableScope` obligations; an in-memory Saga callback alone does not supply it.
The fixture does not qualify a remote provider or the full
[consumer scenario matrix](https://github.com/durable-workflow/workflow/issues/1069#issuecomment-6889952106).

## Follow-up implementation sequence

1. Retain the original machine definition, profiles, rule versions and handlers across deployments; define an explicit migration policy before moving existing instances.
2. Use the recorded two-profile example to integrate application authorization, provider reconciliation and retained child-first recovery. Its SQL effect model does not qualify a real carrier or payment provider.
3. Select definition and execution budgets for the application's pinned profiles. Qualify provider attempts, fixed business deadlines and retained recovery when a forward budget or technical deadline is exhausted.
4. Qualify the package candidate through installed upgrade, worker-loss and recovery scenarios, then publish versioned guides and an immutable package before claiming release delivery.

Each slice must preserve the four-method contract and existing DW workflow APIs. Supporting a child call inside `execute()` does not satisfy the binding and retained-recovery milestones by itself.

## Executable examples and evidence

The [workflow fixture](../tests/Fixtures/V2/TestBusinessStateMachineWorkflow.php) uses the production V2 fiber runner with portable activity payloads. It illustrates a preparation profile with two actions and an import profile with its own action and compensation; either profile can skip work when the evidence already exists. The policy tests additionally exercise differing edges with the same state catalogue. Activity responses are controlled test inputs, not external provider or persistence implementations. The fixture returns outcomes to assertions; it is not a complete application workflow that keeps obligations alive across business events.

```bash
vendor/bin/phpunit tests/Unit/V2/StateMachine
```

Tests exercise refusal/unknown outcomes, source and scope substitution, changed profile fingerprints, unavailable handlers, stale snapshots, protected-commit outcomes, compensation without a receipt, ordering and unresolved compensation. Cold replay reconstructs the original Saga after a recorded activity failure and reuses completed transition activities without scheduling new effects. `V2MachineDefinitionTest` additionally drives actual native activities through shared-invariant refusal, post-work revocation and replay without live fact collection. The recorded Order/Shipment example tests both profiles against application SQL and retained recovery. Its explicit version-two assignment uses shared definitions for parent and child while retaining version-one fingerprints and handlers for existing runs. Forward permission stays separate from the common model invariant, so verified cancellation remains available after the forward fence closes.

| Proposal acceptance item | Source and test evidence | Remaining work |
| --- | --- | --- |
| 1–3 | Shared definitions and invariants; two-profile recorded Order/Shipment example with different actions, edges and fingerprints | Explicit policy/handler migration |
| 4–7 | Skip work, refuse execution, fresh shared-invariant recheck, typed decisions and registered rules | Application-specific rule and provider integrations |
| 8 | Retained original operation evidence, partial failure and recovery after worker loss | Installed package/provider qualification |
| 9–13 | Original-generation bindings, confirmed admission/await, reserved retained child participation | General graph/depth/resource policy |
| 14–16 | SQL cancellation/commit fencing, lost acknowledgement and verified child-first recovery | Provider-specific reconciliation and publication delivery |
| 17 | Cold replay of transitions, child admission and retained recovery; installed 2.32.0 upgrade, rollback and downgrade journey for the examples | Real-provider qualification |
| 18 | Joint binding/scope authorization, generation and protected SQL substitution controls | Application authentication and authorization deployment |
| 19 | Invalid definitions and unavailable pinned handlers refuse | Budgets and explicit migration policy |
| 20 | Structured decisions, complete condition explanations and retained ledger inspection | Application-facing operational tooling |
| 21–22 | Recorded two-profile example, native persistence/crash tests, existing APIs preserved | Final package, installed qualification and versioned publication |

This table reports scope, not completion of the issue's unchecked acceptance criteria.

### Installed upgrade, schema rollback and downgrade

`scripts/ci/state-machine-upgrade-smoke.sh` installs published 2.32.0 into a new
Laravel application with MySQL or PostgreSQL and Redis queue workers. 2.32.0 is the
first release whose compensation API matches the current stable one; the 2.29.0 to
2.31.0 examples use `OperationContext::$uncertainForward`, which 2.32.0 removed. The package ships `examples/`, so the application copies the provider-backed
and configured examples out of the installed 2.32.0 package. That application code
stays the same through every package change below. Each example runs with its own
database and queue, because both bind the engine's handler registry and scope
authorization.

The journey checks these steps:

- **Runs left mid-flight on 2.32.0.** Two provider-backed checkout Orders are booked
  and wait for `finish`. One of them has an accepted cancellation whose provider
  refund is still processing, so its retained scopes are `requested`. A configured
  Order hosts its completed Shipment and waits for `finish`.
- **Upgrade to the candidate.** Migrations run and the workers restart. Every
  recorded run replays without querying business tables or calling the provider. A
  replayed failed run reproduces its recorded failure.
- **Recovery on the candidate.** The waiting Orders finish. The refund is confirmed,
  and a fresh cancellation request verifies the root and child scopes. The finalizer
  then commits `cancelled`. The synthetic provider journal records each effect once,
  in the original compensation order. Histories of runs that were closed before the
  upgrade do not change.
- **Schema rollback.** `migrate:rollback` is refused before any migration runs while
  the ledger holds retained scopes or native child reservation fences. Package and
  application tables are unchanged. The example tables are application migrations,
  so a package rollback never reaches them.
- **Package downgrade to 2.32.0.** The ledger, business tables and histories are kept,
  and rollback is still refused. The downgraded engine books a new checkout Order on
  the same schema.
- **Upgrade again.** That Order finishes. Cancellations of the closed Orders take the
  recovery route, verify, and publish `cancelled`, again without repeating a provider
  effect.

The journey does not cover runs started by the candidate and then resumed by an older
engine. Do not downgrade while such runs or their recovery runs are open. It does not
qualify a real provider either: the provider journal is the examples' SQLite
simulator. The journey runs on pull requests that change the state-machine or
compensation code, the examples or migrations, and on stable release tags against the
published package.

## Native child reservation boundary

The experimental `Binding\ChildReservationStore` provides the native reservation needed before state-bound child admission. Call `reserve()` and `close()` inside recorded application activities, outside caller-owned SQL transactions. Each method owns its transaction so a returned closure is committed and host authorization runs before native locks. The supplied `BindingAuthorization` must validate the authenticated principal, original application/business owner, generation and requested operation before protected storage is read. Identity values and a serialized reservation are not credentials.

A reservation pins the original parent instance/run, business execution, slot/generation, policies, required outcome, portable input fingerprint, child definition fingerprint and child options. Repeating the exact reservation returns the stored descriptor. Reusing that lookup key with changed content refuses. New reservations require the active original parent; retrieving an existing reservation does not follow a newer current run or state entry.

After a deployment or lost acknowledgement, `load($originalIdentity, $principal)` recovers the exact stored descriptor without resolving or loading its historical PHP definition. The host must authorize the `load` operation against the original business owner. Missing records and changed binding content refuse. Loading neither starts a child nor proves absence. Use the returned descriptor with `close()` to obtain the authoritative admission or committed closure; a newer definition cannot replace the pinned one.

Within the original parent workflow, `ChildReservation::start(...$arguments)` uses the normal confirmed native child-start command. Admission locks the reserved child instance before payload storage or child creation, checks the actual executing parent and pinned definition/input/options, and commits the original child receipt with the run and paired parent history. Replay compares the original reservation and reuses its original admission. It does not start another child.

`close()` locks that same instance. If admission committed first, it returns the exact `ChildBinding`. Otherwise it permanently closes the unused reservation and returns `NeverAdmittedChild` with the stored closure identity. Repeating close after a lost acknowledgement returns the same result. Late native work and ordinary start/signal-with-start cannot reopen that reservation. A missing record, malformed state or uncertain run pointer refuses rather than proving absence. Closed unused instances must remain retained; the reservation migration refuses rollback while any reservation exists.

This primitive does not resolve an existing `dw.child-scope` participant whose child scope is missing. A deserialized `NeverAdmittedChild`, technical cancellation or lookup absence is insufficient. Forward ownership transfer through continue-as-new or redrive is not supported for these reserved children.

### Retained child participation

The experimental `DurableScope::reserveChild($key, $childScope, $bindingIdentity)` connects a native reservation to a retained Saga participant. Configure the binding policy to implement `ChildScopeBindingAuthorization`, alongside `ScopeAuthorization` and a trusted `ChildDefinitionRegistry`. The registry selects a workflow class by the binding's exact child workflow type and pinned child-policy fingerprint. Recorded arguments never select a PHP class to autoload.

`ChildScopeBindingAuthorization` extends the ordinary `BindingAuthorization` contract. Its `assertParticipationAllowed()` receives the complete binding, parent/child scope pair, authenticated principal, operation and any pinned policy version. The host must authorize their relationship: the exact business owner, original execution, state-entry generation and corresponding retained scopes. Independently allowing the binding and both scopes does not authorize pairing them. The returned policy version is retained in the reservation and participant descriptor; loading or closing it must still enforce that original version or refuse. A changed policy cannot silently replace it. Version-1 reservations continue to use ordinary binding authorization.

Inside the original parent workflow, reserve participation before starting the child:

```php
$reservation = $scope->reserveChild('shipment', $childScope, $bindingIdentity);
$started = $reservation->start($businessInput, $childScope->toArray());
// Commit the hosting business state through the application's protected boundary.
// A later step awaits this original admission; it does not reserve another owner.
$result = $started->await();
```

Here `$scope` is the parent's `DurableScope`, and `$bindingIdentity` pins the original owner, generation, policy, options and fingerprint of both child arguments. The child workflow must use the exact supplied child scope for its own `DurableScope` operations and establish coverage before every external effect. Child business outcome validation remains an application responsibility.

The recorded reservation activity authorizes the requested relationship before its reservation lookup, then commits an unused native reservation and the retained participant under the native activity-attempt fence. Host policy code runs outside native SQL locks. A crash between those transactions can leave an unused reservation, but native admission refuses until the exact participant and joint policy binding are committed and the parent scope still permits forward work. Retrying can load that original descriptor even when its historical registry entry is no longer deployed, provided its original relationship policy remains enforceable. Native admission still requires the pinned executable definition; a changed definition cannot replace it.

Admission commits the genuine child scope with its actual original native run, receipt and parent history in one transaction. Parent compensation first closes the native reservation. A committed never-admitted closure permanently fences late starts and can settle that participant without inventing a child ledger. If admission won, only that original child's verified retained scope can settle it. Missing, foreign or unresolved child scopes remain unresolved, and technical child completion is insufficient. The new `dw.reserved-child-scope` participant does not change the older `dw.child-scope` contract.

The recorded Order/Shipment example connects this primitive to protected business cancellation and verified child-first recovery, including active and closed original runs. Its tests do not qualify arbitrary providers, automatic policy migration or the package's installed release path. Those remaining boundaries keep the full business-state-machine contract unfinished.
