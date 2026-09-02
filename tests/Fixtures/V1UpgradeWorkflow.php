<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Workflow\Workflow;

final class V1UpgradeWorkflow extends Workflow
{
    public $connection = 'redis';

    public $queue = 'workflow-v1';

    public function execute(): string
    {
        return 'v1';
    }
}
