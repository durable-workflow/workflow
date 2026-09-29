<?php

namespace App\Workflows;

use Workflow\V2\Attributes\Type;
use Workflow\V2\Workflow;

#[Type('schedule-561-probe')]
final class ProbeWorkflow extends Workflow
{
    public function handle(): string
    {
        return 'ok';
    }
}
