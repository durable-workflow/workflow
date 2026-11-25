<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\V2\TestGreetingActivity;
use Tests\NonDatabaseTestCase;
use Workflow\UpdateValidator;
use function Workflow\V2\activity;
use function Workflow\V2\all;
use function Workflow\V2\await;
use function Workflow\V2\child;
use function Workflow\V2\continueAsNew;
use Workflow\V2\Exceptions\UpdateValidatorCommandException;
use function Workflow\V2\sideEffect;
use function Workflow\V2\signalExternalWorkflow;
use Workflow\V2\Support\ActivityCall;
use Workflow\V2\Support\WorkflowFiberContext;
use function Workflow\V2\timer;
use function Workflow\V2\upsertMemo;
use function Workflow\V2\upsertSearchAttributes;

final class WorkflowFiberContextUpdateValidatorTest extends NonDatabaseTestCase
{
    /**
     * @return iterable<string, array{callable(): mixed, string}>
     */
    public static function durableCalls(): iterable
    {
        yield 'activity' => [
            static fn (): mixed => activity(TestGreetingActivity::class, 'x'),
            'ActivityCall',
        ];
        yield 'timer' => [
            static fn (): mixed => timer(5),
            'TimerCall',
        ];
        yield 'child' => [
            static fn (): mixed => child('child-type'),
            'ChildWorkflowCall',
        ];
        yield 'signal external' => [
            static fn (): mixed => signalExternalWorkflow('other', 'go'),
            'SignalExternalWorkflowCall',
        ];
        yield 'side effect' => [
            static fn (): mixed => sideEffect(static fn (): int => 1),
            'SideEffectCall',
        ];
        yield 'await' => [
            static fn (): mixed => await(static fn (): bool => true),
            'AwaitCall',
        ];
        yield 'continue as new' => [
            static fn (): mixed => continueAsNew(),
            'ContinueAsNewCall',
        ];
        yield 'memo' => [static function (): void {
            upsertMemo([
                'a' => 1,
            ]);
        }, 'UpsertMemoCall'];
        yield 'search attributes' => [static function (): void {
            upsertSearchAttributes([
                'a' => 'b',
            ]);
        }, 'UpsertSearchAttributesCall'];
        yield 'all' => [
            static fn (): mixed => all([
                static fn (): mixed => activity(TestGreetingActivity::class, 'x'),
            ]),
            'ActivityCall',
        ];
    }

    #[DataProvider('durableCalls')]
    public function testValidatorScopeRefusesDurableCalls(callable $call, string $type): void
    {
        try {
            WorkflowFiberContext::validatingUpdate('approve', $call);
            $this->fail('A durable call inside an update validator must throw.');
        } catch (UpdateValidatorCommandException $exception) {
            $this->assertSame('approve', $exception->updateName());
            $this->assertSame($type, $exception->commandType());
            $this->assertStringContainsString('read-only', $exception->getMessage());
        }
    }

    public function testScopeEndsAfterTheValidator(): void
    {
        $this->assertSame('ok', WorkflowFiberContext::validatingUpdate('approve', static fn (): string => 'ok'));

        // Outside a validator and outside a fiber the call object is returned.
        $this->assertInstanceOf(ActivityCall::class, activity(TestGreetingActivity::class, 'x'));
    }

    public function testScopeEndsWhenTheValidatorThrows(): void
    {
        try {
            WorkflowFiberContext::validatingUpdate('approve', static function (): never {
                throw new LogicException('nope');
            });
        } catch (LogicException) {
        }

        $this->assertInstanceOf(ActivityCall::class, activity(TestGreetingActivity::class, 'x'));
    }

    public function testValidatorAttributeRequiresAnUpdateName(): void
    {
        $this->assertSame('approve', (new UpdateValidator('approve'))->update);

        $this->expectException(LogicException::class);

        new UpdateValidator('');
    }
}
