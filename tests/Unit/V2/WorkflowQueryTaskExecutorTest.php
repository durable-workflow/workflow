<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Tests\Fixtures\V2\TestQueryWorkflow;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Exceptions\InvalidQueryArgumentsException;
use Workflow\V2\Support\HistoryExport;
use Workflow\V2\Worker\WorkflowQueryTaskExecutor;

final class WorkflowQueryTaskExecutorTest extends TestCase
{
    public function testExecutorReplaysHistoryExportAndCompletesKnownQuery(): void
    {
        $result = (new WorkflowQueryTaskExecutor())->execute($this->queryTask());

        $this->assertSame('completed', $result['outcome'] ?? null);
        $this->assertSame('query-task-1', $result['query_task_id'] ?? null);
        $this->assertSame(1, $result['query_task_attempt'] ?? null);
        $this->assertSame('waiting-for-name', $result['result'] ?? null);
        $this->assertSame('avro', $result['result_envelope']['codec'] ?? null);
        $this->assertSame(
            'waiting-for-name',
            Serializer::unserializeWithCodec('avro', (string) ($result['result_envelope']['blob'] ?? '')),
        );
    }

    public function testExecutorFailsUnknownQueryWithoutThrowing(): void
    {
        $result = (new WorkflowQueryTaskExecutor())->execute($this->queryTask([
            'query_name' => 'missing-query',
        ]));

        $this->assertSame('failed', $result['outcome'] ?? null);
        $this->assertSame('query-task-1', $result['query_task_id'] ?? null);
        $this->assertSame(1, $result['query_task_attempt'] ?? null);
        $this->assertSame('rejected_unknown_query', $result['failure']['reason'] ?? null);
        $this->assertSame('QueryNotFound', $result['failure']['type'] ?? null);
    }

    public function testExecutorNormalizesNamedQueryArgumentsAgainstQueryContract(): void
    {
        $result = (new WorkflowQueryTaskExecutor())->execute($this->queryTask([
            'query_name' => 'events-starting-with',
            'query_arguments' => [
                'blob' => Serializer::serializeWithCodec('avro', [
                    'prefix' => 'start',
                ]),
            ],
        ]));

        $this->assertSame('completed', $result['outcome'] ?? null);
        $this->assertSame(1, $result['result'] ?? null);
    }

    public function testExecutorPreservesInvalidQueryArgumentBoundary(): void
    {
        $result = (new WorkflowQueryTaskExecutor())->execute($this->queryTask([
            'query_name' => 'events-starting-with',
            'query_arguments' => [
                'blob' => Serializer::serializeWithCodec('avro', [
                    'extra' => 'start',
                ]),
            ],
        ]));

        $this->assertSame('failed', $result['outcome'] ?? null);
        $this->assertSame('invalid_query_arguments', $result['failure']['reason'] ?? null);
        $this->assertSame(InvalidQueryArgumentsException::class, $result['failure']['type'] ?? null);
        $this->assertSame(
            'Workflow query [events-starting-with] received invalid arguments.',
            $result['failure']['message'] ?? null,
        );
        $this->assertSame(
            ['The prefix argument is required.'],
            $result['failure']['validation_errors']['prefix'] ?? null,
        );
        $this->assertSame(
            ['Unknown argument [extra].'],
            $result['failure']['validation_errors']['extra'] ?? null,
        );
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function queryTask(array $overrides = []): array
    {
        $workflowArguments = Serializer::serializeWithCodec('avro', []);
        $queryArguments = Serializer::serializeWithCodec('avro', []);

        return array_replace_recursive([
            'query_task_id' => 'query-task-1',
            'query_task_attempt' => 1,
            'workflow_id' => 'workflow-1',
            'run_id' => 'run-1',
            'workflow_type' => 'test-query-workflow',
            'workflow_class' => TestQueryWorkflow::class,
            'query_name' => 'currentStage',
            'payload_codec' => 'avro',
            'query_arguments' => [
                'codec' => 'avro',
                'blob' => $queryArguments,
            ],
            'history_export' => [
                'schema' => HistoryExport::SCHEMA,
                'schema_version' => HistoryExport::SCHEMA_VERSION,
                'exported_at' => '2026-05-17T00:00:00+00:00',
                'history_complete' => false,
                'workflow' => [
                    'instance_id' => 'workflow-1',
                    'run_id' => 'run-1',
                    'run_number' => 1,
                    'workflow_type' => 'test-query-workflow',
                    'workflow_class' => TestQueryWorkflow::class,
                    'status' => 'running',
                    'last_history_sequence' => 1,
                    'started_at' => '2026-05-17T00:00:00+00:00',
                ],
                'payloads' => [
                    'codec' => 'avro',
                    'arguments' => [
                        'available' => true,
                        'data' => $workflowArguments,
                    ],
                    'output' => [
                        'available' => false,
                        'data' => null,
                    ],
                ],
                'history_events' => [
                    [
                        'id' => 'event-started',
                        'sequence' => 1,
                        'type' => HistoryEventType::WorkflowStarted->value,
                        'payload' => [
                            'workflow_type' => 'test-query-workflow',
                            'workflow_class' => TestQueryWorkflow::class,
                            'payload_codec' => 'avro',
                        ],
                        'recorded_at' => '2026-05-17T00:00:00+00:00',
                    ],
                ],
                'commands' => [],
                'signals' => [],
                'updates' => [],
                'tasks' => [],
                'activities' => [],
                'timers' => [],
                'failures' => [],
                'links' => [
                    'parents' => [],
                    'children' => [],
                ],
            ],
        ], $overrides);
    }
}
