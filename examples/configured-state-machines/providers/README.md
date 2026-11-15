# Independent synthetic provider journal

This disposable SQLite simulator supplies an external-effect journal for the
[consumer scenario specifications](https://github.com/durable-workflow/workflow/issues/1069#issuecomment-6889952106).
It is application example code, not a production payment/carrier SDK, a DW storage
adapter, or a claim that those scenarios pass. The existing version-one/two
Order/Shipment profiles remain unchanged. The
[provider-backed native example](../../provider-state-machines) uses this journal
for immediate capture and booking-only cancellation; the remaining consumer
variants still need their recorded native integration.

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

The original six-action fixture deliberately does not infer monetary settlement
from a command name. Applications must validate currency, amounts, quantities,
partial-refund totals, physical receipt/inspection, policy, authority and exact
provider evidence. The optional authorization extension below adds explicit
full-amount financial rules. Neither fixture implements real provider credentials,
asynchronous networking, stock management or carrier return semantics. These
provider controls alone do not qualify the native Payment child or the complete
33-scenario matrix.

Run its connection-isolation, restart, acknowledgement-loss, late-result and
identity controls with the repository dependencies:

```shell
vendor/bin/phpunit tests/Unit/V2/StateMachine/ProviderJournalTest.php
```

## Original authorization, capture, void and refund

For a new disposable provider fixture, call `installAuthorizationSupport()` after
`install()`. It adds a financial admission table; existing six-action callers and
the original four-table installation stay unchanged. This is synthetic provider
support for PAY-02, not the completed native authorization/capture workflow.

`AuthorizationTerms` pins a positive integer amount in minor units, a three-letter
uppercase currency and an absolute Unix `expiresAt`. `authorize` creates permission
to capture, not captured money. `capture-authorized` and `void-authorization` target
that exact original authorization and repeat its complete terms. A full
`refund-authorized` targets the exact confirmed capture and pins the same amount
and currency. The client, business subject, generation and operation identities
remain fixed. This version permits one capture and one full refund for an
authorization; it does not implement partial capture or partial refunds.

The provider clock is read only during a new provider admission, inside its own
transaction. An injected clock supports deterministic source tests. Capture
admission must precede the original expiry strictly; retry and replacement do not
extend it. A capture admitted before expiry can complete afterward using its
persisted admission and original identity. A cached confirmed capture remains a
historical fact after expiry, void attempts or refund. Expired permission cannot
create a new capture or fabricate an earlier admission.

A void request atomically fences future capture admission. If a capture is already
processing or unknown, the void remains unresolved until that original capture's
outcome is known. Confirmed capture requires a refund, while definitive capture
non-execution permits the original void. Void and refund are independent effects;
neither deletes the authorization or capture record. The provider's same-key
idempotency contract separates repeated command requests from committed effects.

All financial calls must still occur through recorded activities with the complete
host authorization described below. The existing `RetainedOperation` handler
continues to select only its original three forward/undo pairs. A versioned
financial handler, common native Payment lifecycle, both stock- and actual
recipient-delivery capture policies, and complete crash/race/installed qualification
remain required before PAY-02 is accepted. Booking or technical child completion
must never stand in for recipient delivery.

```shell
vendor/bin/phpunit tests/Unit/V2/StateMachine/ProviderAuthorizationTest.php
```

## Native retained-operation boundary

`RetainedOperation` implements the existing `ScopedOperation` contract. Register
it in the trusted handler registry and invoke it through a `DurableScope`; all
provider I/O then belongs in recorded native activities. Its portable descriptor
contains `forward` and `compensation` requests with closed identity/term fields.
The compensation must target that exact original operation, with the matching
action (capture/refund, book/cancel-booking, or reserve/release).

The mandatory host authorization callback must check the complete relationship
between the original native scope/run, business client/subject/generation,
registered descriptor, pinned policy and trusted worker principal before provider
SQL. Independent authorization of two unrelated owners is insufficient. The
simulator's client credential check does not supply this application policy.

An uncertain forward attempt performs lookup only; missing data cannot authorize
a new charge or booking. Processing/refused observations remain distinct from a
successful business milestone. Later changing facts still need their own recorded
snapshot activities; an earlier recorded processing result is not silently
replaced during replay.

Compensation first closes provider admission. A genuine permanent non-admission
or definitive forward refusal can establish absence under this simulator's
contract. An admitted processing/unknown forward remains unresolved. A confirmed
forward requires the original linked undo and its confirmed effect evidence;
processing, unknown, refused or unexecuted undo does not verify compensation.
The simulator guarantees same-key idempotency, so lost refund acknowledgements
can reconcile on a later native compensation attempt without a second refund.
Real adapters must establish their own provider contract before reissuing a call.

These adapter controls still do not constitute the native Payment child or the
complete Order/Shipment/Payment cancellation fixture. The application must use
the shared transition protocol, genuine child reservation/participation and
protected business commits; verified provider compensation alone does not set
the parent business state to Cancelled.
