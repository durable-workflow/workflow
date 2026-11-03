<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workbench\App\Models\User;
use Workflow\V2\Activity;

final class TestEloquentModelArgumentActivity extends Activity
{
    public function handle(User $user): string
    {
        return $user->email;
    }
}
