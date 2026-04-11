<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\UpdateMethod;
use Workflow\V2\Attributes\Type;
use function Workflow\V2\awaitWithTimeout;
use Workflow\V2\Workflow;

#[Type('test-keyed-await-timeout-workflow')]
final class TestKeyedAwaitWithTimeoutWorkflow extends Workflow
{
    private bool $approved = false;

    public function execute(): array
    {
        $approved = awaitWithTimeout(5, fn (): bool => $this->approved, 'approval.ready');

        return [
            'approved' => $approved,
        ];
    }

    #[UpdateMethod]
    public function approve(bool $approved = true): array
    {
        $this->approved = $approved;

        return [
            'approved' => $this->approved,
        ];
    }
}
