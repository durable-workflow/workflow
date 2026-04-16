<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Throwable;
use Workflow\QueryMethod;
use function Workflow\V2\all;
use Workflow\V2\Attributes\Type;
use function Workflow\V2\child;
use Workflow\V2\Workflow;

#[Type('test-parallel-child-terminal-outcome-workflow')]
final class TestParallelChildTerminalOutcomeWorkflow extends Workflow
{
    private string $stage = 'booting';

    private string $message = '';

    public function handle(int $firstSeconds, int $secondSeconds): array
    {
        $this->stage = 'waiting-for-children';

        try {
            all([
                fn () => child(TestTimerWorkflow::class, $firstSeconds),
                fn () => child(TestTimerWorkflow::class, $secondSeconds),
            ]);

            $this->stage = 'unexpected-success';
        } catch (Throwable $throwable) {
            $this->stage = 'caught-child-outcome';
            $this->message = $throwable->getMessage();
        }

        return [
            'stage' => $this->stage,
            'message' => $this->message,
        ];
    }

    /**
     * @return array{stage: string, message: string}
     */
    #[QueryMethod]
    public function currentState(): array
    {
        return [
            'stage' => $this->stage,
            'message' => $this->message,
        ];
    }
}
