# Provider-backed Order, Payment and Shipment machines

Work in progress for [#1069](https://github.com/durable-workflow/workflow/issues/1069).
The recorded SQL host, workflow composition and initial native tests are
implemented. The original reservation-first policy, failed-child-recovery
routing correction and controlled-clock fixture passed the complete source
backend cycle in #1161. The common checkout policies below are a subsequent
source increment. This directory remains experimental
source, not an installed-package guide.
Published Workflow 2.28.1 does not contain these retained-scope or StateMachine APIs.

The authorization recipes use one Order route (`received -> ready -> fulfilled`),
one Payment route (`received -> authorized -> captured`) and one full Shipment
route (`received -> booked -> in-transit -> delivered`). They are experimental
source and require complete qualification before publication. The original
immediate-capture profiles and booking-only child remain available.

For `authorization-after-stock`, the original reservation permits capture before
Order becomes ready. For `authorization-after-delivery`, ready requires the
original reservation and valid authorization; it does not imply captured money.
The trusted application requests fulfillment with the Order's `fulfill` signal.
That request provides no stock, financial or delivery evidence.

Full Shipment registers its original owner and whole physical obligation before
provider access. It confirms booking, observes actual carrier handover, records
the separate original stock-deduction effect, then observes recipient delivery.
Booking and technical child completion cannot substitute for those facts. Order
can observe the protected delivery publication while Shipment is still open.
The delivery-capture policy then records a protected instruction to the same
original Payment and waits for its captured business publication. Neither child
waits for the other's technical completion.

The complete runnable assignment supplies an absolute `fulfillmentDeadline`
independently of authorization expiry. The original deadline bounds fulfillment
requests and physical waits; replay and worker replacement do not restart it.
An older financial-preparation assignment without that field cannot dispatch
full fulfillment. Notifications use stable original publication/target keys and
request authenticated rereads. Signal payloads provide no authority.

After handover, cancellation or a permitted full return keeps the original
Shipment responsibility outstanding until actual warehouse receipt, suitable
acceptance and required original restocking confirm. Only then may the parent
settle the original captured Payment and release its original reservation.
Historical delivery remains visible. Unknown outcomes and missing original
reservation receipts never establish successful settlement or permission to
create another stock deduction.

The source cases are `V2AuthorizedPaymentMachineTest`,
`V2PhysicalShipmentMachineTest` and `V2DeliveryCaptureMachineTest`. They are
synthetic recorded-task fixtures, not installed-package, real-provider or full
33-scenario acceptance. The remaining payment, subscription, partial-stock,
return/replacement, crash/race and installed/publication criteria remain open.

`FinancialInspectionActivity` requires an explicitly bound
`FinancialInspectionStore`. Its mandatory host callback must authorize the
original native actor, business generation, financial graph and paired retained
obligations before provider SQL. The store persists the first observation by
original activity identity. An acknowledgement retry returns that observation
after reauthorization; it does not refresh its time or extend authorization
expiry. A later inspection has a distinct recorded identity. Raw unknown,
processing and refused observations remain available alongside confirmed facts.
These observations grant no authority to execute a capture and do not replace
the live protected business admission check.

The authorization and capture obligations are registered before provider
inspection. `RegisteredObligation::compensate()` invokes the existing native
scope request and verified compensation activity, so a transition can resolve
these predeclared owners before its `compensate()` returns. The original
automatic Saga callbacks remain present and observe the same verified rows.
Reverse ordering and unknown-result refusal still apply; the handle introduces
no separate compensation executor.

The first selected policy uses an original stock reservation, immediate full
payment capture and carrier booking before handover. Payment is a distinct
machine with its own capture transition; booking is a distinct Shipment
boundary. A booked parcel has not been delivered. All three machines use the
same transition protocol, immutable recorded facts and protected commits.
The existing configured-machine profiles and fingerprints remain unchanged.

The required compensation sequence is confirmed cancellation of the original
booking, confirmed refund of the original capture, then confirmed release of
the original reservation. A cancellation request or processing refund is not
settlement. The independent synthetic provider journal distinguishes request
attempts from effects and remains separate from DW history.

`OrderTerms` is an application assignment persisted before execution. Original
client, subject, business generation, integer amount, currency and quantities
remain pinned. Provider operation keys do not depend on task/attempt IDs.
`BindingPlan` binds each state entry, native owner, child policy, input and
retained scope pair together; a trusted live ownership callback is still
mandatory before native storage. Portable descriptors alone grant no authority.

## Disposable application assembly

Load `bootstrap.php` in the Laravel client and worker bootstrap, and include
`provider-machines` in the application's native namespace allowlist. In a fresh
disposable database, install `../business-state-machines/schema.php` followed by
this directory's `schema.php`. They create example application tables, not DW
package migrations. Keep the synthetic provider's SQLite file separate from the
application and DW connection.

The application supplies the principal and allowed original business keys from
trusted configuration. Workflow arguments select none of those permissions.
Configure the same original handler and policy versions in every replacement
worker. `configure()` assembles this example's registries; use a disposable
application configured for this example rather than replacing another live
application's handlers.

```php
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\{Journal, Request, Status};
use DurableWorkflow\Examples\ProviderStateMachines\{Authority, Catalogue, MachineStore, Order, OrderTerms};
use Illuminate\Support\Facades\DB;
use Workflow\V2\WorkflowStub;

$terms = new OrderTerms('shop-a', 'purchase-1', 'business-generation-1', 1499, 'USD', 'sku-1', 2);
$provider = new Journal(new PDO('sqlite:/owned/disposable/provider.sqlite'), 'shop-a');
$provider->install(); // Once, when creating the fresh provider fixture.
foreach (['order', 'payment', 'shipment'] as $role) {
    foreach ($terms->descriptor($role) as $command) {
        $provider->plan(Request::fromArray($command), Status::Confirmed);
    }
}
$host = MachineStore::configure(app(), new Authority(DB::connection(), 'provider-worker', [$terms->subjectKey()]), $provider);
$host->seed($terms); // Once, before admitting this original business generation.
$order = WorkflowStub::make(Order::class, 'provider-order-' . $terms->subjectKey(), Catalogue::NAMESPACE);
$order->start($terms->subjectKey());
```

Use the application's ordinary native workers to execute the recorded tasks.
The Order remains open after the confirmed booking so that the first example
can request business cancellation before handover. The `finish` signal closes
that booking-only orchestration; it does not assert physical handover or delivery.

`CancellationStore::request()` atomically persists the business intent and fences
the original Order and child commit rows, then requests native retained recovery.
Its acknowledgement is not settlement. A processing or unknown refund keeps
reservation release blocked. After the original scope is verified, a closed
Order can use `CancellationStore::finish()` to start the separate proof-only
finalizer. That workflow owns no provider effect or new compensation scope and
cannot take over original forward work. Original Order and child histories and
provider operation keys remain unchanged.

Child recovery executes independently of its parent's compensation activity.
If the child's ledger is still unresolved when that activity exhausts its
finite attempts, the original native run can fail before the child finishes.
The parent scope stays requested and earlier compensation remains blocked.
After inspecting the child's verified ledger or a confirmed late provider
result, submit a fresh authorized cancellation request key to resume the
original retained scope. Reusing the failed request key does not select a new
recovery route. The finalizer still requires the original parent scope to be
verified; a completed child recovery alone cannot settle the Order.

If the native root closes before genuine recorded owner registration, a missing
scope stays unresolved. A host lookup, cancellation flag or terminal technical
status never manufactures an empty scope or proves non-execution.

The source fixture is `tests/Feature/V2/V2ProviderStateMachineExampleTest.php`.
Run it through the repository's documented `composer feature` command in a
disposable source test environment. The provider's committed-effect journal
proves ordering and duplicate-effect counts independently of DW history; replay
must perform no provider I/O. The fixture controls native time explicitly so
database latency does not change the ordinary-ordering scenarios. A separate
case deliberately leaves child recovery queued until the parent's finite
attempts expire, then verifies blocked earlier work and fresh retained recovery.
These are synthetic source tests, not elapsed-time,
installed-package, real financial or physical-inventory qualification.

### Process loss during retained compensation

`V2AdvanceBalanceColdRecoveryTest` stops a fresh PHP process at two cuts: after
the original bank refund is durably verified, and after the provider commits it
but loses the acknowledgement before verification. The remaining Payment and
Order compensation is still outstanding. A second fresh process uses the same
application database and independent provider journal to finish the original
cancellation. Both cuts require the original scope, obligation and refund
identities, unchanged history prefix, and one committed bank-refund effect.
Verified refunds must not receive another command; an unacknowledged result may
be retried or reconciled through the same original operation.

This cut uses the advance-before-handover policy before Shipment dispatch. The
fixture kills the first process with `SIGKILL`, carries its controlled native
clock into the replacement, and runs the existing native test-task driver. It
requires POSIX signals and declares a skip when they are unavailable. It is a
source process-loss control; ordinary queue-worker execution, installed
recovery, and the other interruption points in the consumer matrix still need
their own qualification.

This first full-refund, pre-handover policy does not implement the remaining
payment, subscription, partial-stock, physical return or replacement variants in
the [consumer acceptance matrix](https://github.com/durable-workflow/workflow/issues/1069#issuecomment-6889952106).
Those requirements remain open. These source fixtures do not qualify a release,
installed upgrade, rollback, real provider operation or production readiness.

## One checkout route, two prerequisite policies

These common checkout policies are experimental source, not installed-package
or complete consumer-matrix acceptance. The original version-1 profile and Order
class remain available with their original fingerprints and recorded commands.
Select a new recipe only for a fresh original business generation.

`COMMON_RESERVE_FIRST` and `PAYMENT_FIRST` use the same version-2 Order definition,
states, child slots and route: `received -> ready -> booked`, with a proof-only
cancellation edge from each of those states. `ready` means that both original
payment and stock prerequisites are confirmed. While it is `received`, the
original Payment publication and provider observations expose the financial
facts; that state never implies that no money was captured.

The `prepare` transition pins a different policy action. One recipe reserves
stock before capture; the other confirms capture before trying stock. Both run
through `CheckoutOrder` and the same four-operation transition protocol. The
application selects the recipe from trusted subject grants, never workflow
arguments:

```php
use DurableWorkflow\Examples\ProviderStateMachines\CheckoutOrder;

$authority = new Authority(DB::connection(), 'provider-worker', [$terms->subjectKey()], [
    $terms->subjectKey() => Catalogue::PAYMENT_FIRST,
]);
$host = MachineStore::configure(app(), $authority, $provider);
$host->seed($terms);
$order = WorkflowStub::make(CheckoutOrder::class, 'provider-order-' . $terms->subjectKey(), Catalogue::NAMESPACE);
$order->start($terms->subjectKey());
```

The original grant binds the selected profile and joint child policy before
protected terms decode. Replacing the worker with another recipe refuses that
generation. Payment and booking keep their existing child policies and pinned
original ownership; the new common Order definition changes no historical one.

`DurableScope::register()` records an obligation and its native compensation
callback before dispatch. Its `RegisteredObligation::execute()` method later
uses the existing engine-validated forward activity. Registration performs no
provider operation. The existing `DurableScope::execute()` still records the
same registration then forward commands. Checkout registers stock before
Payment in both recipes, so compensation remains booking cancellation, refund,
then reservation release even when capture preceded the stock command.

A known processing reservation waits inside the original admitted `Execute`
operation for `reconcile-stock`. Each observation is a recorded activity;
repeated signals neither re-admit that transition nor repeat the reservation or
charge. Unknown outcomes use the original uncertain native activity and
provider identity. They never become stock refusal merely because of a missing
lookup or expired retry budget.

A definitive stock refusal closes that original provider admission and records
a fixed original-run/operation receipt. Payment-first cancellation remains
financially unsettled until the original captured payment is verifiably
refunded. It creates neither a booking nor a fictitious reservation release.
The protected cancellation publication carries that stock-refusal receipt;
a technical cancellation result alone is insufficient. A later confirmed
refund can resume the original retained scope through a fresh authorized
request and the existing proof-only finalizer.

The focused source cases are `V2PaymentFirstProviderMachineTest.php` and
`V2CommonReserveFirstProviderMachineTest.php`; the original seven cases remain
in `V2ProviderStateMachineExampleTest.php`. These use synthetic providers and
controlled native time. All remaining customer policy variants and installed,
upgrade, rollback and publication gates remain open.

## Refund outcomes and retained recovery

A confirmed refund is financial evidence; processing, Unknown and definitive
refusal are distinct outstanding outcomes. They keep the original Payment
obligation unresolved, block stock release and prevent cancellation publication.
The original capture remains a historical fact.

`V2ProviderRefundOutcomeTest.php` exercises Unknown and refused refunds in the
payment-first checkout. An authoritative late confirmation can settle the
original Unknown refund through fresh authorized recovery. A fresh recovery
request cannot turn a definitive refusal into a successful refund. This
example's refusal policy leaves the obligation outstanding for authorized
handling; it does not select a different refund identity or silently replan the
provider result.

The simulator's same-key idempotency permits repeated request attempts while
returning the original outcome. The cases count those requests separately from
committed effects and preserve both closed Order and original Payment histories.
They are synthetic source controls, not a claim about a real provider's retry
contract or installed-package acceptance.

## Incoming bank-payment observations

`IncomingPaymentJournal` is a separate synthetic bank-side receipt journal. It
keeps the original receivable, individual transfer identities, integer amounts,
currency and fixed deadline. Partial payment, overpayment and late receipts
remain distinct facts. An empty lookup or an expired deadline does not establish
that money cannot arrive later.

`IncomingPaymentInspectionActivity` records those facts for the original native
owner. Its host-supplied authorization must relate the run, retained money duty,
business grant and exact receivable before protected storage or provider access.
Reusing the same activity returns its first persisted observation after current
authorization; a new activity can observe later receipts. Replay uses recorded
activity results.

`V2IncomingPaymentInspectionTest.php` exercises this observation boundary. Its
technical workflow can complete while the original incoming-payment duty remains
registered and unresolved. These controls do not implement bank refunds, close
future incoming admission, admit fulfillment or complete the common Payment
route. Those business integration and recovery requirements remain necessary;
an incoming transfer must never be presented as a card authorization or capture.


## Retained incoming-money cancellation

`IncomingPaymentProvider` keeps optional bank setup and its receipt journal
separate from the existing command engine, on the same provider-owned connection.
Install the original command journal before installing this optional support.

`IncomingPaymentOperation` registers the original receivable through a native
retained activity. The host must authorize the complete original business
assignment, run, scope and obligation before provider storage. Register the
obligation before executing it. Establishing a reference is not a payment
confirmation and must not admit fulfillment.

The provider contract is pinned when the reference is first registered. The
default `incoming-unfenced-v1` contract keeps accepting original transfers after
the business deadline. Cancellation can refund known incoming money but remains
unresolved while further transfers are possible. Expiry and an empty lookup do
not close that obligation.

The optional `synthetic-incoming-atomic-close-v1` contract is an explicit
simulator capability. Its close command atomically rejects new transfers,
including a never-opened original reference, and retains the exact admitted
receipt inventory. Repeated confirmation of an already admitted transfer remains
idempotent. A reference created with the unfenced contract cannot acquire this
capability during cancellation. A real bank adapter may use this branch only
when its original provider contract proves the same complete admission boundary.

Cancellation reconciles each original transfer and uses a stable refund key
from its original scope, obligation and transfer identity. Processing, unknown,
not-executed and refused refunds remain unsettled. The simulator's same-key
idempotency permits recovery after a lost acknowledgement without another
committed refund. Verified settlement requires both the provider fence and
confirmed refunds for the complete receipt inventory; it preserves gross
incoming receipts as historical facts. Other previously admitted partial refunds
can make the full-transfer refund refuse rather than reimburse money twice;
partial-return allocation remains unfinished.

`V2IncomingPaymentRetentionTest.php` runs the native registered-obligation and
closed-origin recovery paths. The replacement-provider case reopens the same
provider database in the same test process; it is not a cold-process or installed
qualification. The common route below adds native bank prepayment integration;
expired cart reservations, installed qualification and the complete customer
acceptance matrix remain unfinished.

## Fixed Cart leases before a formal Order

`CartCheckout` is an opt-in bank-funded purchase route using the same Order,
Payment and Shipment definitions. A purchase can hold stock before its formal
Order exists. `CartPlan` pins the original Cart, purchase owner, stock, initial
creation/expiry times, distinct late reservation and payment observation deadline.
The expiry does not reset when a worker restarts or an Order is created.

Install `cart-schema.php` alongside the host schemas, and install the provider's
Cart inventory and physical Shipment schemas on the same separate provider
connection as its original incoming-payment journal. Explicitly grant Cart mode
for the purchase key; input data cannot select this mode for another purchase:

```php
$authority = new Authority($host, 'provider-worker', [$key], [$key => FundingCatalogue::BANK], [$key]);
$store = MachineStore::configure($app, $authority, $provider, $incoming);
$store->seed($terms, $cartPlan); // The terms and immutable CartPlan must have the same original owner.
$purchase = WorkflowStub::make(CartCheckout::class, 'provider-order-' . $key, Catalogue::NAMESPACE);
$purchase->start($key);
```

The host purchase projection is not a formal Order. Only confirmed original
payment plus a factual stock hold permit a `provider_cart_orders` association.
That write shares the original cancellation lock and retains the original
purchase/run, scope, Payment child, reservation and future Order reference.
The same transaction pins the complete original Order receipt on the purchase.
Reads require that pin and the original paid publication. They do not close
child admission or reconstruct missing evidence.
The Cart's initial and late stock duties are registered before Payment so reverse
compensation refunds money before releasing paid stock.

Unpaid initial expiry releases only the original lease, leaving its distinct
payment observation window open. A full authoritative receipt before expiry
preserves that lease even if the Payment publication is delayed. Formal Order
creation still waits for the original protected publication. A partial receipt
does not extend the original reservation. Late confirmed money requires a new reservation
after positive original release; a prior availability observation is no promise.
Definitive refusal requests the original refund without creating an Order.
Unknown outcomes remain uncertain and wait for `reconcile-stock` or the original
payment milestone. Reconciliation observes the same provider key and native duty;
it cannot substitute a new purchase, move compensation ownership or invent a
refund. The fixed payment deadline still bounds forward admission.

Before handover, release also fences late carrier handover. After handover,
physical return, receipt, saleable inspection and one confirmed restock are
required before those goods become available again. A missing/damaged return is
not a stock receipt. `CartReservationProviderTest.php` covers provider fences and
physical stock facts; `V2Cart*MachineTest.php` exercises the native routes with a
controlled fixture clock and explicit native task driver. Full backend,
cold-process, installed and complete customer-matrix qualification remain
separate requirements; this is not a published Cart integration.

`V2CartOrderAssociationConcurrencyTest.php` exercises both cancellation/Order
association orderings on PostgreSQL. It observes the losing process waiting on
the original purchase row lock, then verifies retained identity and factual
refund-before-stock-release compensation. These are source host-boundary races,
not installed-worker qualification.

## Common funding and physical fulfillment

`FundingCheckout` and `FundingPayment` use one new definition per machine type
across the four base recipes in `FundingCatalogue::baseRecipes()`: immediate direct capture,
authorization with capture after stock reservation, authorization with capture
after recipient delivery, and incoming bank prepayment. Historical definitions
and profiles above remain available for their original executions.

The Order route is `received -> ready -> fulfilled`, with verified cancellation
available from each state. Payment uses `received -> ready -> collected -> paid`.
Readiness is the selected financial prerequisite, collection is the original
payer payment, and paid is confirmed merchant receipt. The synthetic direct
acquirer confirms merchant receipt with its original capture effect; the bank
recipe receives directly into the merchant account. Neither contract implements
carrier collection or subsequent COD remittance.

For bank prepayment, include the original `IncomingPaymentDescriptor` and
explicit admission contract in `OrderTerms`. Assemble one
`IncomingPaymentProvider`, install its optional tables after its
`commands->install()`, and pass that exact `commands` object and provider as the
third and fourth arguments to `MachineStore::configure()`. Configure Shipment
support on the same command journal. Give `Authority` the selected recipe for
the original subject key, seed its terms, and start `FundingCheckout` with that
key. Workflow inputs and signals cannot choose the recipe or grant ownership.

The Payment child registers its complete retained money graph before opening
the bank reference or reading provider facts. `incoming-observed` is a wakeup
hint; the recorded activity must read the original provider receipts. Partial
payment cannot reserve stock or admit fulfillment. The fixed original bank
deadline applies to incoming confirmation; worker restart cannot extend it.
Gross receipts remain historical after refunds, while the live business and
retained-scope fences prevent cancelled money from admitting forward work.

Order observes original protected Payment publications instead of waiting for a
mutually dependent terminal child. The delivery-capture recipe coordinates the
actual Shipment delivery publication, original capture instruction and final
Payment publication. The selected Shipment is the same full physical route for
all four recipes. Its original return, warehouse acceptance and deduction
responsibilities remain ahead of refund and reservation release.

Run the native source scenarios with
`vendor/bin/phpunit tests/Feature/V2/V2CommonFundingMachineTest.php tests/Feature/V2/V2BankFundingMachineTest.php`. They do not
substitute for the complete customer matrix, cold-process failures, installed
upgrade/recovery/rollback checks or a published release.

## COD collection and merchant remittance

`CodFundingPolicy::BEFORE` and `CodFundingPolicy::AFTER` extend those same Order,
Payment and full Shipment definitions and routes. The first requires complete
on-time payer collection before recipient handover; the second requires actual
recipient handover before collection. A distinct `CodFundingPayment` workflow
type declares `cod-observed` without changing historical Payment signal schemas.
No COD transition authorizes or manufactures a card capture.

The original `OrderTerms` pins a `CodDescriptor`, its complete original Shipment
graph, collection point, exact money, collection deadline, remittance deadline
and handover policy. Configure `CodPaymentProvider` with `physicalContract: true`
on the provider-owned connection. Install `commands`, Shipment support, then COD
support on a fresh provider database, and pass that exact command journal and
`cod: $cod` to `MachineStore::configure()`. The host refuses another provider
assembly or a recipe whose handover policy differs from the original terms.

Payment registers owner, merchant-settlement and payer-reimbursement duties
before opening collection. Carrier readiness permits stock reservation and
physical fulfillment; it does not prove payer payment. The provider serializes
collection and recipient handover under the original carrier contract.
`cod-observed` is only a wakeup: a recorded, authorized inspection rereads the
complete original receipt and carrier-order inventory. Partial or spoofed
payment cannot advance collection. Actual payer collection permits `collected`,
while `paid` and Order fulfillment require the distinct merchant remittance.

Passing the original remittance deadline preserves the carrier/merchant debt
and waits for another original observation. It does not reset the deadline,
invent a refund or claim settlement. The optional original
`CodJournal::CLOSABLE` simulator contract can atomically fence future payer
collection. The default open contract cannot acquire that capability during
cancellation. Original known collections use stable payer-refund keys;
processing, refusal, uncertainty and lost acknowledgements remain explicit.
Even confirmed customer refunds do not erase gross collection history or prove
the independent merchant/carrier balance settled. Those outstanding duties keep
dependent parent compensation blocked. Once the original collection inventory is
atomically fenced, verified remittance of every actually collected amount can
settle the separate merchant duty. Partial remittance or an open collection
reference remains unresolved, even when all currently known receipts were paid.
Physical return still requires original warehouse receipt, suitable inspection
and restocking before money recovery.

Run `vendor/bin/phpunit tests/Feature/V2/V2CodFundingMachineTest.php tests/Feature/V2/V2CodMerchantSettlementTest.php` and
`vendor/bin/phpunit tests/Unit/V2/StateMachine/RetainedCodPaymentOperationTest.php`
for the native route and direct retained-operation controls. These are synthetic
source fixtures, not real carrier integrations, cold-process/installed proofs,
full 33-scenario acceptance or release qualification.

### Bank advance and carrier-collected balance

`AdvanceBalancePolicy::BEFORE` and `AdvanceBalancePolicy::AFTER` keep the same
Order, Payment and Shipment definitions and routes as the other funding
profiles. The original `AdvanceBalanceTerms` binds two distinct amounts and
payment identities to one purchase: a bank advance and a carrier-collected
balance. Their sum must equal the original purchase amount, with matching
currency, owner, generation and Shipment. Neither portion can be replaced by
another payment mode or reconstructed from a reduced Order assignment.

Configure the original command journal, incoming-payment provider and
physical-contract COD provider from the same `AdvanceBalanceProvider` instance.
Payment registers the merchant, bank-refund and payer-refund duties before
opening either payment reference. A separate native Payment type declares both
observation signals, preserving the existing funding types and signal schemas.
Signals only request recorded inspections of the original provider receipts.

In these two profiles, the full original bank advance permits stock reservation
and Shipment preparation. A smaller advance does not. Carrier collection obeys
the original before/after-handover contract and moves Payment to `collected`
only when the required balance is collected. Order fulfillment and Payment
`paid` additionally require the carrier's separate merchant remittance. Partial
remittance and an expired remittance deadline preserve the outstanding debt.
Neither a finished Shipment nor a payment notification proves that debt paid.

The advance, collection and remittance deadlines remain separate fixed values.
Passing the advance deadline after its timely receipt does not expire the later
balance stage. Cancellation targets each original receipt with its own stable
refund identity; carrier balance and bank advance are reconciled separately.
An original closable reference can fence later receipts. An open reference or
an unknown refund outcome retains the unresolved obligation instead of claiming
that no later payment is possible.

Run `vendor/bin/phpunit tests/Feature/V2/V2AdvanceBalanceMachineTest.php tests/Feature/V2/V2AdvanceBalanceRefundTest.php` for the
native staged-payment routes and cancellation, and
`vendor/bin/phpunit tests/Unit/V2/StateMachine/AdvanceBalanceProviderTest.php`
for original portion and refund controls. These synthetic source cases do not
complete PAY-05, its interruption/concurrency coverage, the full customer
matrix, installed recovery/upgrade acceptance or publication qualification.

### Subscription period contract preparation

`BillingPeriod` pins one original subscription generation, period, amount,
currency, start, end and payment deadline. A service-only period has no Order
or Shipment. Where the application has a genuine Order for the period, its
`OrderTerms` must retain the same client, period generation and money. The
association is part of the immutable assignment fingerprint; it is not a
provider payment identity or an admission receipt. This initial period
profile selects immediate capture. An associated Order cannot substitute an
authorization, incoming transfer, COD or split-payment policy.

The original period produces distinct capture and refund identities. A later
period cannot refund an earlier period's charge, and changing the charge terms
cannot reuse a committed provider key. These are business identities; retries,
native attempts and worker replacement do not select new ones.

`BillingPeriod::graceDeadline($startsAt)` fixes the example interval at ten
elapsed UTC days. Configure a different positive integer interval explicitly
before assignment. The cutoff is exclusive: confirmation exactly at the
deadline is late. Access during grace is an explicit policy option. This
example requires a separate decision for late-payment reactivation and never
automatically enables an expired period. Access ends at the fixed period end.
Payment-first never grants unpaid grace access. `BillingPeriodPolicy` preserves
the common `provider.payment` definition and its `received`, `ready`,
`collected` and `paid` factual meanings for both associations.

This is contract preparation for PAY-06/SUB-01 through SUB-04. The access
projection takes trusted observations; it is not a recorded host authorization,
provider receipt, entitlement write or native child admission. The current
Order-specific `MachineStore` and `Authority` cannot be used to run a
service-only period. A real period host boundary, authoritative timed payment
inspection, atomic original-period assignment/admission, cancellation of
future billing, retained settlement, native replay/process-loss tests and
installed qualification remain unfinished. Cancelling future billing must
not be interpreted as refunding previously paid periods.

Run `vendor/bin/phpunit tests/Unit/V2/StateMachine/BillingPeriodTest.php` for the
fixed-boundary, substitution and independent provider-journal controls. They
do not complete the subscription scenarios or the full customer matrix.
