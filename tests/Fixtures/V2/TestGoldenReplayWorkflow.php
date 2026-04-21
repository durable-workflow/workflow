<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\QueryMethod;
use Workflow\UpdateMethod;
use function Workflow\V2\activity;
use Workflow\V2\Attributes\Signal;
use Workflow\V2\Attributes\Type;
use function Workflow\V2\await;
use function Workflow\V2\getVersion;
use function Workflow\V2\signal;
use Workflow\V2\Workflow;
use Workflow\V2\WorkflowStub;

#[Type('test-golden-replay-workflow')]
#[Signal('name-provided')]
final class TestGoldenReplayWorkflow extends Workflow
{
    private string $stage = 'booting';

    private ?string $name = null;

    private ?string $greeting = null;

    private bool $approved = false;

    private int $version = WorkflowStub::DEFAULT_VERSION;

    private ?string $versionResult = null;

    /**
     * @var list<string>
     */
    private array $events = [];

    public function handle(string $scenario): array
    {
        return match ($scenario) {
            'single-activity' => $this->singleActivity(),
            'signal-activity' => $this->signalActivity(),
            'wait-condition' => $this->waitCondition(),
            'version-marker' => $this->versionMarker(),
            default => throw new \InvalidArgumentException("Unknown golden replay scenario [{$scenario}]."),
        };
    }

    #[QueryMethod]
    public function currentState(): array
    {
        return [
            'stage' => $this->stage,
            'name' => $this->name,
            'greeting' => $this->greeting,
            'approved' => $this->approved,
            'version' => $this->version,
            'version_result' => $this->versionResult,
            'events' => $this->events,
        ];
    }

    #[UpdateMethod]
    public function approve(bool $approved = true): array
    {
        $this->approved = $approved;
        $this->events[] = $approved ? 'approved' : 'unapproved';

        return $this->currentState();
    }

    private function singleActivity(): array
    {
        $this->stage = 'scheduling-activity';
        $this->greeting = activity(TestGreetingActivity::class, 'Ada');
        $this->events[] = "activity:{$this->greeting}";
        $this->stage = 'completed';

        return $this->currentState();
    }

    private function signalActivity(): array
    {
        $this->stage = 'waiting-for-signal';
        $this->name = signal('name-provided');
        $this->events[] = "signal:{$this->name}";
        $this->greeting = activity(TestGreetingActivity::class, $this->name);
        $this->events[] = "activity:{$this->greeting}";
        $this->stage = 'completed';

        return $this->currentState();
    }

    private function waitCondition(): array
    {
        $this->stage = 'waiting-for-approval';
        await(fn (): bool => $this->approved);
        $this->stage = 'approved';
        $this->events[] = 'condition-satisfied';

        return $this->currentState();
    }

    private function versionMarker(): array
    {
        $this->stage = 'checking-version';
        $this->version = getVersion('golden-version', WorkflowStub::DEFAULT_VERSION, 2);
        $this->versionResult = $this->version >= 2
            ? activity(TestVersionedActivityV3::class)
            : activity(TestVersionedActivityV2::class);
        $this->events[] = "version:{$this->version}";
        $this->stage = 'completed';

        return $this->currentState();
    }
}
