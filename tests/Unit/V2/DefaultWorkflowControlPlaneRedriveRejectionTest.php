<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\V2\TestRedriveWorkflow;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Contracts\WorkflowControlPlane;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowLink;
use Workflow\V2\Models\WorkflowMemo;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowSearchAttribute;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\DefaultWorkflowTaskBridge;
use Workflow\V2\Support\ExternalPayloadReference;
use Workflow\V2\Support\ExternalPayloads;
use Workflow\V2\Support\FailedRunRedrivePlan;
use Workflow\V2\WorkflowStub;

final class DefaultWorkflowControlPlaneRedriveRejectionTest extends TestCase
{
    private WorkflowControlPlane $controlPlane;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-08T05:40:00Z');
        config()
            ->set('workflows.v2.task_dispatch_mode', 'poll');
        Queue::fake();
        $this->controlPlane = app(WorkflowControlPlane::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[DataProvider('refusals')]
    public function testRefusedRedrivePreservesEveryRecordAndQueuesNoWork(
        string $case,
        string $reason,
        int $status
    ): void {
        $startOptions = match ($case) {
            'memo rows' => [
                'memo' => [
                    'order' => [0, false, ''],
                ],
            ],
            'search attribute rows' => [
                'search_attributes' => [
                    'attempt' => 0,
                ],
            ],
            default => [],
        };
        $source = $this->failedSource($startOptions);
        $instanceId = $source->workflow_instance_id;
        $runId = $source->id;
        $options = $this->redriveOptions();

        switch ($case) {
            case 'long ASCII request':
                $options['request_id'] = str_repeat('r', 192);
                break;
            case 'long UTF8 request':
                $options['request_id'] = str_repeat('é', 96);
                break;
            case 'missing instance':
                $instanceId = 'absent-instance';
                break;
            case 'foreign namespace':
                $options['namespace'] = 'another-tenant';
                break;
            case 'missing run':
                $runId = 'absent-run';
                break;
            case 'foreign run':
                $foreign = $this->failedSource(instanceId: 'another-instance');
                $runId = $foreign->id;
                break;
            case 'historical run':
                $current = $source->replicate();
                $current->forceFill([
                    'run_number' => 2,
                    'status' => RunStatus::Pending,
                ])->save();
                $source->instance->forceFill([
                    'current_run_id' => $current->id,
                    'run_count' => 2,
                ])->save();
                break;
            case 'stored arguments':
                $source->forceFill([
                    'arguments' => $this->storedPayload(),
                ])->save();
                break;
            case 'child workflow link':
                $parent = $this->failedSource(instanceId: 'parent-instance');
                WorkflowLink::query()->create([
                    'link_type' => 'child_workflow',
                    'sequence' => 1,
                    'parent_workflow_instance_id' => $parent->workflow_instance_id,
                    'parent_workflow_run_id' => $parent->id,
                    'child_workflow_instance_id' => $source->workflow_instance_id,
                    'child_workflow_run_id' => $source->id,
                    'is_primary_parent' => true,
                ]);
                break;
            case 'recorded memo':
            case 'recorded search attributes':
            case 'recorded parent':
                $event = $this->event($source, HistoryEventType::WorkflowStarted);
                $field = match ($case) {
                    'recorded memo' => 'memo',
                    'recorded search attributes' => 'search_attributes',
                    default => 'parent_workflow_run_id',
                };
                $event->forceFill([
                    'payload' => array_merge($event->payload, [
                        $field => $field === 'parent_workflow_run_id' ? 'parent-run' : [
                            'order' => '42',
                        ],
                    ]),
                ])->save();
                break;
            case 'reused request on failed successor':
                $first = $this->controlPlane->redrive($instanceId, $runId, $options);
                $this->assertTrue($first['accepted'], (string) $first['reason']);
                $source = WorkflowRun::query()->findOrFail($first['workflow_run_id']);
                $this->recordFailure($source, false);
                $runId = $source->id;
                break;
        }

        $this->assertTrue(FailedRunRedrivePlan::forRun($source->fresh())['eligible']);
        $this->assertRefusedWithoutMutation($instanceId, $runId, $options, $reason, $status);
    }

    /**
     * @return iterable<string, array{string, string, int}>
     */
    public static function refusals(): iterable
    {
        foreach (['long ASCII request', 'long UTF8 request'] as $case) {
            yield $case => [$case, 'invalid_request_id', 422];
        }
        foreach (['missing instance', 'foreign namespace'] as $case) {
            yield $case => [$case, 'instance_not_found', 404];
        }
        foreach (['missing run', 'foreign run'] as $case) {
            yield $case => [$case, 'run_not_found', 404];
        }
        yield 'historical run' => ['historical run', 'source_run_not_current', 409];
        foreach (['memo rows', 'search attribute rows'] as $case) {
            yield $case => [$case, 'unsupported_visibility_metadata', 409];
        }
        foreach (['stored arguments', 'child workflow link'] as $case) {
            yield $case => [$case, 'unsupported_source_link_or_payload', 409];
        }
        foreach (['recorded memo', 'recorded search attributes', 'recorded parent'] as $case) {
            yield $case => [$case, 'unsupported_source_metadata', 409];
        }
        yield 'reused request on failed successor' => [
            'reused request on failed successor',
            'request_id_already_used',
            409,
        ];
    }

    #[DataProvider('unsafeHistories')]
    public function testUnsafeRecordedHistoryReturnsItsSpecificReasonWithoutMutation(string $case, string $reason): void
    {
        $source = $this->failedSource();
        $this->assertTrue(FailedRunRedrivePlan::forRun($source->fresh())['eligible']);
        $scheduled = $this->event($source, HistoryEventType::ActivityScheduled);
        $completion = $this->event($source, HistoryEventType::ActivityCompleted);
        $failure = $this->event($source, HistoryEventType::WorkflowFailed);

        switch ($case) {
            case 'event after terminal failure':
                WorkflowHistoryEvent::record($source, HistoryEventType::ActivityHeartbeatRecorded, [
                    'sequence' => 2,
                    'activity_type' => 'remote.second',
                ]);
                break;
            case 'duplicate start':
                $scheduled->forceFill([
                    'event_type' => HistoryEventType::WorkflowStarted,
                ])->save();
                break;
            case 'start acceptance after workflow start':
                $scheduled->forceFill([
                    'event_type' => HistoryEventType::StartAccepted,
                ])->save();
                break;
            case 'failure without start':
                $source->historyEvents()
                    ->whereNotIn('event_type', [
                        HistoryEventType::StartAccepted->value, HistoryEventType::WorkflowFailed->value,
                    ])->delete();
                break;
            case 'missing terminal event':
                $failure->delete();
                break;
            case 'unsupported timer':
                $scheduled->forceFill([
                    'event_type' => HistoryEventType::TimerScheduled,
                ])->save();
                break;
            case 'local execution mode':
            case 'local activity flag':
                $field = $case === 'local execution mode' ? 'execution_mode' : 'local_activity';
                $scheduled->forceFill([
                    'payload' => array_merge($scheduled->payload, [
                        $field => $field === 'execution_mode' ? 'local' : true,
                    ]),
                ])->save();
                break;
            case 'string activity sequence':
            case 'zero activity sequence':
                $scheduled->forceFill([
                    'payload' => array_merge($scheduled->payload, [
                        'sequence' => $case === 'string activity sequence' ? '1' : 0,
                    ]),
                ])->save();
                break;
            case 'missing activity type':
                $payload = $scheduled->payload;
                unset($payload['activity_type']);
                $scheduled->forceFill([
                    'payload' => $payload,
                ])->save();
                break;
            case 'activity type mismatch':
                $completion->forceFill([
                    'payload' => array_merge($completion->payload, [
                        'activity_type' => 'remote.changed',
                    ]),
                ])->save();
                break;
            case 'gap with unchanged step count':
                foreach ($source->historyEvents()->whereIn('event_type', [
                    HistoryEventType::ActivityScheduled->value, HistoryEventType::ActivityFailed->value,
                ])->get() as $event) {
                    if (($event->payload['sequence'] ?? null) === 2) {
                        $event->forceFill([
                            'payload' => array_merge($event->payload, [
                                'sequence' => 3,
                            ]),
                        ])->save();
                    }
                }
                break;
            case 'completed failed step':
                $failed = $this->event($source, HistoryEventType::ActivityFailed);
                $failed->forceFill([
                    'event_type' => HistoryEventType::ActivityCompleted,
                    'payload' => array_merge($failed->payload, [
                        'result' => Serializer::serializeWithCodec('avro', 'unexpected completion'),
                        'payload_codec' => 'avro',
                    ]),
                ])->save();
                break;
            case 'incomplete prefix':
                $completion->delete();
                break;
            case 'missing result':
            case 'missing result codec':
                $payload = $completion->payload;
                unset($payload[$case === 'missing result' ? 'result' : 'payload_codec']);
                $completion->forceFill([
                    'payload' => $payload,
                ])->save();
                break;
            case 'stored completed result':
            case 'null completed result':
                $completion->forceFill([
                    'payload' => array_merge($completion->payload, [
                        'result' => $case === 'stored completed result' ? $this->storedPayload() : null,
                    ]),
                ])->save();
                break;
        }

        $plan = FailedRunRedrivePlan::forRun($source->fresh());
        $this->assertSame([
            'eligible' => false,
            'reason' => $reason,
            'resume_step_sequence' => null,
            'completed' => [],
        ], $plan);
        $this->assertRefusedWithoutMutation(
            $source->workflow_instance_id,
            $source->id,
            $this->redriveOptions(),
            $reason,
            409,
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unsafeHistories(): iterable
    {
        yield 'event after terminal failure' => ['event after terminal failure', 'events_after_failure'];
        yield 'duplicate start' => ['duplicate start', 'duplicate_start'];
        yield 'start acceptance after workflow start' => ['start acceptance after workflow start', 'unexpected_start'];
        yield 'failure without start' => ['failure without start', 'missing_start'];
        yield 'missing terminal event' => ['missing terminal event', 'missing_terminal_history'];
        yield 'unsupported timer' => ['unsupported timer', 'unsupported_history'];
        foreach (['local execution mode', 'local activity flag'] as $case) {
            yield $case => [$case, 'unsupported_local_activity'];
        }
        foreach (['string activity sequence', 'zero activity sequence'] as $case) {
            yield $case => [$case, 'invalid_activity_sequence'];
        }
        yield 'missing activity type' => ['missing activity type', 'missing_activity_type'];
        yield 'activity type mismatch' => ['activity type mismatch', 'activity_type_mismatch'];
        yield 'gap with unchanged step count' => ['gap with unchanged step count', 'activity_sequence_gap'];
        yield 'completed failed step' => ['completed failed step', 'invalid_failed_step'];
        yield 'incomplete prefix' => ['incomplete prefix', 'incomplete_activity_prefix'];
        foreach (['missing result', 'missing result codec', 'null completed result'] as $case) {
            yield $case => [$case, 'missing_activity_result'];
        }
        yield 'stored completed result' => ['stored completed result', 'external_activity_result_not_supported'];
    }

    public function testRecordedLocalClassStillRedrivesAfterItsAliasIsRemoved(): void
    {
        WorkflowStub::fake();
        $workflow = WorkflowStub::make(TestRedriveWorkflow::class, 'redrive-local-alias');
        $workflow->start('Taylor');
        $this->assertTrue($workflow->refresh()->failed());
        $this->assertSame(1, Cache::get('test:redrive:first-calls'));
        $this->assertSame(1, Cache::get('test:redrive:second-calls'));
        $source = WorkflowRun::query()->findOrFail($workflow->runId());
        $source->forceFill([
            'workflow_type' => 'retired-local-alias',
        ])->save();
        $before = $source->fresh()
            ->getRawOriginal();
        $result = $this->controlPlane->redrive($source->workflow_instance_id, $source->id, [
            'namespace' => $source->namespace,
            'request_id' => str_repeat('r', 191),
        ]);

        $this->assertTrue($result['accepted'], (string) $result['reason']);
        $this->assertSame(202, $result['status']);
        $this->assertSame(2, $result['resume_step_sequence']);
        $successor = WorkflowRun::query()->findOrFail($result['workflow_run_id']);
        $this->assertSame(TestRedriveWorkflow::class, $successor->workflow_class);
        $this->assertSame('retired-local-alias', $successor->workflow_type);
        $this->assertSame(RunStatus::Completed, $successor->status);
        $this->assertSame($before, $source->fresh()->getRawOriginal());
        $this->assertSame('Hello, Taylor!', Serializer::unserializeWithCodec(
            'avro',
            $this->event($successor, HistoryEventType::ActivityCompleted)->payload['result']
        ));
        $this->assertSame(
            $source->id,
            $this->event($successor, HistoryEventType::ActivityCompleted)->payload['reused_from_run_id']
        );
        $this->assertSame($successor->id, $source->instance->fresh()->current_run_id);
        $this->assertSame(1, WorkflowLink::query()->where('link_type', 'redrive')->count());
        $this->assertSame(1, WorkflowCommand::query()->where('command_type', 'redrive')->count());
        $this->assertSame('Hello, Taylor! Redrive completed.', $successor->workflowOutput()['result']);
        $this->assertSame(1, Cache::get('test:redrive:first-calls'));
        $this->assertSame(2, Cache::get('test:redrive:second-calls'));
        $after = $this->records();
        for ($attempt = 0; $attempt < 2; ++$attempt) {
            $repeated = $this->controlPlane->redrive($source->workflow_instance_id, $source->id, [
                'namespace' => $source->namespace,
                'request_id' => str_repeat('r', 191),
            ]);
            $this->assertTrue($repeated['accepted']);
            $this->assertSame(200, $repeated['status']);
            $this->assertSame($successor->id, $repeated['workflow_run_id']);
        }
        $this->assertSame($after, $this->records());
        $this->assertSame(1, Cache::get('test:redrive:first-calls'));
        $this->assertSame(2, Cache::get('test:redrive:second-calls'));
        Queue::assertNothingPushed();
    }

    /**
     * @param array<string, mixed> $options
     */
    private function failedSource(array $options = [], string $instanceId = 'redrive-source'): WorkflowRun
    {
        $start = $this->controlPlane->start('remote.redrive', $instanceId, $options + [
            'namespace' => 'tenant-redrive',
            'queue' => 'service-workflows',
            'arguments' => Serializer::serializeWithCodec('avro', ['Taylor']),
            'external_workflow_definition_fingerprint' => 'worker-definition-1',
        ]);
        $this->assertTrue($start['started']);
        $source = WorkflowRun::query()->findOrFail($start['workflow_run_id']);
        $this->recordFailure($source);

        return $source->fresh();
    }

    private function recordFailure(WorkflowRun $source, bool $includeCompletedPrefix = true): void
    {
        if ($includeCompletedPrefix) {
            $firstActivityId = (string) Str::ulid();
            WorkflowHistoryEvent::record($source, HistoryEventType::ActivityScheduled, [
                'activity_execution_id' => $firstActivityId,
                'activity_type' => 'remote.first',
                'sequence' => 1,
            ]);
            WorkflowHistoryEvent::record($source, HistoryEventType::ActivityCompleted, [
                'activity_execution_id' => $firstActivityId,
                'activity_type' => 'remote.first',
                'sequence' => 1,
                'result' => Serializer::serializeWithCodec('avro', [0, false, '']),
                'payload_codec' => 'avro',
            ]);
        }
        $activityId = (string) Str::ulid();
        WorkflowHistoryEvent::record($source, HistoryEventType::ActivityScheduled, [
            'activity_execution_id' => $activityId,
            'activity_type' => 'remote.second',
            'sequence' => 2,
        ]);
        WorkflowHistoryEvent::record($source, HistoryEventType::ActivityFailed, [
            'activity_execution_id' => $activityId,
            'activity_type' => 'remote.second',
            'sequence' => 2,
            'message' => 'temporary failure',
        ]);
        $task = WorkflowTask::query()->where('workflow_run_id', $source->id)->firstOrFail();
        $task->forceFill([
            'status' => TaskStatus::Leased,
            'lease_owner' => 'service-worker',
            'lease_expires_at' => now()
                ->addMinute(),
        ])->save();
        $source->forceFill([
            'status' => RunStatus::Waiting,
        ])->save();
        $result = app(DefaultWorkflowTaskBridge::class)->complete($task->id, [[
            'type' => 'fail_workflow',
            'message' => 'temporary failure',
            'failed_step_sequence' => 2,
            'failed_activity_execution_id' => $activityId,
        ]]);
        $this->assertTrue($result['completed']);
        $this->assertSame(RunStatus::Failed, $source->fresh()->status);
    }

    private function event(WorkflowRun $run, HistoryEventType $type): WorkflowHistoryEvent
    {
        return $run->historyEvents()
            ->where('event_type', $type->value)
            ->orderBy('sequence')
            ->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function redriveOptions(): array
    {
        return [
            'namespace' => 'tenant-redrive',
            'request_id' => 'redrive-once',
            'external_workflow_definition_fingerprint' => 'worker-definition-1',
        ];
    }

    private function storedPayload(): string
    {
        $payload = Serializer::serializeWithCodec('avro', [0, false, '']);

        return ExternalPayloads::encodeStoredEnvelope([
            'codec' => 'avro',
            'external_storage' => (new ExternalPayloadReference(
                uri: 'memory://redrive/source-only',
                sha256: hash('sha256', $payload),
                sizeBytes: strlen($payload),
                codec: 'avro',
            ))->toArray(),
        ]);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function assertRefusedWithoutMutation(
        string $instanceId,
        string $runId,
        array $options,
        string $reason,
        int $status
    ): void {
        $before = $this->records();
        $connection = (new WorkflowRun())->getConnection();
        $connection->flushQueryLog();
        $connection->enableQueryLog();
        try {
            for ($attempt = 0; $attempt < 2; ++$attempt) {
                $this->assertSame([
                    'accepted' => false,
                    'workflow_instance_id' => $instanceId,
                    'workflow_run_id' => null,
                    'continued_from_run_id' => $runId,
                    'resume_step_sequence' => null,
                    'task_id' => null,
                    'reason' => $reason,
                    'status' => $status,
                ], $this->controlPlane->redrive($instanceId, $runId, $options));
            }
            $queries = $connection->getQueryLog();
        } finally {
            $connection->disableQueryLog();
        }
        foreach ($queries as $query) {
            $this->assertDoesNotMatchRegularExpression(
                '/^\s*(insert|update|delete|replace|alter|create|drop)\b/i',
                $query['query']
            );
        }
        if ($reason === 'invalid_request_id') {
            $this->assertSame([], $queries);
        }
        $this->assertSame($before, $this->records());
        Queue::assertNothingPushed();
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function records(): array
    {
        $records = [];
        foreach ([WorkflowInstance::class, WorkflowRun::class, WorkflowCommand::class,
            WorkflowHistoryEvent::class, WorkflowTask::class, WorkflowLink::class,
            WorkflowMemo::class, WorkflowSearchAttribute::class, ActivityExecution::class,
            ActivityAttempt::class, WorkflowFailure::class] as $model) {
            $records[$model] = $model::query()->orderBy('id')->get()->map(
                static fn (Model $row): array => $row->getRawOriginal(),
            )->all();
        }

        return $records;
    }
}
