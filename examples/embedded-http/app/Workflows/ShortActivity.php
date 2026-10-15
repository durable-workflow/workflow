<?php

declare(strict_types=1);

namespace App\Workflows;

final class ShortActivity extends \Workflow\V2\Activity
{
    public function handle(string $owner): array
    {
        return ['owner' => $owner, 'integer' => 9007199254740993, 'optional' => null, 'unicode' => 'activity λ'];
    }
}
