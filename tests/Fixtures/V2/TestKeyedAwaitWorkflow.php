<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\QueryMethod;
use Workflow\UpdateMethod;
use Workflow\V2\Attributes\Type;
use function Workflow\V2\await;
use Workflow\V2\Workflow;

#[Type('test-keyed-await-workflow')]
final class TestKeyedAwaitWorkflow extends Workflow
{
    private bool $approved = false;

    public function handle(): array
    {
        await(fn (): bool => $this->approved, 'approval.ready');

        return [
            'approved' => $this->approved,
        ];
    }

    #[QueryMethod]
    public function currentState(): array
    {
        return [
            'approved' => $this->approved,
        ];
    }

    #[UpdateMethod]
    public function approve(bool $approved = true): array
    {
        $this->approved = $approved;

        return $this->currentState();
    }
}
