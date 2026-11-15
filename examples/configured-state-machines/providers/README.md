# Independent synthetic provider journal

This disposable SQLite simulator supplies an external-effect journal for the
[consumer scenario specifications](https://github.com/durable-workflow/workflow/issues/1069#issuecomment-6889952106).
It is application example code, not a production payment/carrier SDK, a DW storage
adapter, or a claim that those scenarios pass. The existing version-one/two
Order/Shipment profiles remain unchanged. Payment-child orchestration and the
full consumer matrix still need their recorded native integration.

Create a new, separate SQLite file and connection, load `bootstrap.php`, construct
`Journal($pdo, $trustedClient)`, and call `install()` once. Never pass the DW or
business database connection. No provider operation reads DW history, task IDs,
business state, or the other machine's private state.

The application supplies an original client, business subject, generation and
operation key. `Request` pins the action, portable command terms and, for undo,
the original operation of that same owner. A task retry does not change any of
these values. Substituted terms or cross-owner compensation refuse. The trusted
client is provided by the fixture bootstrap, not selected by a command.

The fixture's `plan()` selects an operation result before dispatch. `attempt()`
records a command attempt separately from the uniquely committed effect.
`lookup()` observes the original command; missing data is **unknown**, without a
non-admission receipt. A planned acknowledgement loss throws only after the
independent provider transaction commits. A rolled-back application transaction
or a fresh worker process cannot erase that effect.

Processing, unknown, definitive non-execution, refusal and confirmed completion
are distinct. Provider-side `resolve()` can finish an admitted processing or
unknown operation, including after admission closes. `closeAdmission()` prevents
future dispatch atomically; only a genuinely unadmitted command gets the permanent
not-executed receipt. Closing an already admitted command does not prove absence
or finish its compensation. An explicit new plan can permit a new attempt only
after definitive non-execution while admission remains open; there is no retry
loop or deadline reset.

Refund, booking cancellation and reservation release are separate operations
linked to the original confirmed capture, booking or reservation. They append
their own effects without rewriting the historical forward result. A refund
cannot confirm against an unknown capture or a booking under the same owner.
The provider-side `provider_requests`, `provider_operations` and `provider_effects`
tables expose attempts, outcomes and actual committed effects independently of
DW's recorded results.

The simulator deliberately does not infer monetary settlement from a command
name. Applications must validate currency, amounts, quantities, partial-refund
totals, physical receipt/inspection, policy, authority and exact provider evidence.
It does not implement real provider credentials, asynchronous networking, payment
authorization/expiry, stock management or carrier return semantics. In particular,
these source controls do not qualify the Payment child, the agreed booking-cancel
→ refund → reservation-release ordering, or the complete 33-scenario matrix.

Run its connection-isolation, restart, acknowledgement-loss, late-result and
identity controls with the repository dependencies:

```shell
vendor/bin/phpunit tests/Unit/V2/StateMachine/ProviderJournalTest.php
```
