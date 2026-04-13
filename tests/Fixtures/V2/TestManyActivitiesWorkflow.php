<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use function Workflow\V2\activity;
use function Workflow\V2\all;
use function Workflow\V2\startActivity;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Workflow;

#[Type('test-many-activities-workflow')]
final class TestManyActivitiesWorkflow extends Workflow
{
    public function handle(int $count): array
    {
        $calls = [];

        for ($i = 0; $i < $count; $i++) {
            $calls[] = startActivity(TestGreetingActivity::class, "item-{$i}");
        }

        return all($calls);
    }
}
