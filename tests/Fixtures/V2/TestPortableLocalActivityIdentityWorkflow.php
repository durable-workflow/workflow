<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use function Workflow\V2\localActivity;
use Workflow\V2\Workflow;

final class TestPortableLocalActivityIdentityWorkflow extends Workflow
{
    /**
     * @return array{first: string, second: string}
     */
    public function handle(string $firstName, string $secondName): array
    {
        return [
            'first' => localActivity(TestGreetingActivity::class, $firstName),
            'second' => localActivity(TestGreetingActivity::class, $secondName),
        ];
    }
}
