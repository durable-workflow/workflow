<?php

declare(strict_types=1);

namespace App\Workflows;

use Workflow\QueryMethod;
use Workflow\V2\Attributes\Signal;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Workflow;

#[Type('qualification.http')]
#[Signal('finish', ['value'])]
final class HttpWorkflow extends Workflow
{
    private string $state = 'created';
    private ?string $owner = null;

    public function handle(string $owner, string $mode = 'fast', int $generation = 0): array
    {
        $this->owner = $owner;
        $this->state = 'activity';
        $version = self::getVersion('qualification.http-version', 0, 1);
        $value = self::activity(ShortActivity::class, $owner);
        $signal = null;

        if ($mode === 'timer') {
            $this->state = 'timer';
            self::timer('2 seconds');
        } elseif ($mode === 'signal' || $mode === 'cancel') {
            $this->state = 'signal';
            $signal = self::awaitSignal('finish');
        } elseif ($mode === 'continue' && $generation === 0) {
            self::continueAsNew($owner, $mode, 1);
        }

        $this->state = 'finished';

        return ['owner' => $owner, 'version' => $version, 'generation' => $generation,
            'activity' => $value, 'clock' => self::now()->format('Y-m-d\TH:i:s.uP'), 'signal' => $signal];
    }

    #[QueryMethod('snapshot')]
    public function snapshot(): array
    {
        return ['owner' => $this->owner, 'state' => $this->state];
    }
}
