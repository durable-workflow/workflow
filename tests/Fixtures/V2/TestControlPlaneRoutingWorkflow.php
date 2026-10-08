<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\QueryMethod;
use Workflow\V2\Attributes\Signal;
use function Workflow\V2\signal;
use Workflow\V2\Workflow;

#[Signal('finish')]
final class TestControlPlaneRoutingWorkflow extends Workflow
{
    public ?string $connection = 'redis';

    public ?string $queue = 'routed-workflows';

    private string $stage = 'not-started';

    public function handle(): string
    {
        $this->stage = 'waiting-for-finish';
        signal('finish');
        $this->stage = 'completed';

        return 'finished';
    }

    #[QueryMethod('routing-stage')]
    public function currentStage(): string
    {
        return $this->stage;
    }
}
