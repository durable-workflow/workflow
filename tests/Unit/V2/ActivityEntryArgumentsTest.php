<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Workflow\V2\Activity;
use Workflow\V2\Exceptions\ActivityEntryArgumentsMismatchException;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\ActivityEntryArguments;
use Workflow\V2\Support\RuntimeObjectFactory;

final class ActivityEntryArgumentsTest extends TestCase
{
    private Container $previousContainer;

    protected function setUp(): void
    {
        $this->previousContainer = Container::getInstance();
        $container = new Container();
        $container->instance('config', new Repository());
        Container::setInstance($container);
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->previousContainer);
        parent::tearDown();
    }

    public function testMismatchDiagnosticContainsIdentityButNoRecordedValues(): void
    {
        $activity = $this->activity(ArgumentDtoActivity::class);
        try {
            ActivityEntryArguments::resolve(
                $activity,
                ['private-payment-note'],
                new ReflectionMethod($activity, 'handle')
            );
            self::fail('Injected DTO displaced recorded input.');
        } catch (ActivityEntryArgumentsMismatchException $exception) {
            self::assertStringContainsString('unit-activity-type', $exception->getMessage());
            self::assertStringContainsString('handle', $exception->getMessage());
            self::assertStringContainsString('recorded_argument_would_be_discarded', $exception->getMessage());
            self::assertStringNotContainsString('private-payment-note', $exception->getMessage());
        }
    }

    public function testInjectionWithoutRecordedInputRemainsValid(): void
    {
        $activity = $this->activity(ArgumentDtoActivity::class);
        $resolved = ActivityEntryArguments::resolve($activity, [], new ReflectionMethod($activity, 'handle'));

        self::assertCount(1, $resolved);
        self::assertInstanceOf(ArgumentData::class, $resolved[0]);
        self::assertSame(0, $activity->handle(...$resolved));
    }

    public function testOverlappingServiceInterfacesDoNotMisclassifyTheRecordedNull(): void
    {
        Container::getInstance()->bind(FirstArgumentService::class, CombinedArgumentService::class);
        $activity = $this->activity(NullableServiceActivity::class);
        $resolved = ActivityEntryArguments::resolve($activity, [null], new ReflectionMethod($activity, 'handle'));

        self::assertCount(2, $resolved);
        self::assertInstanceOf(FirstArgumentService::class, $resolved[0]);
        self::assertNull($resolved[1]);
        self::assertNull($activity->handle(...$resolved));
    }

    private function activity(string $class): Activity
    {
        return RuntimeObjectFactory::activity(
            $class,
            new ActivityExecution([
                'activity_type' => 'unit-activity-type',
            ]),
            new WorkflowRun(),
            'unit-task',
        );
    }
}

class ArgumentData
{
    public function __construct(
        public int $amount = 0
    ) {
    }
}

class ArgumentDtoActivity extends Activity
{
    public function handle(ArgumentData $data): int
    {
        return $data->amount;
    }
}

interface FirstArgumentService
{
}

interface SecondArgumentService
{
}

class CombinedArgumentService implements FirstArgumentService, SecondArgumentService
{
}

class NullableServiceActivity extends Activity
{
    public function handle(FirstArgumentService $first, ?SecondArgumentService $second = null): ?SecondArgumentService
    {
        return $second;
    }
}
