<?php

declare(strict_types=1);

namespace Workflow\V2\Conformance;

use function Workflow\V2\activity;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Workflow;

#[Type(self::TYPE_KEY)]
final class ReplayConformanceDivergentWorkflow extends Workflow
{
    public const TYPE_KEY = 'workflow-v2-replay-conformance-divergent';

    /**
     * @return array<string, mixed>
     */
    public function handle(string $scenario): array
    {
        if ($scenario !== 'single-activity') {
            throw new \InvalidArgumentException("Unknown divergent replay conformance scenario [{$scenario}].");
        }

        return [
            'stage' => 'diverged',
            'result' => activity(ReplayConformanceCancelActivity::class, 'inventory', 'inventory-id-456'),
        ];
    }
}
