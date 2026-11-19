<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2\EntityReferences;

use Workflow\V2\Activity;

final class OrderMapActivity extends Activity
{
    public function handle(array $value, string $note): string
    {
        return $value['id'] . ':' . $note;
    }
}
