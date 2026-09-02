<?php

declare(strict_types=1);

namespace Workflow\V2\Conformance;

use RuntimeException;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Workflow;

#[Type(self::TYPE_KEY)]
final class ReplayConformanceFailingChildWorkflow extends Workflow
{
    public const TYPE_KEY = 'workflow-v2-replay-conformance-failing-child';

    public function handle(): never
    {
        throw new RuntimeException('payment declined');
    }
}
