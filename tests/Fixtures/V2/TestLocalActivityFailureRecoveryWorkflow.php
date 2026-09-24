<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use RuntimeException;
use function Workflow\V2\localActivity;
use Workflow\V2\Workflow;

final class TestLocalActivityFailureRecoveryWorkflow extends Workflow
{
    public function handle(string $name): string
    {
        try {
            localActivity(TestGreetingActivity::class, $name);
        } catch (RuntimeException $failure) {
            return 'recovered: ' . $failure->getMessage();
        }

        return 'unexpected success';
    }
}
