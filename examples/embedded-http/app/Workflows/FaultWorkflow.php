<?php

declare(strict_types=1);

namespace App\Workflows;

use Workflow\QueryMethod;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Workflow;

#[Type('qualification.fault')]
final class FaultWorkflow extends Workflow
{
    private string $state = 'created';
    private ?string $owner = null;

    public function handle(string $owner, string $mode = 'fault'): array
    {
        $this->owner = $owner;
        $version = self::getVersion('qualification.fault-version', 0, 1);
        $this->state = 'activity';
        $value = self::activity(ShortActivity::class, $owner);
        $this->state = 'timer';
        self::timer('30 seconds');
        $this->state = 'finished';

        return ['owner' => $owner, 'version' => $version, 'activity' => $value,
            'clock' => self::now()->format('Y-m-d\TH:i:s.uP')];
    }

    #[QueryMethod('snapshot')]
    public function snapshot(): array
    {
        return ['owner' => $this->owner, 'state' => $this->state];
    }
}
