# Original root startup and cancellation

This experimental source example gives the original reservation-first Order a
permanent root admission boundary. It requires the current source API and the
native root reservation migration. The published stable package does not include
it. Other recipes retain their existing entries and require their own integration
and qualification. In particular, pre-existing money, Cart leases and billing
periods require their original ownership and compensation contracts.

Install `business-state-machines/schema.php`, this directory's `schema.php`, and
its optional `root-schema.php` in the application's disposable source environment.
The trusted host selects the business subject grant and root entry before seed:

```php
$key = $terms->subjectKey();
$authority = new Authority(
    $businessDb,
    $authenticatedWorker,
    [$key],
    [$key => Catalogue::RESERVE_FIRST],
    reservedRoots: [$key],
);
$store = MachineStore::configure($app, $authority, $provider);
$store->seed($terms);

$identity = $authority->roots->identity($key);
$roots = app(RootReservationStore::class);
$reservation = $roots->reserve($identity, $authenticatedWorker);
$binding = $roots->start($reservation, $authenticatedWorker, [$key]);
```

The seed transaction retains the complete original root identity with the business
assignment and subject. Recovery reloads that identity. It preserves the original
business generation, definition, input, routing and start profile across process
loss and deployment. Forward admission still requires the original compatible
workflow definition. Closure treats an unavailable historical class as data.

The actual native root run and scope admission commit together. The provider
binding uses that canonical native instance as the children's parent and uses the
reserved business scope. Initialization checks the authoritative admission against
the actual native run. Historical entries keep their existing identity and policy
fingerprints. The new entry has a separate pinned business grant and pair-policy
version, and cannot adopt an existing ordinary business row.

## Cancellation

```php
$result = app(RootCancellationStore::class)->request($key, $originalBusinessRequestKey);
```

The authenticated host records the original business cancellation request and
fences root and child writers before closing native admission. The business
request key and content must remain unchanged on retry.

If the root was never admitted, its permanent authoritative closure permits a
startup cancellation only for the untouched original business generation. Earlier
actors, child subjects, decisions, publications or changed state/revision keep the
business outcome unresolved. The cancelled business state, decision and durable
publication commit atomically. The publication distinguishes a reserved run ID
from an admitted execution. It does not call an unopened scope verified.

For an admitted root, cancellation uses its genuine original scope. A live root
first receives cooperative cancellation. Reevaluate the same business request
after the reported native route progresses. A closed root can then use the normal
retained recovery workflow. Startup settlement requires that exact original ledger
to be verified; any registered work must finish compensation first. If the owner
initialized, the existing StateMachine cancellation owns the decision. When the
original native owner has closed and its original scope is verified, reevaluating
the request starts or adopts the canonical proof-only finalizer. It cannot take
over provider work. A completed business result requires the original matching
decision and publication, original scope, revision and native transition identity;
a mutable cancelled flag alone is insufficient. A late initializer cannot overwrite a committed startup cancellation.

A failed recovery reports `submit_fresh_authorized_request`. After addressing the
failure, the host can explicitly select another scope recovery request while
retaining the original business request:

```php
$result = app(RootCancellationStore::class)->request(
    $key,
    $originalBusinessRequestKey,
    $freshScopeRecoveryRequestKey,
);
```

The old failed execution remains retained. The recovery successor binds its exact
predecessor and original scope; the host does not choose a new root generation.
The API does not generate fresh recovery keys or repeat failed work automatically.
When the original root closes with an accepted request still unverified, the task
watchdog routes that same request to canonical recovery (#1205). That is not a
fresh key, and it does not repeat a failed recovery run.

These are host or recorded-activity boundaries. Calling them during workflow
replay or from a caller-owned transaction is refused before business storage.
The ordinary unreserved missing-root scenario remains unresolved. Complete
customer scenarios, broader recipe support, installed upgrades, pinned recovery,
rollback and publication require further qualification.
