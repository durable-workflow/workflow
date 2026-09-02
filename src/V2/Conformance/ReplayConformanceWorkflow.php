<?php

declare(strict_types=1);

namespace Workflow\V2\Conformance;

use Throwable;
use Workflow\QueryMethod;
use Workflow\UpdateMethod;
use function Workflow\V2\activity;
use Workflow\V2\Attributes\Signal;
use Workflow\V2\Attributes\Type;
use function Workflow\V2\await;
use function Workflow\V2\child;
use function Workflow\V2\getVersion;
use function Workflow\V2\signal;
use Workflow\V2\Workflow;
use Workflow\V2\WorkflowStub;

#[Type(self::TYPE_KEY)]
#[Signal('name-provided')]
final class ReplayConformanceWorkflow extends Workflow
{
    public const TYPE_KEY = 'workflow-v2-replay-conformance';

    private string $stage = 'booting';

    private ?string $name = null;

    private ?string $greeting = null;

    private bool $approved = false;

    private int $version = WorkflowStub::DEFAULT_VERSION;

    private ?string $versionResult = null;

    private ?string $reservationId = null;

    /**
     * @var list<string>
     */
    private array $events = [];

    /**
     * @return array<string, mixed>
     */
    public function handle(string $scenario): array
    {
        return match ($scenario) {
            'single-activity' => $this->singleActivity(),
            'signal-activity' => $this->signalActivity(),
            'wait-condition' => $this->waitCondition(),
            'version-marker' => $this->versionMarker(),
            'saga-compensation' => $this->sagaCompensation(),
            default => throw new \InvalidArgumentException("Unknown replay conformance scenario [{$scenario}]."),
        };
    }

    /**
     * @return array<string, mixed>
     */
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
            'reservation_id' => $this->reservationId,
            'events' => $this->events,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    #[UpdateMethod]
    public function approve(bool $approved = true): array
    {
        $this->approved = $approved;
        $this->events[] = $approved ? 'approved' : 'unapproved';

        return $this->currentState();
    }

    /**
     * @return array<string, mixed>
     */
    private function singleActivity(): array
    {
        $this->stage = 'scheduling-activity';
        $this->greeting = activity(ReplayConformanceGreetingActivity::class, 'Ada');
        $this->events[] = "activity:{$this->greeting}";
        $this->stage = 'completed';

        return $this->currentState();
    }

    /**
     * @return array<string, mixed>
     */
    private function signalActivity(): array
    {
        $this->stage = 'waiting-for-signal';
        $this->name = signal('name-provided');
        $this->events[] = "signal:{$this->name}";
        $this->greeting = activity(ReplayConformanceGreetingActivity::class, $this->name);
        $this->events[] = "activity:{$this->greeting}";
        $this->stage = 'completed';

        return $this->currentState();
    }

    /**
     * @return array<string, mixed>
     */
    private function waitCondition(): array
    {
        $this->stage = 'waiting-for-approval';
        await(fn (): bool => $this->approved);
        $this->stage = 'approved';
        $this->events[] = 'condition-satisfied';

        return $this->currentState();
    }

    /**
     * @return array<string, mixed>
     */
    private function versionMarker(): array
    {
        $this->stage = 'checking-version';
        $this->version = getVersion('golden-version', WorkflowStub::DEFAULT_VERSION, 2);
        $this->versionResult = $this->version >= 2
            ? activity(ReplayConformanceVersionedActivityV3::class)
            : activity(ReplayConformanceVersionedActivityV2::class);
        $this->events[] = "version:{$this->version}";
        $this->stage = 'completed';

        return $this->currentState();
    }

    /**
     * @return array<string, mixed>
     */
    private function sagaCompensation(): array
    {
        $this->stage = 'reserving-inventory';
        $this->reservationId = activity(ReplayConformanceBookingActivity::class, 'inventory');
        $this->addCompensation(
            fn (): mixed => activity(ReplayConformanceCancelActivity::class, 'inventory', $this->reservationId)
        );

        try {
            child(ReplayConformanceFailingChildWorkflow::class);
        } catch (Throwable $e) {
            $this->stage = 'compensating';
            $this->compensate();
            $this->events[] = "compensated:{$e->getMessage()}";
            $this->stage = 'compensated';

            return $this->currentState();
        }

        $this->stage = 'completed';
        $this->events[] = 'child-completed';

        return $this->currentState();
    }
}
