<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\QueryMethod;
use Workflow\V2\Attributes\Signal;
use Workflow\V2\Attributes\Type;
use function Workflow\V2\sideEffect;
use function Workflow\V2\signal;
use Workflow\V2\Workflow;

#[Type('test-large-side-effect-workflow')]
#[Signal('finish')]
final class TestLargeSideEffectWorkflow extends Workflow
{
    private static int $sideEffectExecutions = 0;

    private ?string $value = null;

    public static function resetCounter(): void
    {
        self::$sideEffectExecutions = 0;
    }

    public static function sideEffectExecutions(): int
    {
        return self::$sideEffectExecutions;
    }

    public function handle(int $size, bool $wait = false): array
    {
        $this->value = sideEffect(static function () use ($size): string {
            self::$sideEffectExecutions++;

            return str_repeat('x', $size);
        });

        if ($wait) {
            signal('finish');
        }

        return [
            'length' => strlen($this->value),
            'value' => $this->value,
        ];
    }

    #[QueryMethod]
    public function currentPayload(): ?array
    {
        if ($this->value === null) {
            return null;
        }

        return [
            'length' => strlen($this->value),
            'value' => $this->value,
        ];
    }
}
