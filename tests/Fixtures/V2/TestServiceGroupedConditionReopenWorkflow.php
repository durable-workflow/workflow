<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use function Workflow\V2\all;
use Workflow\V2\Attributes\Signal;
use Workflow\V2\Attributes\Type;
use function Workflow\V2\await;
use function Workflow\V2\signal;
use function Workflow\V2\timer;
use Workflow\V2\Workflow;

#[Type('test-service-grouped-condition-reopen-workflow')]
#[Signal('vote')]
final class TestServiceGroupedConditionReopenWorkflow extends Workflow
{
    public function handle(): array
    {
        return all([
            static fn () => timer(300),
            static fn () => all([
                static fn () => signal('never'),
                static fn () => await(static fn (): bool => false, conditionKey: 'two-votes'),
            ]),
        ]);
    }
}
