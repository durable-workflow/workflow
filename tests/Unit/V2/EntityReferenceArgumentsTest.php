<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ReflectionMethod;
use Tests\Fixtures\V2\EntityReferences\Order;
use Tests\Fixtures\V2\EntityReferences\OrderReferenceAdapter;
use Workflow\V2\Activity;
use Workflow\V2\EntityReferences\EntityReference;
use Workflow\V2\EntityReferences\EntityReferenceContext;
use Workflow\V2\EntityReferences\EntityReferenceRefused;
use Workflow\V2\EntityReferences\EntityReferenceResolver;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\ActivityEntryArguments;
use Workflow\V2\Support\EntityReferenceArguments;
use Workflow\V2\Support\RuntimeObjectFactory;

final class EntityReferenceArgumentsTest extends TestCase
{
    private Container $previous;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previous = Container::getInstance();
        $container = new Container();
        $container->instance('config', new Repository([
            'workflows' => [
                'v2' => [
                    'entity_references' => [
                        'orders.order' => [
                            'entity_class' => Order::class,
                            'adapter' => OrderReferenceAdapter::class,
                            'resolver' => EntityArgumentResolver::class,
                        ],
                    ],
                ],
            ],
        ]));
        $container->instance(LoggerInterface::class, new NullLogger());
        Container::setInstance($container);
        EntityArgumentResolver::$calls = 0;
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->previous);
        parent::tearDown();
    }

    public function testReferenceResolutionPreservesAnInjectedServiceAndRecordedPosition(): void
    {
        $activity = $this->activity([0]);
        $method = new ReflectionMethod($activity, 'handle');
        $arguments = EntityReferenceArguments::resolve($activity, $this->arguments(), $method);
        $resolved = ActivityEntryArguments::resolve($activity, $arguments, $method);
        self::assertInstanceOf(NullLogger::class, $resolved[0]);
        self::assertInstanceOf(Order::class, $resolved[1]);
        self::assertSame('note', $resolved[2]);
        self::assertSame(1, EntityArgumentResolver::$calls);
    }

    #[DataProvider('invalidPositions')]
    public function testMalformedRecordedPositionsRefuseBeforeResolverAccess(mixed $positions): void
    {
        $activity = $this->activity($positions);
        try {
            EntityReferenceArguments::resolve($activity, $this->arguments(), new ReflectionMethod($activity, 'handle'));
            self::fail('Malformed metadata was accepted.');
        } catch (EntityReferenceRefused $error) {
            self::assertSame(EntityReferenceRefused::INVALID_REFERENCE, $error->reason);
            self::assertSame(0, EntityArgumentResolver::$calls);
        }
    }

    public static function invalidPositions(): iterable
    {
        yield 'null' => [null];
        yield 'boolean' => [false];
        yield 'string' => ['0'];
        yield 'boolean member' => [[false]];
        yield 'negative member' => [[-1]];
        yield 'non-reference member' => [[1]];
        yield 'duplicate member' => [[0, 0]];
        yield 'non-list' => [[
            1 => 0,
        ]];
        yield 'array member' => [[[
            'position' => 0,
        ]]];
    }

    public function testAmbiguousAdaptersRefuseRatherThanChoosingRegistrationOrder(): void
    {
        $registrations = config('workflows.v2.entity_references');
        $registrations['other.order'] = $registrations['orders.order'];
        config()
            ->set('workflows.v2.entity_references', $registrations);
        $this->expectException(EntityReferenceRefused::class);
        EntityReferenceArguments::encode([new Order('id', 'paid')]);
    }

    private function activity(mixed $positions): Activity
    {
        return RuntimeObjectFactory::activity(
            EntityArgumentActivity::class,
            new ActivityExecution([
                'id' => 'execution-id',
                'attempt_count' => 1,
                'activity_options' => [
                    'entity_reference_positions' => $positions,
                ],
            ]),
            new WorkflowRun([
                'id' => 'run-id',
                'namespace' => 'tenant-a',
                'workflow_instance_id' => 'instance-id',
            ]),
            'task-id',
        );
    }

    private function arguments(): array
    {
        return [[
            'type' => 'orders.order',
            'id' => 'id',
            'version' => 1,
        ], 'note'];
    }
}

final class EntityArgumentResolver implements EntityReferenceResolver
{
    public static int $calls = 0;

    public function resolve(EntityReference $reference, EntityReferenceContext $context): object
    {
        ++self::$calls;
        return new Order($reference->id, 'paid');
    }
}

final class EntityArgumentActivity extends Activity
{
    public function handle(LoggerInterface $logger, Order $order, string $note): string
    {
        return $order->status . ':' . $note;
    }
}
