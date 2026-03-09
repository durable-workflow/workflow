<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Workflow\Activity;

final class TestNullableArgumentsActivity extends Activity
{
    public function execute(array $reports, ?int $min = null, ?int $max = null, ?string $comparing = 'hours')
    {
        return [
            'reports' => $reports,
            'min' => $min,
            'max' => $max,
            'comparing' => $comparing,
        ];
    }
}
