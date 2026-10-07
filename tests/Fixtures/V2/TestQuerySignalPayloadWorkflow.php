<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\QueryMethod;
use Workflow\V2\Attributes\Signal;
use function Workflow\V2\signal;
use Workflow\V2\Workflow;

#[Signal('approval')]
#[Signal('finish')]
final class TestQuerySignalPayloadWorkflow extends Workflow
{
    private string $stage = 'booting';

    private array $values = [];

    public function handle(): array
    {
        for ($wait = 1; $wait <= 2; ++$wait) {
            $this->stage = 'waiting-for-approval-' . $wait;
            $this->values[] = signal('approval');
        }

        $this->stage = 'waiting-for-finish';
        signal('finish');
        $this->stage = 'completed';

        return $this->currentState();
    }

    #[QueryMethod]
    public function currentState(): array
    {
        return [
            'stage' => $this->stage,
            'values' => $this->values,
        ];
    }
}
