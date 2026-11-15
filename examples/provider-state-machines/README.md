# Provider-backed Order, Payment and booking machines

Work in progress for [#1069](https://github.com/durable-workflow/workflow/issues/1069).
The recorded SQL host, workflow composition and initial native tests are
implemented. Ordinary and lost-acknowledgement pre-handover cancellation cases
pass. Broader recovery tests exposed a failed-child-recovery routing gap; its
correction and complete source/backend qualification are in progress. This
directory remains experimental source, not an installed-package guide.
Published Workflow 2.28.1 does not contain these retained-scope or StateMachine APIs.

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

If the native root closes before genuine recorded owner registration, a missing
scope stays unresolved. A host lookup, cancellation flag or terminal technical
status never manufactures an empty scope or proves non-execution.

The source fixture is `tests/Feature/V2/V2ProviderStateMachineExampleTest.php`.
Run it through the repository's documented `composer feature` command in a
disposable source test environment. The provider's committed-effect journal
proves ordering and duplicate-effect counts independently of DW history; replay
must perform no provider I/O. These are synthetic source tests, not elapsed-time,
installed-package, real financial or physical-inventory qualification.

This first full-refund, pre-handover policy does not implement the remaining
payment, subscription, partial-stock, physical return or replacement variants in
the [consumer acceptance matrix](https://github.com/durable-workflow/workflow/issues/1069#issuecomment-6889952106).
Those requirements remain open. No native scenario, release, installed upgrade,
rollback, real provider operation or production readiness is qualified here.
