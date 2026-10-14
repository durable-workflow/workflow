<?php

declare(strict_types=1);

namespace App\Models;

final class QualifiedWorkflowInstance extends \Workflow\V2\Models\WorkflowInstance
{
    public function getForeignKey(): string
    {
        return 'workflow_instance_id';
    }
}
