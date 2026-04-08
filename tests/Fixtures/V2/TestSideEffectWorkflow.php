<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Generator;
use Workflow\QueryMethod;
use Workflow\V2\Attributes\Signal;
use Workflow\V2\Attributes\Type;
use function Workflow\V2\awaitSignal;
use function Workflow\V2\sideEffect;
use Workflow\V2\Workflow;

#[Type('test-side-effect-workflow')]
#[Signal('finish')]
final class TestSideEffectWorkflow extends Workflow
{
    private static int $sideEffectExecutions = 0;

    private string $stage = 'booting';

    private ?int $token = null;

    public static function resetCounter(): void
    {
        self::$sideEffectExecutions = 0;
    }

    public static function sideEffectExecutions(): int
    {
        return self::$sideEffectExecutions;
    }

    public function execute(): Generator
    {
        $this->stage = 'recording-side-effect';

        $this->token = yield sideEffect(function (): int {
            self::$sideEffectExecutions++;

            return self::$sideEffectExecutions;
        });

        $this->stage = 'waiting-for-finish';

        $finish = yield awaitSignal('finish');

        $this->stage = 'completed';

        return [
            'token' => $this->token,
            'finish' => $finish,
            'workflow_id' => $this->workflowId(),
            'run_id' => $this->runId(),
        ];
    }

    #[QueryMethod]
    public function currentStage(): string
    {
        return $this->stage;
    }

    #[QueryMethod]
    public function currentToken(): ?int
    {
        return $this->token;
    }
}
