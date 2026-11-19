# Recorded Order and Shipment transitions

This experimental application example composes the existing `TransitionRunner`,
`ActivityTransitionRuntime`, rule registry, native child reservations and retained
compensation scopes. It is a repository source example rather than an installed-package guide.
The complete [business-state-machine feature](https://github.com/durable-workflow/workflow/issues/1069)
remains unfinished. The retained-scope additions first ship, experimentally, in 2.29.0.

The application assigns each Order a pinned `prepare` or `imported` profile before
starting its native workflow. Both profiles share the same state catalogue and
forward enabled/cancellation guards. Preparation performs reservation and
preparation actions and hosts Shipment in `working`; import performs one action
and hosts Shipment in `received`. Shipment uses the same four-operation transition
contract and runner for delivery. A prevalidated imported order also has a
proof-only reconciliation edge: satisfied facts skip work entirely.

## Explicit profile versions

Version one retains its original fingerprints, snapshot payloads and handler
selection. The bootstrap registers both versions' original child policies so a
new deployment can still recover version-one reservations and compensation.
The default assignment remains version one. Select version two explicitly when
seeding a new authorized subject:

```php
$store->seed('case-aaaaaaaaaaaaaaaa-g1', 'prepare', version: '2');
```

Version two attaches both integration profiles to the same `MachineDefinition`
for Order and another shared definition for Shipment. These definitions pin
state meanings, transition identities and the Shipment slot. Its invariant
requires a declared business state and a positive business revision, including
when target evidence already exists. The snapshot stores the definition-bound
`MachineAssessment` and its complete rule explanation. Admission and commit must
use that exact immutable assessment; a substituted proof refuses.

Forward permission remains a transition condition. Closing forward admission
does not falsify the shared model invariant or prevent the separately verified
cancellation transition. Protected commits still compare live authority,
revision and cancellation under the business lock; the recorded proof is not a
concurrency fence.

Do not rewrite a running subject's `profile_version` or grant to migrate it.
That changes its fingerprint and refuses the original execution. Keep its
original assignment available through recovery. This example offers selection
for new instances and explicit refusal of an unapproved rewrite; it does not
implement an authorized in-flight migration protocol.

## Recorded execution

`BoundaryActivity` loads the trusted application assignment and original native
owner. Workflow arguments do not select the profile or principal. Each transition
then follows the existing protocol:

1. A snapshot activity collects facts and evaluates the closed rule registry. It
   retains immutable facts, evidence, explanations and a correlated snapshot ID.
2. The step's boolean guards project that recorded assessment. They perform no
   database, configuration, provider or container access.
3. If work is necessary, an admission activity checks the current business fence
   and expected source revision. Native Saga captures the original step before
   its `execute()` can suspend or fail. Every SQL effect has its portable retained
   obligation registered before dispatch.
4. Work is followed by a different recorded snapshot. A version-two machine
   invariant and the target assessment must both be satisfied before protected
   business commit, which atomically writes
   business state, decision and publication intent in the example database.

`MachineAuthority` owns native/business authorization, `AssessmentStore` owns
recorded assessments and forward commits, and `CancellationStore` owns the
cancellation fence and verified finalization. The common enabled/forward-open
guards apply to ordinary work; cancellation uses its separate retained proof.

Starting Shipment and awaiting its result are separate transitions. `begin`
reserves and admits the child, persists the exact binding and commits the hosting
state. `finish` observes that same binding after a durable `finish` signal; it does
not create another child or compensation participant. Both the child's concrete
business result and its original SQL business evidence are required. Technical
workflow completion alone is insufficient.

Replay uses recorded activity results, native command receipts and pinned source
definitions. It does not query the application tables or re-evaluate live rules.
Old catalogue, handler and authorization versions must stay deployed while their
executions or retained obligations remain recoverable.

## Cancellation and recovery

The trusted host calls `MachineStore::requestCancellation()` with the original
subject, native run and immutable request key. It locks the parent business row
and its existing children, persists the request and closes SQL forward/commit
admission before routing through native `ScopeRecovery`. A child initialized
later inherits that same fence under the parent lock.

The original native Saga handles an active owner; an explicit recovery execution
handles a closed owner. Child compensation must verify before earlier parent
effects are undone. The transition callback verifies only its own original
obligations after their native callbacks; checking the entire scope from each
callback would prevent earlier callbacks from running. Observer steps own no
duplicate undo. The complete scope is verified after Saga finishes.

`finishCancellation()` is a separate host milestone after verified recovery. Its
proof-only native transition validates the accepted cancellation, original scope
owner, live authority and expected business revision. It atomically commits
`cancelled`, its decision and publication intent. A queued request, technical
closure, failed compensation or temporarily absent effect cannot supply that
proof. Original terminal workflow histories remain unchanged.

The host must resume an accepted cancellation intent if it loses the routing
acknowledgement, and call the finalization milestone after recovery verifies.
Reevaluate that same original request after the reported route progresses. A
parent can close with compensation unresolved while its genuine child recovery
is still running. The task watchdog then routes the accepted request to the
parent's canonical recovery under the re-authorized original principal (#1205).
Child verification wakes that recovery's waiting retry. A refused re-authorization
is recorded as `recovery_authorization_failed`, and a failed recovery execution
keeps its failure. Either one requires the explicitly reported fresh authorized
request.
This example supplies neither a background intent dispatcher nor cross-store
atomicity. Shipment's recorded business completion remains historical; retained
compensation is inspected separately from that completed state.

Root startup is still a boundary: business initialization does not atomically
open the native scope. The first genuine recorded no-effect ownership obligation
opens it before any transition work. If the original root closes before that
registration, cancellation remains explicitly unresolved. The example never
synthesizes an empty verified scope.

## Run the executable tests

Use the repository's normal development dependencies and test database setup:

```shell
vendor/bin/phpunit tests/Feature/V2/V2ConfiguredStateMachineExampleTest.php
```

The example uses its own disposable application tables, created by the shared
SQL-example schemas followed by `schema.php` in this directory. These are not DW
package migrations. Load `bootstrap.php` explicitly and configure `MachineStore`
with the worker's trusted principal and allowed business subjects. Provider
effects here are synchronous writes in the same SQL service; all writers share
its subject lock, permanent operation receipt and cancellation fence.

This does not qualify remote carrier behavior. A remote integration must supply
its own idempotency, durable admission/fencing and reconciliation of uncertain or
late results. Missing lookup results are not permanent non-admission evidence.
The current retained graph is limited to one parent/child level, sixteen children
and 256 obligations per scope. Root and child forward ownership do not transfer
to continue-as-new/redrive successors. Retention release remains explicit.

The example does not add profile migration, arbitrary executable configuration,
recursive/shared children, an event router, circuit-breaker policy, or a
publication dispatcher. Its original profiles leave the optional
[resource budgets](../../docs/business-state-machines.md#configured-resource-budgets)
unset; introducing them requires an explicitly selected compatible profile for
new executions. Profile assignments stay pinned to original runs;
authority changes must use the shared business lock and cancellation fence.
No release date or full StateMachine completion follows from this example.
