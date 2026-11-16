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
continues to select only its original three forward/undo pairs. The optional
financial handler below needs separate registration. A common native Payment
lifecycle, both stock- and actual-recipient-delivery capture policies, and complete
crash/race/installed qualification remain required before PAY-02 is accepted.
Booking or technical child completion must never stand in for recipient delivery.

```shell
vendor/bin/phpunit tests/Unit/V2/StateMachine/ProviderAuthorizationTest.php
```

### Separate permission and captured-money obligations

`FinancialOperation` is a new optional `ScopedOperation` implementation. The host
registers distinct, versioned handler types with the trusted constructor stage
`authorize` or `capture`. Workflow input cannot choose that stage. Both use a closed
descriptor containing the four original `authorize`, `capture`, `void` and `refund`
requests. Validation checks their exact ownership, targets, original money/expiry
and four distinct idempotency identities before provider access.

The native Payment assembly must register the original authorization obligation
first, then the capture obligation, **before executing either forward operation**.
The mandatory host callback checks that actual paired registration, original
scope/run, complete descriptor, policy and worker authority before any provider
SQL. Descriptor validation and a callback supplied by an untrusted caller do not
provide that authority. Keep both handler versions available to replacement
workers and retained recovery.

Native LIFO then settles the captured-money obligation before the earlier
authorization obligation. Capture compensation fences the original capture;
unknown/processing stays outstanding, and confirmed capture requires the exact
original full refund. Authorization compensation also fences the exact future
capture. If the permission was unused, it requires the original void. If full
capture consumed it, the authorization obligation records that consumption while
the distinct capture obligation continues to own the required refund. Verifying
permission consumption alone does not verify the money obligation or business
cancellation.

An uncertain native forward attempt performs lookup only. Confirmed processing,
refusal and non-execution remain observed facts, never successful financial
milestones. Provider I/O belongs inside the recorded retained activities; neither
workflow replay nor pure conditions may call these methods directly.

The adapter tests exercise these contracts with synthetic host authorization.
`V2FinancialRetainedScopeTest` also executes recorded native tasks, checks both
original obligation rows before provider access, and recovers closed or failed
origins through the retained ledger. It covers voiding unused permission, refund
acknowledgement loss, original late capture, unknown refund, missing paired
registration and expiry between admission steps. Original terminal history stays
unchanged, and replay performs no provider I/O. Its capture-selection boolean is
only a scope-fixture control; neither stock nor recipient delivery is inferred.
Actual Order/Payment/Shipment composition, both capture-trigger policies and full
business-cancellation qualification remain required integration work.

```shell
vendor/bin/phpunit tests/Unit/V2/StateMachine/RetainedFinancialOperationTest.php
vendor/bin/phpunit tests/Feature/V2/V2FinancialRetainedScopeTest.php
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

## Original physical Shipment evidence

The optional `$provider->shipments()->install()` extension keeps the original four-table
installation and six actions unchanged. Register a closed `ShipmentDescriptor`
before admitting its separate `ship-book`, `ship-cancel-booking`, `request-return`
and `restock` operations. The graph pins the original client, business generation,
SKU, quantity, carrier and all four operation identities. Registration cannot
replace a return or reopen a cancelled booking's handover fence.

Only the synthetic carrier/warehouse fixture calls `$provider->shipments()->record()`.
It records original handover, recipient delivery, loss, transport damage,
non-collection, warehouse receipt and inspection events. Application commands
and workflow signals cannot supply these facts. Duplicate equivalent events
retain the first event; conflicting quantities or reused identities refuse.
An admitted uncertain booking cancellation fences later handover. After actual
handover, cancellation of a booking refuses and return remains separate work.

`$provider->shipments()->inspect()` supplies raw operation outcomes and the physical evidence.
It must be called inside a recorded, original-owner-authorized host activity.
Booking does not establish delivery. An accepted return request does not establish
receipt, inspection, acceptance or restocking. Inspection separately records
received, accepted and saleable quantities; it does not itself restock goods.
This first full-parcel restock operation requires the complete original quantity
to be inspected as saleable. Partial or unsuitable goods cannot silently become
a full restock. Historical delivery and accepted requests survive later return
or loss; cached effects require their original persisted admissions. The native
full-Shipment graph additionally pins a distinct `deduct-stock` request. Carrier
handover does not itself deduct inventory. That original request must confirm
before its graph can restock the same quantity. Compensation fences a missing
future deduction, waits for an admitted uncertain deduction, and never creates
restocking for stock that was definitively not deducted.

These are synthetic provider source controls. The complete native Shipment
consumer policies, global warehouse balances, partial restocking and the full
consumer matrix remain unfinished. The experimental native host in
`../../provider-state-machines` supplies recorded physical inspection, explicit
deduction and both capture-trigger paths; its installed qualification remains open.
No real carrier or warehouse behavior is qualified by these methods.

`ShipmentOperation` supplies an optional retained handler for the first
full-suitable-return policy. A mandatory host callback checks the original
native owner, scope, child admission and complete descriptor before provider
access. Before handover it requires confirmed original booking cancellation.
After handover it requires the original return, complete warehouse acceptance
as saleable goods and confirmed original restocking. A lost acknowledgement
reconciles those same operation keys. Unknown booking, return or restock outcomes
remain outstanding. Partial, unsuitable and lost goods require their separately
selected settlement policies; this handler does not settle those variants or
perform a refund. Its adapter tests do not constitute native host authorization
or complete Shipment-child qualification.

```shell
vendor/bin/phpunit tests/Unit/V2/StateMachine/ProviderShipmentTest.php
```
