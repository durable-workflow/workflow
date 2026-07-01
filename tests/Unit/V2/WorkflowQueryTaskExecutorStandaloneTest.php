<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Workflow\QueryMethod;
use Workflow\Serializers\Serializer;
use Workflow\V2\Attributes\Signal;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Support\HistoryExport;
use Workflow\V2\Workflow;
use Workflow\V2\Worker\WorkflowQueryTaskExecutor;
use function Workflow\V2\signal;

final class WorkflowQueryTaskExecutorStandaloneTest extends TestCase
{
    public function testExecutorAnswersInitialCounterMirrorQueryWithoutLaravelContainer(): void
    {
        $task = $this->queryTask();
        $container = Container::getInstance();
        $facadeApplication = Facade::getFacadeApplication();

        Container::setInstance(null);
        Facade::setFacadeApplication(null);

        try {
            $result = (new WorkflowQueryTaskExecutor([
                'polyglot.php.counter' => WorkflowQueryTaskExecutorStandaloneCounterWorkflow::class,
            ]))->execute($task);
        } finally {
            Container::setInstance($container);
            Facade::setFacadeApplication($facadeApplication);
        }

        $this->assertSame('completed', $result['outcome'] ?? null, json_encode($result, JSON_PRETTY_PRINT));
        $this->assertSame(0, $result['result'] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    private function queryTask(): array
    {
        return [
            'query_task_id' => 'query-task-1',
            'query_task_attempt' => 1,
            'workflow_id' => 'workflow-1',
            'run_id' => 'run-1',
            'workflow_type' => 'polyglot.php.counter',
            'workflow_class' => 'polyglot.php.counter',
            'query_name' => 'state',
            'payload_codec' => 'avro',
            'query_arguments' => [
                'codec' => 'avro',
                'blob' => Serializer::serializeWithCodec('avro', []),
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
                    'workflow_type' => 'polyglot.php.counter',
                    'workflow_class' => 'polyglot.php.counter',
                    'status' => 'running',
                    'last_history_sequence' => 0,
                    'started_at' => '2026-05-17T00:00:00+00:00',
                ],
                'payloads' => [
                    'codec' => 'avro',
                    'arguments' => [
                        'available' => true,
                        'data' => Serializer::serializeWithCodec('avro', []),
                    ],
                    'output' => [
                        'available' => false,
                        'data' => null,
                    ],
                ],
                'history_events' => [],
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
        ];
    }
}

#[Signal('increment', [[
    'name' => 'amount',
    'type' => 'int',
]])]
final class WorkflowQueryTaskExecutorStandaloneCounterWorkflow extends Workflow
{
    private int $count = 0;

    public function handle(): mixed
    {
        while (true) {
            $this->count += (int) signal('increment');
        }
    }

    #[QueryMethod]
    public function state(): int
    {
        return $this->count;
    }

    #[QueryMethod]
    public function current(): int
    {
        return $this->count;
    }
}
