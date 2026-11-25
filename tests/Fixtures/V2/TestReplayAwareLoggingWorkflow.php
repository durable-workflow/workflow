<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\QueryMethod;
use Workflow\UpdateMethod;
use function Workflow\V2\activity;
use Workflow\V2\Attributes\Signal;
use Workflow\V2\Attributes\Type;
use function Workflow\V2\sideEffect;
use function Workflow\V2\signal;
use Workflow\V2\Workflow;

#[Type('test-replay-aware-logging-workflow')]
#[Signal('finish')]
final class TestReplayAwareLoggingWorkflow extends Workflow
{
    /**
     * @var list<string>
     */
    public static array $observedStates = [];

    /**
     * @var list<string>
     */
    private array $notes = [];

    public function handle(?string $channel = null): array
    {
        $log = $this->logger($channel);
        self::$observedStates[] = 'start:' . $this->replayState()->value;
        $log->info('logging workflow started');

        $first = activity(TestGreetingActivity::class, 'first');
        self::$observedStates[] = 'after-first:' . $this->replayState()->value;
        $log->info('logging workflow step one', [
            'result' => $first,
        ]);

        $stamp = sideEffect(static fn (): string => 'stamped');
        $log->info('logging workflow side effect', [
            'result' => $stamp,
        ]);

        $second = activity(TestGreetingActivity::class, 'second');
        $log->info('logging workflow step two', [
            'result' => $second,
        ]);

        signal('finish');
        self::$observedStates[] = 'after-signal:' . $this->replayState()->value;
        $log->info('logging workflow finishing');

        return [$first, $stamp, $second, $this->notes];
    }

    #[QueryMethod]
    public function status(): array
    {
        $this->logger()
            ->info('logging workflow query');

        return [
            'replay_state' => $this->replayState()
->value,
            'replaying' => $this->isReplaying(),
            'notes' => $this->notes,
        ];
    }

    #[UpdateMethod]
    public function note(string $note, ?string $channel = null): array
    {
        $this->notes[] = $note;
        $this->logger($channel)
            ->info('logging workflow update applied', [
                'note' => $note,
            ]);

        return $this->notes;
    }
}
