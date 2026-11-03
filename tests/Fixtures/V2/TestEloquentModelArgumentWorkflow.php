<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workbench\App\Models\User;
use function Workflow\V2\activity;
use Workflow\V2\Workflow;

final class TestEloquentModelArgumentWorkflow extends Workflow
{
    public function handle(int $userId): string
    {
        return activity(TestEloquentModelArgumentActivity::class, User::query()->findOrFail($userId));
    }
}
