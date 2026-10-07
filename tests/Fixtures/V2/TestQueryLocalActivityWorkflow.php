<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Throwable;
use Workflow\QueryMethod;
use function Workflow\V2\localActivity;
use function Workflow\V2\timer;
use Workflow\V2\Workflow;

final class TestQueryLocalActivityWorkflow extends Workflow
{
    private string $stage = 'booting';

    private ?string $greeting = null;

    /**
     * @var array{class: string, message: string, code: int}|null
     */
    private ?array $failure = null;

    public function handle(): array
    {
        $this->stage = 'waiting-for-local-activity';

        try {
            $this->greeting = localActivity(TestQueryReplayGuardActivity::class);
        } catch (Throwable $failure) {
            $this->failure = [
                'class' => $failure::class,
                'message' => $failure->getMessage(),
                'code' => $failure->getCode(),
            ];
        }

        $this->stage = 'waiting-for-timer';
        timer(60);
        $this->stage = 'completed';

        return $this->currentState();
    }

    #[QueryMethod]
    public function currentState(): array
    {
        return [
            'stage' => $this->stage,
            'greeting' => $this->greeting,
            'failure' => $this->failure,
        ];
    }
}
