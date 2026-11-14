# SQL business decisions

This application-side example implements the `TransitionRuntime::commit()` persistence boundary using Laravel's database connection. It uses application tables in `schema.php`; it does not add DW migrations or change native workflow storage. Invoke `SqlBusinessStore` from a recorded application activity.

The subject row is the common fence for completion, cancellation, and authority changes. The store checks the original state and revision, then writes the new state, immutable decision, and publication intent in one transaction. Retrying an identical decision after losing its acknowledgement returns the original result. Reusing its identity with another subject or content refuses. A competing transition gets a revision conflict.

Cancellation takes that same row lock. Cancellation accepted first prevents the completion commit. Completion accepted first makes cancellation against the old revision a conflict. `cancellation_requested` means business forward completion is fenced; it does not mean external effects stopped, compensation succeeded, or the business reached a Cancelled state.

The trusted authorization callback receives the full execution, principal, operation, and proposed transition context. It must authenticate the principal, enforce the exact pinned profile and transition, and verify the assessment against authoritative immutable facts. Returning a version for a caller-supplied `satisfied` value is unsafe. The subject row must still authorize that principal and version under lock. Revocation must update the same subject row transactionally. The callback runs outside SQL locks, and caller-owned transactions refuse.

The example's host-policy fixture uses a closed profile and a fixed immutable assessment. Replace it with the application's own trusted registry and retained facts. A serialized scope, profile fingerprint, principal name, or snapshot ID is not authentication or evidence by itself.

Run the executable SQL example tests from the repository root:

```bash
vendor/bin/phpunit tests/Unit/V2/StateMachine/BusinessCommitTest.php
```

They exercise the actual example implementation on SQLite: lost acknowledgement, cancellation before/after completion, competing revisions, publication failure rollback, decision reuse, authorization revocation, foreign principals, transaction ownership, and profile substitution. The test does not require a provider or a running workflow worker.

For the separate-process race tests, use the repository's configured MySQL/MariaDB or PostgreSQL test database:

```bash
vendor/bin/phpunit tests/Feature/V2/V2BusinessCommitConcurrencyTest.php
```

The six cases observe the competing `SELECT ... FOR UPDATE` before releasing the winner. Both cancellation/completion orderings, both revocation/completion orderings, duplicate completion, and competing completion use separate processes and connections. SQLite and hosts without `pcntl` explicitly skip this race suite.

The publication table stores an intent. A real application still needs an idempotent dispatcher and consumer; this transaction does not atomically publish to an external broker. Separate business and DW stores communicate through the recorded commit activity and exact decision identity, without a distributed transaction. Activity retries must retain the original request; they must not repeat provider effects while recovering a lost business commit acknowledgement.

The SQL tests above cover business persistence. They do not establish provider admission/fencing, uncertain effects, retained child compensation, the complete two-integration application workflow, policy migration or resource budgets. The native example below separately exercises retained child compensation with a local SQL service.

## Native Order and Shipment compensation

The [Order workflow](OrderWorkflow.php) and [Shipment workflow](ShipmentWorkflow.php) demonstrate the retained-scope foundation with actual native workflow runs and recorded SQL activities. Run the complete example in the repository's configured disposable test database:

```bash
vendor/bin/phpunit tests/Feature/V2/V2NestedCompensationExampleTest.php
```

`prepare` owns separate reservation and preparation effects; `imported` owns an import effect. Both use the same state catalogue and the same Shipment workflow. Every SQL effect has an original operation ID, retained compensation descriptor and permanent absent/voided receipt. The SQL service reconciles those receipts before reissuing an operation. It models a synchronous application-owned service, not a carrier API or an external callback stop guarantee.

The Order registers its own obligations, then uses `DurableScope::reserveChild()` before admitting Shipment. The joint ownership policy pins the business subject, original parent run, state-entry generation, child policy and exact scope relationship. `ChildReservation::start()` confirms native admission. A separate recorded activity retains the authoritative admission receipt and hosting-state milestone. Waiting for the `finish` signal and awaiting that same child's result are later operations; they do not create a second child or compensation owner.

Shipment can finish while Order remains open. Order completion additionally checks the exact admission, original child run, retained SQL delivery evidence and application business outcome. A technically completed child with missing business evidence fails explicitly. This example does not convert an arbitrary technical terminal status into business success.

`NestedCompensationExample::requestCancellation()` first commits the application's forward-work fence, then calls the existing `ScopeRecovery` API. While Order is active, its native Saga compensates the registered child before earlier Order operations. After both runs close, native recovery uses the same original ledgers and preserves both terminal histories. The tests assert SQL compensation order, retained scope verification, no duplicated effects and cold replay without application writes. They also reject a different original parent generation.

The startup-window test stops after recorded application initialization but before root scope registration. Cancellation persists, yet recovery explicitly refuses the absent ledger. Resuming the genuine original owner registers its obligation through the normal recorded activity, observes the SQL cancellation fence and verifies non-admission. If that owner closes before initialization/registration can complete, the missing scope remains unresolved; the example never creates a synthetic empty scope.

The application retains `cancellation_requested` and scope resolution separately from the earlier business completion record. A successful technical cancellation or a queued recovery request is not a final business `Cancelled` decision. An application must retain its business policy for that final decision and for external uncertainty.

`bootstrap.php` loads the example classes. `NestedCompensationExample::configure()` installs the closed demo registries and a single trusted worker principal for a disposable local application. The test creates the application tables from `schema.php`, `provider-schema.php` and `application-schema.php`; native DW migrations remain unchanged. A real application must compose its own authenticated authorization/registry configuration rather than install the demo principal globally.

This executable compensation example is one integration scenario for [#1063](https://github.com/durable-workflow/workflow/issues/1063). It does not implement the complete StateMachine transition/rule adapter, profile migration, recursive child graphs, remote-provider reconciliation or all [#1069](https://github.com/durable-workflow/workflow/issues/1069) criteria. The retained-scope APIs remain experimental and require their own package/release qualification.
