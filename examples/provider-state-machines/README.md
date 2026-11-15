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
