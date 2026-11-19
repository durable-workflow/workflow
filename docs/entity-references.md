# Domain entity references in activities

This experimental embedded API lets queued and local PHP activities receive a
domain entity loaded by the application. Workflow history records its logical
type, identifier and reference version. The entity class implements no Workflow
or Laravel interface.

Choose the argument that expresses the activity's contract:

- Pass an identifier when the activity should own the lookup explicitly.
- Pass an Avro-compatible value when the activity needs the exact recorded state.
- Pass a registered entity when each attempt should load its current state.

Only top-level activity arguments are adapted. Nested objects, collections,
activity results and workflow arguments keep their existing serialization rules.
This API does not add object serialization or a Server/SDK capability.

## Register the application boundary

Configure an entity class, adapter and resolver under a stable logical type:

```php
// config/workflows.php
'v2' => [
    'entity_references' => [
        'orders.order' => [
            'entity_class' => App\Domain\Order::class,
            'adapter' => App\Infrastructure\OrderReferenceAdapter::class,
            'resolver' => App\Infrastructure\OrderReferenceResolver::class,
        ],
    ],
],
```

The class binding lets the worker check the declared activity parameter before
repository access and preserve Laravel method injection. Class names stay in
application configuration. The recorded reference contains only
`{type: "orders.order", id: "o-123", version: 1}` and explicit argument positions;
an ordinary map with the same keys remains an ordinary value.

The adapter implements `Workflow\V2\EntityReferences\EntityReferenceAdapter`:

```php
final class OrderReferenceAdapter implements EntityReferenceAdapter
{
    public function supports(object $entity): bool
    {
        return $entity instanceof Order;
    }

    public function identify(object $entity): string
    {
        return $entity->id;
    }

    public function version(): int
    {
        return 1;
    }
}
```

Adapter construction and methods must be deterministic and perform no I/O.
Adaptation runs while the workflow turn is committed and may run again on a
transaction retry. Workflow code must follow its usual replay rules; registering
an adapter does not permit database reads inside workflow code.

The resolver implements `EntityReferenceResolver`. It receives the portable
reference and an engine-supplied `EntityReferenceContext` containing namespace,
workflow instance/run, activity execution, attempt number, argument position and
the named object parameter type when one is declared:

```php
public function resolve(EntityReference $reference, EntityReferenceContext $context): object
{
    $order = $this->orders->find($reference->id)
        ?? throw EntityNotFound::for($reference);
    if (! $this->authorization->canProcess($context->namespace, $order)) {
        throw EntityAccessDenied::for($reference);
    }
    return $order;
}
```

The resolver owns application authorization. A reference identifier is not proof
of ownership or tenancy. Resolution happens outside the engine's claim/commit
transaction before the actual activity method runs. Each retry resolves again;
replay consumes the recorded activity outcome without calling the resolver.
Other Laravel dependencies are still injected normally.

```php
public function handle(LoggerInterface $logger, Order $order): void
{
    // $order is the authorized current entity for this attempt.
}
```

Unknown logical types, unsupported reference versions, malformed metadata and
parameter mismatches fail before the method runs with non-retryable
`EntityReferenceRefused`. Missing entities, denied access and resolver exceptions
follow the activity's ordinary retry policy. Deploy matching registrations to
the in-process workers that can receive the activity, and keep them available
for retained history and retries.

For local activities, the in-process workflow worker commits preparation before
calling the resolver outside SQL. It then rechecks the original hosting task,
activity attempt, recorded arguments and deadlines before invoking the callback.
The resolved object remains in memory for that invocation; history keeps only
the reference and outcome. Lookup does not extend the hosting lease or reset the
task's history budget. A changed claim or expired deadline prevents the callback.

Local callbacks keep their existing hosting transaction and lifetime. This API
does not move arbitrary activity methods outside SQL or prove that an earlier
physical callback stopped. Local reference resolution requires the worker's
in-process claim at transaction depth zero; direct execution inside a caller's
transaction is refused before lookup. Portable/remote local execution remains
outside this embedded API's scope.
