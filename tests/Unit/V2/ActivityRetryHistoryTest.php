<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Support\ActivityRetryHistory;

final class ActivityRetryHistoryTest extends TestCase
{
    private Container $previousContainer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = Container::getInstance();
        $application = new Application(dirname(__DIR__, 3));
        $application->instance('config', new Repository([]));
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->previousContainer);
        parent::tearDown();
    }

    public static function terminalKinds(): array
    {
        return [
            'completed prefix' => [HistoryEventType::ActivityCompleted],
            'failed boundary' => [HistoryEventType::ActivityFailed],
        ];
    }

    #[DataProvider('terminalKinds')]
    public function testCanonicalRetryChainBindsEachHeartbeatToItsActiveAttempt(HistoryEventType $terminal): void
    {
        self::assertTrue(ActivityRetryHistory::valid(self::models(self::history($terminal))));
    }

    public static function malformedChains(): iterable
    {
        foreach (self::terminalKinds() as $kind => [$terminal]) {
            foreach ([
                'heartbeat wrong attempt',
                'heartbeat wrong number',
                'heartbeat wrong payload task',
                'heartbeat wrong row task',
                'heartbeat wrong snapshot task',
                'heartbeat before start',
                'heartbeat after retry',
                'duplicate schedule',
                'mid-chain schedule',
                'missing schedule',
            ] as $mutation) {
                yield $kind . ': ' . $mutation => [$terminal, $mutation];
            }
        }
    }

    #[DataProvider('malformedChains')]
    public function testMalformedChainCannotAuthorizeRedrive(HistoryEventType $terminal, string $mutation): void
    {
        $history = self::history($terminal);
        switch ($mutation) {
            case 'heartbeat wrong attempt':
                $history[2]['payload']['activity_attempt_id'] = 'other-attempt';
                break;
            case 'heartbeat wrong number':
                $history[2]['payload']['attempt_number'] = 2;
                break;
            case 'heartbeat wrong payload task':
                $history[2]['payload']['workflow_task_id'] = 'other-task';
                break;
            case 'heartbeat wrong row task':
                $history[2]['workflow_task_id'] = 'other-task';
                break;
            case 'heartbeat wrong snapshot task':
                $history[2]['payload']['activity_attempt']['task_id'] = 'other-task';
                break;
            case 'heartbeat before start':
                $heartbeat = array_splice($history, 2, 1);
                array_splice($history, 1, 0, $heartbeat);
                break;
            case 'heartbeat after retry':
                $heartbeat = array_splice($history, 2, 1);
                array_splice($history, 3, 0, $heartbeat);
                break;
            case 'duplicate schedule':
                array_splice($history, 1, 0, [$history[0]]);
                break;
            case 'mid-chain schedule':
                array_splice($history, 4, 0, [$history[0]]);
                break;
            case 'missing schedule':
                array_shift($history);
                break;
        }
        self::assertFalse(ActivityRetryHistory::valid(self::models($history)), $mutation);
    }

    private static function history(HistoryEventType $terminal): array
    {
        $identity = [
            'activity_execution_id' => 'execution-1',
            'activity_type' => 'test.activity',
            'sequence' => 1,
        ];
        $attempt = static fn (int $number): array => [
            ...$identity,
            'activity_attempt_id' => 'attempt-' . $number,
            'attempt_number' => $number,
        ];
        $heartbeat = static fn (int $number): array => [
            ...$attempt($number),
            'activity_attempt' => [
                'id' => 'attempt-' . $number,
                'attempt_number' => $number,
                'task_id' => 'task-' . $number,
            ],
        ];
        return [
            [
                'event_type' => HistoryEventType::ActivityScheduled,
                'payload' => $identity,
            ],
            [
                'event_type' => HistoryEventType::ActivityStarted,
                'workflow_task_id' => 'task-1',
                'payload' => [
                    ...$attempt(1),
                    'workflow_task_id' => 'task-1',
                ],
            ],
            [
                'event_type' => HistoryEventType::ActivityHeartbeatRecorded,
                'workflow_task_id' => 'task-1',
                'payload' => $heartbeat(1),
            ],
            [
                'event_type' => HistoryEventType::ActivityRetryScheduled,
                'workflow_task_id' => 'task-1',
                'payload' => [
                    ...$attempt(1),
                    'retry_after_attempt_id' => 'attempt-1',
                    'retry_after_attempt' => 1,
                    'retry_of_task_id' => 'task-1',
                    'retry_task_id' => 'task-2',
                    'max_attempts' => 2,
                ],
            ],
            [
                'event_type' => HistoryEventType::ActivityStarted,
                'workflow_task_id' => 'task-2',
                'payload' => [
                    ...$attempt(2),
                    'workflow_task_id' => 'task-2',
                ],
            ],
            [
                'event_type' => HistoryEventType::ActivityHeartbeatRecorded,
                'workflow_task_id' => 'task-2',
                'payload' => $heartbeat(2),
            ],
            [
                'event_type' => $terminal,
                'workflow_task_id' => 'task-2',
                'payload' => $attempt(2),
            ],
        ];
    }

    private static function models(array $history): array
    {
        return array_map(static fn (array $event): WorkflowHistoryEvent => new WorkflowHistoryEvent($event), $history);
    }
}
