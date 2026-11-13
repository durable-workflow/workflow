# Durable compensation scopes (experimental)

Implementation of the embedded, sequential scope design in
[retained compensation proposal](https://github.com/durable-workflow/workflow/issues/1063), preparing the
nested-machine integration in [business state-machine proposal](https://github.com/durable-workflow/workflow/issues/1069).
This is not a released API. Existing run-local Saga and the four-method
`TransitionStep` contract remain unchanged.

## Boundaries

A scope is `(application, namespace, key, generation)`, not a workflow run.
The native default namespace remains `null`; it is never replaced by an invented
alias. The original run and a later recovery run have separate identities. One scope
contains at most 256 obligations. Stored descriptors contain a closed handler
identifier and version, stable operation key and portable data (16 KiB, depth 8,
512 values). They never contain executable closures or arbitrary class names.
Applications store protected references instead of credentials or customer data.
The principal comes only from trusted host code, never directly from webhook or
HTTP start input. Content hashes detect accidental corruption, not an attacker
with write access to both the descriptor and its hash.

Persisted descriptors use explicit `schemaVersion: 1` arrays. Scope IDs and
obligation fingerprints hash that canonical schema, not PHP property reflection.
Unsupported versions fail closed. Recorded activities use stable
`dw.compensation.*.v1` type identifiers. Scope and recovery activity layouts carry
native version markers. This first experimental format does not qualify replay
of pre-versioned development histories.

The registration journal and obligation commit together. Workflow history records
activity boundary outcomes; a lost activity acknowledgement is adopted by exact
identity. Forward work must use the scope admission gate. Registering a descriptor
alone does not make an unrelated activity cancellation-safe.

Live database, authorization and handler resolution happen at activity boundaries,
never during deterministic replay. Native activity retries and native Saga remain
responsible for execution. No independent timer, worker or retry engine is added.
Every scope derives one canonical recovery workflow instance ID. Native DW
signal-with-start admits or adopts one canonical recovery run; native redrive admits one successor after an eligible
failure. A native returned-existing-active start receipt remains eligible for
redrive only when its run identity and accepted start-command snapshot match.
A technical redrive key includes the exact failed source run. One unchanged
retained request can therefore recover several failed successors while duplicate
requests for one source adopt the same successor. Scope progress must be written
under the current native activity attempt's authority, in the same database transaction as the ledger mutation. A status check
followed by an unguarded write is insufficient. No scope lease or separate owner
claim is introduced. Native fencing cannot recall an issued external request.
Handlers must reconcile unknown provider outcomes using stable operation keys.
A returned callback, timeout or expired lease is not verified compensation.

Rows use the workflow storage connection. Scope rows serialize ledger mutations;
obligation and journal rows follow that lock. Host policy, input validation and provider calls run outside native SQL
locks. Their immutable policy version and descriptor fingerprint are captured
before admission. Under locks the boundary checks native authority, the pinned
principal and the exact immutable descriptor again. A later policy change is
checked at the next boundary, not by executing another host callback under locks. The ledger has no cascading foreign key to run history: normal history
pruning must not erase unresolved obligations or reuse their identities.

## Runtime flow and responsibilities

```mermaid
flowchart TD
    A[DurableScope.execute] --> B[RegisterObligationActivity]
    B --> C[Atomic descriptor and journal]
    C --> D[Native Saga callback registered before effect]
    D --> E[ForwardOperationActivity]
    E --> F[ScopeBoundary: native attempt + authorization + gate]
    F --> G[Host operation outside SQL locks]
    G --> H[Guarded forward outcome]
    R[ScopeRecovery.request] --> Q[Content-bound request closes gate]
    Q --> I{Origin active?}
    I -->|yes| J[Native cooperative cancellation and original Saga]
    I -->|closed| K[Canonical RecoveryWorkflow: native start/adopt]
    K --> L[Native Saga in reverse registration order]
    J --> L
    L --> M[CompensateObligationActivity]
    M --> N{Ordinary operation or child scope?}
    N -->|operation| O[Closed versioned handler registry]
    N -->|child| P[Exact child request and verified resolution]
    O --> V[Guarded authoritative evidence]
    P --> V
    V --> W{All obligations verified?}
    W -->|yes| X[VerifyScopeActivity]
    W -->|unknown or failed| Y[Native retries; retain unresolved ledger]
    Y --> Z[Eligible native redrive reuses verified activity prefix]
```

Host `ScopeAuthorization::assertAllowed()` and `ScopedOperation::assertInput()`
run before the guarded transaction. Host callbacks must not leave an open SQL
transaction. Parent and child authorization decisions keep their own recorded
policy versions. No HTTP or provider callback runs while native rows are locked.

`ScopeBoundary` uses the existing native activity status/authority checks while
retaining their SQL locks in the ledger transaction. Its lock order is native
attempt, execution, run and task, followed by the scope and its journal. It checks
authority again after the mutation. Provider calls run between guarded transactions,
never inside them. The journal records principal, authorization policy version,
origin/current run, activity and attempt references. Child participation records
the child authorization policy separately from the parent registration policy.
A missing or invalid policy version refuses participation before registration.
The host must bind
`ScopeAuthorization`; there is no permissive default.

`HandlerRegistry` is supplied by trusted host code. Its entries implement
`ScopedOperation::assertInput()`, `execute()` and `compensate()`. These are provider
boundary handlers; they do not change the four `TransitionStep` methods. Forward
and compensation outcomes accept a list of at most 32 immutable evidence
references, each at most 128 bytes. This leaves room for native identity metadata inside the
existing 16 KiB, 512-value journal bound. Larger provider proofs should be stored
as protected evidence bundles and referenced by a stable identifier. An
uncertain forward result requires reconciliation against the stable operation ID
before an unsafe provider reissue. `Resolution::verified()` additionally requires
settled-forward evidence; neither a callback return nor a native terminal run proves
that business result. Handler unavailability and descriptor hash mismatch refuse
before both forward and compensation provider operations. Forward handler and input
validation run before dispatch admission: a missing pinned handler or incompatible
input retains its native nonretryable reason without creating provider uncertainty.
A refused retry preserves any earlier uncertain attempt. Exceptions after provider
dispatch remain unknown and require reconciliation, even if their PHP type is
nonretryable.

`ChildScopes` registers compensation participants, not state-machine bindings.
The initial graph is a bounded tree with one nesting level, at most 16 children
per parent and at most 256 obligations per scope (at most 4,352 across that tree).
Children cannot be shared between parents. One application/namespace graph-integrity
row serializes edge changes before scope locks. It contains no executor, lease,
heartbeat or retry state. A missing or unverified child stops earlier parent work.
Long child cleanup can exhaust the ordinary native activity retry budget; the
parent remains unresolved and can resume through eligible native redrive.

A scope stays attached to its original native run for forward execution. This
increment does not transfer forward ownership across continue-as-new or origin
redrive, or a native child retry. `generation` must be unique for each originating
native run. Reusing a generation from a new run fails with the non-retryable
`scope_owned_by_another_run` reason. The original retained obligations remain
recoverable through their exact original scope identity.

The first accepted request pins the cleanup principal. Later authorized
requests can route recovery but cannot replace that principal. Recovery evaluates
current authorization again at every activity boundary. Revoked authority leaves
work unresolved rather than silently adopting another principal.

`ScopeRecovery::request()` on an active origin requests cancellation of the whole
originating run. Native Saga then executes all its registered callbacks, including
other scopes and callbacks not managed by scopes. A request is not a selective
rollback of one scope while that origin continues forward.

A canonical recovery that was cancelled or terminated can be restarted through
native signal-with-start on the same recovery instance. This creates a fresh run,
keeps closed histories unchanged, and skips already verified ledger obligations.
Failed recovery still uses native redrive when its history is eligible.

All scoped activities use three native attempts and
the native backoff. No scope retry loop is introduced. `DurableScope.execute()`
accepts native `ActivityOptions` for forward work. Immediate terminal cancellation
or termination can prevent cleanup; retained obligations remain for explicit
recovery. A request accepted on an active origin is durable routing evidence; if
that origin closes without resolving its obligations, repeat the same authorized
request to route it to canonical recovery.

`ScopeRetention::release()` requires verified work and terminal native executions,
and refuses a child still retained by an unresolved parent. It removes retained
inputs and forward results while preserving scope/generation, operation and request
identities, fingerprints, outcome references and journal tombstones. Forward
observation references are recorded in the immutable journal before release.
The journal does not retain the forward business value or obligation input.
No automatic expiry is added. Ordinary run-detail pruning cannot cascade into this ledger.

## Continue-as-new and originating-run redrive

Scope identity and native child supervision are separate. When a workflow
continues as new, native DW can hand its still-open children to the continued
run. This does not transfer the parent's compensation scope or the children's
own scopes. Each retained scope keeps its `original_run_id`. A parent that must
compensate a child registers it with `participate()` before dispatch, regardless
of which run later supervises that child.

New registration from a continued or redriven origin is refused by the retained
original-run binding. A redrive may replay an observed activity prefix, but it
cannot use that prefix to dispatch new work in the old scope. Resuming an
uncertain forward activity in the successor is also refused by `ScopeBoundary`
before provider dispatch. The original obligations and provider evidence remain
available. Hosts request `ScopeRecovery` for that exact identity and generation
to resolve them. This increment does not silently create replacement scopes,
move obligations to a new generation or infer compensation from run closure.

`V2CompensationScopeRunBoundaryTest` exercises real continue-as-new with an open
scoped child, native supervision handoff, refused successor work and verified
child-first recovery. It also checks originating-run redrive after an unrelated
failed activity and after an uncertain scoped forward effect. Recovery preserves
the original terminal history in all cases.

## Core redrive regression evidence

The replay corpus policy explicitly guards `FailedRunRedrivePlan` and
`ActivityRetryHistory`, so changes to either require executable regression evidence.

The planner changes are independent of compensation scopes. They accept native
`ActivityRetryScheduled` lineage only when execution, attempt, task and attempt
number references agree. A valid native active-start adoption receipt must also
match the run and accepted command snapshot. The planner keeps current main's
completed-child reuse behavior.

The `redrive-retried-completed-activity`, `redrive-retried-failed-activity` and
`redrive-retried-activity-after-completed-child` regression-corpus fixtures retain
both a cold replay prefix and the failed source history. The official
`ReplayRegressionCorpusTest` consumer executes `FailedRunRedrivePlan` against the
source and its identity-substitution cases, then executes the cold workflow
replay. This provides planner evidence separately from the scope integration
tests. `FailedRunRedrivePlanTest` separately covers start-adoption receipt
substitution. The separate `redrive-native-active-start-adoption` corpus fixture
executes the same acceptance and substitution checks for recorded start receipts.
Its planner source retains the native audit-enriched command snapshot. Its cold
replay prefix contains the authored event payload, which is checked against the
strict event payload contract before native audit enrichment.
Existing completed-child corpus and feature regressions remain
applicable. These evidence paths allow the core planner change to be reviewed
or extracted separately from the experimental scope API.

## Authoring and host setup

The embedded host binds `ScopeAuthorization` to its authorization policy and
`HandlerRegistry` to a closed map of handler type/version pairs. Normal package
migrations create the ledger tables on the configured workflow storage connection.
No additional queue, worker or lease configuration is required.

Inside a native workflow, create `DurableScope($this, $identity, $principal)` and
call `execute(new Obligation($key, $handlerType, $handlerVersion, $input))`. Register
all effects through this helper. On cleanup, call the existing
`$this->compensate()` and then `$scope->verifyCompensation()`. The helper does not
catch the application's business failures or decide its business state.

`participate($key, $childIdentity)` registers a compensation participant. It does
not start a child, await its business result or establish a state-machine binding.
Register that participant before dispatching new child work. Each child registers
its own provider obligations before effects. A missing child remains unresolved
and cannot satisfy the parent barrier.

After the origin closes, the host calls `ScopeRecovery::request($identity,
$requestKey, $principal, $reason)`. Obtain the principal from trusted application
authorization, not from untrusted request content. Repeating the same request
requires the same principal and reason. Inspecting or requesting retained work
must go through an authorized host boundary; the `@internal` persistence classes
are not an HTTP-facing data access API.

## Evidence and review

| Guarantee | Responsible component | Acceptance test |
| --- | --- | --- |
| Exact request and generation identity | ScopeIdentity, ScopeRepository, ScopeRecovery | ScopeRecoveryTest, V2CompensationScopeConcurrencyTest |
| Current native attempt owns ledger mutation | ScopeBoundary | ExpiredNativeAttemptCannotPublishVerifiedCompensation |
| No false success for late/unknown effects | ScopeProgress, CompensateObligationActivity | PossibleLateForwardEffectCannotBecomeVerifiedAfterRetryExhaustion |
| Closed-run recovery and original terminal history | RecoveryWorkflow, native Saga | ClosedOriginUsesCanonicalRecoveryAndKeepsOriginalTerminalHistory |
| Verified progress survives failure and redrive | Native redrive plus ledger | RetryExhaustionStaysUnresolvedAndNativeRedriveReusesVerifiedWork, OneRetainedRequestCanRedriveTwoFailuresWithoutRepeatingVerifiedWork |
| Native start adoption preserves eligible redrive | FailedRunRedrivePlan | NativeActiveAdoptionDoesNotPreventRecoveryRedriveAfterFailure, SubstitutedActiveStartAdoptionCannotAuthorizeRedrive |
| Forward evidence survives explicit release | ScopeProgress, ScopeRetention | ForwardEvidenceSurvivesReleaseWithoutRetainingBusinessInputsOrResults |
| Replay does no handler/provider resolution | DurableScope, recorded activities | ColdReplayDoesNotResolveMutableHandlersOrRepeatProviderEffects |
| Child-first sequential progress | ChildScopes, ChildResolution | CompletedChildScopeIsVerifiedBeforeEarlierParentCompensation, CancellationWhileChildWaitsResolvesItsSagaBeforeEarlierParentWork |
| Child authorization evidence | RegisterChildScopeActivity | ChildParticipationRecordsItsOwnAuthorizationPolicyVersion, InvalidChildParticipationPolicyCannotRegisterAChild |
| Retained descriptor integrity before effects | ForwardOperationActivity, CompensateObligationActivity | TamperedRegistrationCannotReachTheForwardProvider, TamperedRetainedDescriptorIsRefusedBeforeProviderCall |
| Live storage is forbidden during replay | ScopeRepository, ScopeStorage | OpeningLiveStorageFromWorkflowReplayIsRefusedBeforeWriting |
| Bounded retained work | ScopeRepository, ChildScopes | ObligationLimitCannotLeaveAPartialRegistration, ChildFanOutLimitCannotLeaveAPartialParticipant |
| No cycle under concurrent registration | ChildScopes integrity lock | CompetingEdgesCannotCreateACycle |
| Ordinary pruning preserves recovery | Independent retained ledger | OrdinaryRunPruningPreservesRetainedScopeRecovery |
| Explicit release cannot reuse identifiers | ScopeRetention | ReleaseRefusesUnresolvedWorkThenRetainsAnUnreusableTombstone |
| Fresh-process recovery | Native workflow and persisted ledger | RetainedRecoveryRunsInAFreshPhpProcess |

Integration exposed a native redrive limitation: `ActivityRetryScheduled` was
refused as unsupported history. `ActivityRetryHistory` validates exact execution,
attempt counters and task lineage before the existing redrive planner accepts that
history. Substitution controls accompany the real retry-exhaustion regression.

Local evidence does not establish a published package, CI matrix qualification,
service-mode/cross-SDK support or production-provider behavior. The full quality
cycle and final evidence counts are recorded after the last code review.
