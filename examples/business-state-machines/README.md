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

This example covers business persistence. Provider admission/fencing, uncertain effects, retained child compensation, the complete two-integration application workflow, policy migration, and resource budgets remain separate parts of the business state-machine work. The SQL tests do not establish those guarantees.
