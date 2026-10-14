<?php

declare(strict_types=1);

namespace App\Workflows;

use Workflow\QueryMethod;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Workflow;

#[Type('qualification.cleanup')]
final class CleanupWorkflow extends Workflow
{
    private string $state = 'waiting';

    public function handle(string $owner, string $mode): void
    {
        try {
            self::timer(300);
        } finally {
            $this->state = 'cleaning_up';
            self::activity(ShortActivity::class, $owner);
            self::timer(1);
            self::upsertMemo(['cleanup_owner' => $owner, 'cleanup_completed' => true]);
        }
    }

    #[QueryMethod('snapshot')]
    public function snapshot(): array
    {
        return ['state' => $this->state];
    }
}
