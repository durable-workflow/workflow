<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use RuntimeException;
use Tests\Fixtures\V2\TestServiceResponseReplayWorkflow;
use Tests\TestCase;
use Throwable;
use Workflow\Serializers\CodecDecodeException;
use Workflow\Serializers\Serializer;
use Workflow\V2\Contracts\HistoryProjectionRole;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\ExternalPayloadReference;
use Workflow\V2\Support\QueryStateReplayer;
use Workflow\V2\Support\WorkflowFiberRunner;
use Workflow\V2\WorkflowStub;

final class V2ServiceResponseReplayTest extends TestCase
{
    private int $workflowNumber = 0;

    public function testEmbeddedExecutorRejectsMalformedRecognizedServiceResponseEnvelopes(): void
    {
        Queue::fake();

        foreach ($this->serviceEventTypes() as $eventType) {
            foreach ($this->malformedEnvelopes() as $case) {
                [$workflow, $run] = $this->createWorkflowWithServiceHistory($eventType, $case['payload']);

                $this->runReadyWorkflowTask($run);

                /** @var WorkflowFailure $failure */
                $failure = WorkflowFailure::query()
                    ->where('workflow_run_id', $run->id)
                    ->latest('created_at')
                    ->firstOrFail();

                $this->assertSame(RunStatus::Failed, $run->fresh()->status);
                $this->assertSame($case['exception'], $failure->exception_class);
                $this->assertStringContainsString($case['message'], $failure->message);
                $this->assertTrue($workflow->refresh()->failed());
            }
        }
    }

    public function testQueryAndFiberReplayRejectMalformedRecognizedServiceResponseEnvelopes(): void
    {
        Queue::fake();

        foreach ($this->serviceEventTypes() as $eventType) {
            foreach ($this->malformedEnvelopes() as $case) {
                [, $run] = $this->createWorkflowWithServiceHistory($eventType, $case['payload']);

                $this->assertMalformedEnvelopeRejected(
                    static fn (): mixed => (new QueryStateReplayer())->query(
                        $run->fresh(['historyEvents']),
                        'currentResponsePayload',
                    ),
                    $case,
                    sprintf('%s query replay', $eventType->value),
                );
                $this->assertMalformedEnvelopeRejected(
                    fn (): mixed => $this->runFiberHistory($eventType, $case['payload']),
                    $case,
                    sprintf('%s fiber replay', $eventType->value),
                );
            }
        }
    }

    public function testReplayConsumersPreserveDirectServiceResponseObjects(): void
    {
        Queue::fake();

        foreach ($this->serviceEventTypes() as $eventType) {
            foreach ($this->directResponsePayloads() as $payload) {
                [$workflow, $run] = $this->createWorkflowWithServiceHistory($eventType, $payload);

                $this->runReadyWorkflowTask($run);

                $this->assertSameJsonObject($payload, $workflow->refresh()->output());
                $this->assertSameJsonObject(
                    $payload,
                    (new QueryStateReplayer())->query($run->fresh(['historyEvents']), 'currentResponsePayload'),
                );
                $this->assertSameJsonObject($payload, $this->runFiberHistory($eventType, $payload));
            }
        }
    }

    public function testValidInlineServiceResponseEnvelopesDecodeEquivalentlyAcrossReplayConsumers(): void
    {
        Queue::fake();

        $expected = [
            'authorized' => true,
            'auth_code' => 'A-42',
        ];
        $envelope = [
            'codec' => 'avro',
            'blob' => Serializer::serializeWithCodec('avro', $expected),
        ];

        foreach ($this->serviceEventTypes() as $eventType) {
            [$workflow, $run] = $this->createWorkflowWithServiceHistory($eventType, $envelope);

            $this->runReadyWorkflowTask($run);

            $this->assertSame($expected, $workflow->refresh()->output());
            $this->assertSame(
                $expected,
                (new QueryStateReplayer())->query($run->fresh(['historyEvents']), 'currentResponsePayload'),
            );
            $this->assertSame($expected, $this->runFiberHistory($eventType, $envelope));
        }
    }

    /**
     * @return list<HistoryEventType>
     */
    private function serviceEventTypes(): array
    {
        return [HistoryEventType::ServiceCallStarted, HistoryEventType::ServiceCallCompleted];
    }

    /**
     * @return list<array{name: string, payload: array<string, mixed>, exception: class-string<Throwable>, message: string}>
     */
    private function malformedEnvelopes(): array
    {
        return [
            [
                'name' => 'undecodable inline blob',
                'payload' => [
                    'codec' => 'avro',
                    'blob' => '{"truncated":',
                ],
                'exception' => CodecDecodeException::class,
                'message' => 'invalid_payload_framing',
            ],
            [
                'name' => 'invalid external reference',
                'payload' => [
                    'codec' => 'avro',
                    'external_storage' => [
                        'key' => 'payload',
                    ],
                ],
                'exception' => InvalidArgumentException::class,
                'message' => 'Unsupported external payload reference schema',
            ],
            [
                'name' => 'unresolvable external reference',
                'payload' => [
                    'codec' => 'avro',
                    'external_storage' => [
                        'schema' => ExternalPayloadReference::SCHEMA,
                        'uri' => 'file:///unavailable/service-response.json',
                        'sha256' => str_repeat('a', 64),
                        'size_bytes' => 128,
                        'codec' => 'avro',
                    ],
                ],
                'exception' => RuntimeException::class,
                'message' => 'External payload storage driver is unavailable',
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function directResponsePayloads(): array
    {
        return [
            [
                'codec' => 'domain-status',
                'authorized' => true,
            ],
            [
                'codec' => 'domain-record',
                'blob' => [
                    'domain' => 'value',
                ],
            ],
            [
                'codec' => 'domain-record',
                'external_storage' => 'inline',
            ],
        ];
    }

    /**
     * @return array{WorkflowStub, WorkflowRun}
     */
    private function createWorkflowWithServiceHistory(HistoryEventType $eventType, mixed $responsePayload): array
    {
        $workflow = WorkflowStub::make(
            TestServiceResponseReplayWorkflow::class,
            sprintf('service-response-replay-%d', ++$this->workflowNumber),
        );
        $workflow->start();

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->findOrFail($workflow->runId());
        WorkflowHistoryEvent::record($run, $eventType, $this->serviceEventPayload($eventType, $responsePayload));

        return [$workflow, $run];
    }

    private function runReadyWorkflowTask(WorkflowRun $run): void
    {
        $this->bindNoOpHistoryProjection();

        /** @var WorkflowTask $task */
        $task = WorkflowTask::query()
            ->where('workflow_run_id', $run->id)
            ->where('task_type', TaskType::Workflow->value)
            ->where('status', TaskStatus::Ready->value)
            ->firstOrFail();

        $this->app->call([new RunWorkflowTask($task->id), 'handle']);
    }

    private function bindNoOpHistoryProjection(): void
    {
        $this->app->instance(HistoryProjectionRole::class, new class() implements HistoryProjectionRole {
            public function projectRun(WorkflowRun $run): WorkflowRunSummary
            {
                return new WorkflowRunSummary();
            }

            public function recordActivityStarted(
                WorkflowRun $run,
                ActivityExecution $execution,
                ActivityAttempt $attempt,
                WorkflowTask $task,
            ): WorkflowRunSummary {
                return $this->projectRun($run);
            }
        });
    }

    /**
     * @param callable(): mixed $operation
     * @param array{name: string, payload: array<string, mixed>, exception: class-string<Throwable>, message: string} $case
     */
    private function assertMalformedEnvelopeRejected(callable $operation, array $case, string $consumer): void
    {
        try {
            $operation();
        } catch (Throwable $exception) {
            $this->assertInstanceOf($case['exception'], $exception);
            $this->assertStringContainsString($case['message'], $exception->getMessage());

            return;
        }

        $this->fail(sprintf('%s accepted malformed envelope case [%s].', $consumer, $case['name']));
    }

    private function runFiberHistory(HistoryEventType $eventType, mixed $responsePayload): mixed
    {
        $step = WorkflowFiberRunner::forClass(
            TestServiceResponseReplayWorkflow::class,
            'service-response-replay',
            'service-response-replay-run',
            [],
            'avro',
            [[
                'sequence' => 1,
                'event_type' => HistoryEventType::WorkflowStarted->value,
                'payload' => [],
                'recorded_at' => '2026-07-30T10:00:00+00:00',
            ], [
                'sequence' => 2,
                'event_type' => $eventType->value,
                'payload' => $this->serviceEventPayload($eventType, $responsePayload),
                'recorded_at' => '2026-07-30T10:00:01+00:00',
            ]],
        )->step();

        $this->assertTrue($step->completed);

        return $step->result;
    }

    /**
     * @return array<string, mixed>
     */
    private function serviceEventPayload(HistoryEventType $eventType, mixed $responsePayload): array
    {
        $status = $eventType === HistoryEventType::ServiceCallCompleted ? 'completed' : 'started';

        return [
            'sequence' => 1,
            'service_call_id' => 'service-call-1',
            'endpoint_name' => 'payments',
            'service_name' => 'Payments',
            'operation_name' => 'authorize',
            'operation_mode' => 'async',
            'wait_for' => 'accepted',
            'status' => $status,
            'outcome' => $status,
            'response_payload' => $responsePayload,
        ];
    }
}
