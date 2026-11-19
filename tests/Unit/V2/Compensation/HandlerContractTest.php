<?php

declare(strict_types=1);

namespace Tests\Unit\V2\Compensation;

use PHPUnit\Framework\TestCase;
use Tests\Fixtures\V2\TestScopedOperation;
use Workflow\V2\Compensation\Definition\Obligation;
use Workflow\V2\Compensation\Execution\ForwardResult;
use Workflow\V2\Compensation\Execution\HandlerContractViolation;
use Workflow\V2\Compensation\Execution\HandlerRegistry;
use Workflow\V2\Compensation\Execution\Resolution;

final class HandlerContractTest extends TestCase
{
    public function testDeprecatedForwardSettledTrueStillVerifies(): void
    {
        $result = $this->withoutDeprecations(
            $messages,
            static fn (): Resolution => call_user_func([Resolution::class, 'verified'], ['undo-ack'], true)
        );
        $this->assertSame(Resolution::VERIFIED, $result->outcome);
        $this->assertSame(['undo-ack'], $result->evidence);
        $this->assertCount(1, $messages);
        $this->assertStringContainsString(
            'Passing forwardSettled to Resolution::verified() is deprecated',
            $messages[0]
        );
    }

    public function testDeprecatedForwardSettledFalseIsALateForwardUnknown(): void
    {
        $result = $this->withoutDeprecations(
            $messages,
            static fn (): Resolution => call_user_func([Resolution::class, 'verified'], ['undo-ack'], false)
        );
        $this->assertSame(Resolution::UNKNOWN, $result->outcome);
        $this->assertSame('late-forward-possible', $result->reason);
        $this->assertSame([], $result->evidence);
        $this->assertCount(1, $messages);
    }

    public function testVerifiedRejectsAnythingButTheDeprecatedFlag(): void
    {
        $this->expectException(HandlerContractViolation::class);
        $this->expectExceptionMessage('Resolution::verified() takes only evidence.');
        call_user_func([Resolution::class, 'verified'], ['undo-ack'], 'yes');
    }

    public function testInvalidOutcomesThrowHandlerContractViolation(): void
    {
        foreach ([
            static fn () => Resolution::verified([]),
            static fn () => Resolution::unknown('not a reason'),
            static fn () => Resolution::failed('refused', [str_repeat('e', 129)]),
            static fn () => ForwardResult::observed([
                'ok' => true,
            ], []),
            static fn () => ForwardResult::unknown(''),
            static fn () => ForwardResult::rejected('refused', array_fill(0, 33, 'proof')),
        ] as $index => $outcome) {
            try {
                $outcome();
                $this->fail("Outcome {$index} was accepted.");
            } catch (HandlerContractViolation $violation) {
                $this->assertInstanceOf(\InvalidArgumentException::class, $violation);
                $this->assertNotSame('', $violation->getMessage());
            }
        }
    }

    public function testTheForwardValueLimitIsAppliedWhenRecordedNotWhenBuilt(): void
    {
        $result = ForwardResult::observed([
            'receipt' => str_repeat('r', 12_000),
        ], ['provider:1']);
        $this->assertTrue($result->observed);
        $this->expectException(HandlerContractViolation::class);
        $this->expectExceptionMessage('Compensation forward value exceeds 11264 bytes.');
        $result->assertValueWithinLimits();
    }

    public function testValuesWithinTheForwardLimitPass(): void
    {
        $result = ForwardResult::observed([
            'receipt' => str_repeat('r', 11_000),
        ], ['provider:1']);
        $result->assertValueWithinLimits();
        $this->assertTrue($result->observed);
    }

    public function testARawVersionOneVerifiedRowWithAnUnsettledForwardDecodesAsUnknown(): void
    {
        // Exactly as 2.31.0 and earlier stored Resolution::verified(['undo-ack'], false).
        $stored = '{"evidence":["undo-ack"],"forwardSettled":false,"reason":"verified","verified":true,"version":1}';
        $result = Resolution::fromArray(json_decode($stored, true, flags: JSON_THROW_ON_ERROR));
        $this->assertSame(Resolution::UNKNOWN, $result->outcome);
        $this->assertSame('late-forward-possible', $result->reason);
        $this->assertSame([], $result->evidence);
        $this->assertFalse($result->verified);

        $settled = '{"evidence":["undo-ack"],"forwardSettled":true,"reason":"verified","verified":true,"version":1}';
        $this->assertSame(
            Resolution::VERIFIED,
            Resolution::fromArray(json_decode($settled, true, flags: JSON_THROW_ON_ERROR))->outcome
        );
    }

    public function testLegacyEngineReservedTypesStayResolvable(): void
    {
        $handler = new TestScopedOperation();
        $registry = new HandlerRegistry([
            'dw.custom' => [
                '1' => $handler,
            ],
        ]);
        $this->assertSame($handler, $registry->resolve(new Obligation('legacy', 'dw.custom', '1', [])));
    }

    public function testTheEngineChildScopeTypesCannotBeMapped(): void
    {
        foreach (['dw.child-scope', 'dw.reserved-child-scope'] as $type) {
            try {
                new HandlerRegistry([
                    $type => [
                        '1' => new TestScopedOperation(),
                    ],
                ]);
                $this->fail("{$type} was mapped.");
            } catch (\InvalidArgumentException $refused) {
                $this->assertStringContainsString('cannot be mapped', $refused->getMessage());
            }
        }
    }

    /**
     * @template T
     *
     * @param list<string>|null $messages
     * @param callable(): T $callback
     *
     * @return T
     */
    private function withoutDeprecations(?array &$messages, callable $callback): mixed
    {
        $messages = [];
        set_error_handler(static function (int $level, string $message) use (&$messages): bool {
            $messages[] = $message;
            return true;
        }, E_USER_DEPRECATED);
        try {
            return $callback();
        } finally {
            restore_error_handler();
        }
    }
}
