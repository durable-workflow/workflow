<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\V2\Workflow;

final class TestEntryArgumentsMismatchWorkflow extends Workflow
{
    public function handle(string $label): string
    {
        Workflow::timer('1 second');

        return $label;
    }
}
