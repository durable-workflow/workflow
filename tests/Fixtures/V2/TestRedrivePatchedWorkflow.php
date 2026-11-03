<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use function Workflow\V2\activity;
use Workflow\V2\Attributes\Type;
use function Workflow\V2\patched;
use Workflow\V2\Workflow;

#[Type('test-redrive-patched-workflow')]
final class TestRedrivePatchedWorkflow extends Workflow
{
    /**
     * @return array{result: string, patched: bool, patched_again: bool}
     */
    public function handle(string $name): array
    {
        $patched = patched('redrive-patch');
        // A repeated call reuses the first decision and records no marker.
        $patchedAgain = patched('redrive-patch');
        $greeting = activity(TestRedriveFirstActivity::class, $name);

        return [
            'result' => activity(TestRedriveSecondActivity::class, $greeting),
            'patched' => $patched,
            'patched_again' => $patchedAgain,
        ];
    }
}
