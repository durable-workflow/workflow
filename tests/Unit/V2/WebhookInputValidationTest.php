<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\WorkerCompatibilityFleet;
use Workflow\V2\Webhooks;
use Workflow\V2\Workflow;
use Workflow\V2\WorkflowStub;

final class WebhookInputValidationTest extends TestCase
{
    private WorkflowRun $run;

    private WorkflowTask $task;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'workflows.webhook_auth.method' => 'none',
            'workflows.v2.task_dispatch_mode' => 'poll',
        ]);
        Queue::fake();
        WorkerCompatibilityFleet::clear();
        WebhookValidationProbeWorkflow::$calls = 0;
        Webhooks::routes([
            'validation-probe' => WebhookValidationProbeWorkflow::class,
        ], '/validation');

        $workflow = WorkflowStub::make(WebhookValidationProbeWorkflow::class, 'validation-existing');
        $this->assertTrue($workflow->attemptStart('Existing')->accepted());
        $this->run = WorkflowRun::query()->findOrFail($workflow->runId());
        $this->task = WorkflowTask::query()->where('workflow_run_id', $this->run->id)->firstOrFail();
        Queue::fake();
    }

    protected function tearDown(): void
    {
        WorkerCompatibilityFleet::clear();
        WebhookValidationProbeWorkflow::$calls = 0;

        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('malformedRequests')]
    public function testMalformedRequestReportsItsFieldBeforeChangingDurableRecords(
        string $method,
        string $path,
        array $payload,
        string $field,
        string $message,
    ): void {
        $path = strtr($path, [
            '{task}' => $this->task->id,
            '{workflow}' => $this->run->workflow_instance_id,
            '{run}' => $this->run->id,
        ]);
        if (str_contains($path, '/start/')) {
            $payload = array_replace([
                'workflow_id' => $this->run->workflow_instance_id,
                'name' => 'Ada',
            ], $payload);
        }
        $before = $this->records();
        $writes = [];
        DB::listen(static function (QueryExecuted $event) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete|replace|truncate|alter|create|drop)\b/i', $event->sql) === 1) {
                $writes[] = $event->sql;
            }
        });

        foreach ([1, 2] as $_) {
            $response = $method === 'GET'
                ? $this->getJson($path . '?' . http_build_query($payload, '', '&', PHP_QUERY_RFC3986))
                : $this->postJson($path, $payload);

            $response->assertStatus(422);
            $this->assertSame([
                $field => [$message],
            ], $response->json('errors'));
            $this->assertSame($before, $this->records());
            $this->assertSame([], $writes);
            $this->assertSame(0, WebhookValidationProbeWorkflow::$calls);
            Queue::assertNothingPushed();
        }
    }

    /**
     * @return iterable<string, array{string, string, array<string, mixed>, string, string}>
     */
    public static function malformedRequests(): iterable
    {
        $start = '/validation/start/validation-probe';
        $idMessage = 'The workflow_id field must be a non-empty URL-safe string up to 191 characters using only letters, numbers, ".", "_", "-", and ":".';
        foreach ([42, null, ['nested']] as $id) {
            yield 'start workflow_id ' . get_debug_type($id) => ['POST', $start, [
                'workflow_id' => $id,
            ], 'workflow_id', $idMessage];
        }
        yield 'duplicate policy must be string' => ['POST', $start, [
            'on_duplicate' => false,
        ], 'on_duplicate', 'The on_duplicate field must be a string.'];
        yield 'duplicate policy must be recognized' => ['POST', $start, [
            'on_duplicate' => 'unknown',
        ], 'on_duplicate', 'The selected on_duplicate value is invalid.'];
        yield 'visibility must be object' => ['POST', $start, [
            'visibility' => false,
        ], 'visibility', 'The visibility field must be an object.'];
        yield 'business key must be string' => ['POST', $start, [
            'visibility' => [
                'business_key' => 42,
            ],
        ], 'visibility.business_key', 'The visibility.business_key field must be a string.'];
        yield 'labels must be object' => ['POST', $start, [
            'visibility' => [
                'labels' => 'invalid',
            ],
        ], 'visibility.labels', 'The visibility.labels field must be an object.'];
        yield 'memo must be object' => ['POST', $start, [
            'visibility' => [
                'memo' => false,
            ],
        ], 'visibility.memo', 'The visibility.memo field must be an object.'];
        yield 'nested label values rejected by options' => ['POST', $start, [
            'visibility' => [
                'labels' => [
                    'team' => ['nested'],
                ],
            ],
        ], 'visibility', 'Workflow v2 visibility label [team] must be a scalar value or null.'];
        yield 'nested memo key rejected by options' => [
            'POST', $start, [
                'visibility' => [
                    'memo' => [
                        'item' => [
                            '' => true,
                        ],
                    ],
                ],
            ], 'visibility', 'Workflow v2 memo.item keys must be non-empty strings up to 64 characters.'];
        yield 'signal with start requires return existing policy' => ['POST', $start . '/signals/approve', [
            'on_duplicate' => 'reject_duplicate',
        ], 'on_duplicate', 'The on_duplicate field must be return_existing_active for signal-with-start.'];
        yield 'signal with start arguments must be array' => ['POST', $start . '/signals/approve', [
            'signal_arguments' => false,
        ], 'signal_arguments', 'The signal_arguments field must be an array.'];
        foreach (['signals/approve', 'queries/state', 'updates/approve'] as $endpoint) {
            yield $endpoint . ' arguments must be array' => ['POST', '/validation/instances/{workflow}/' . $endpoint, [
                'arguments' => false,
            ], 'arguments', 'The arguments field must be an array.'];
            yield $endpoint . ' selected-run arguments must be array' => ['POST', '/validation/instances/{workflow}/runs/{run}/' . $endpoint, [
                'arguments' => false,
            ], 'arguments', 'The arguments field must be an array.'];
        }

        $leaseMessage = 'The lease_owner field must be a non-empty string up to 255 characters.';
        foreach (['workflow-tasks', 'activity-tasks'] as $kind) {
            $claim = '/validation/' . $kind . '/{task}/claim';
            foreach ([
                'non-string' => 42,
                'blank' => '',
                'whitespace' => '  ',
                'overlong Unicode' => str_repeat('é', 256),
            ] as $name => $owner) {
                yield $kind . ' lease owner ' . $name => ['POST', $claim, [
                    'lease_owner' => $owner,
                ], 'lease_owner', $leaseMessage];
            }
        }

        $complete = '/validation/workflow-tasks/{task}/complete';
        yield 'commands required' => [
            'POST',
            $complete,
            [],
            'commands',
            'The commands field is required and must be an array.',
        ];
        foreach ([
            'scalar' => false,
            'object instead of list' => [
                'type' => 'complete_workflow',
            ],
        ] as $name => $commands) {
            yield 'commands ' . $name => ['POST', $complete, [
                'commands' => $commands,
            ], 'commands', 'The commands field must be an array of command objects.'];
        }
        yield 'empty command list' => ['POST', $complete, [
            'commands' => [],
        ], 'commands', 'At least one command must be provided.'];
        foreach ([
            'scalar entry' => false,
            'missing type' => [
                'result' => 42,
            ],
            'non-string type' => [
                'type' => 42,
            ],
        ] as $name => $command) {
            yield 'commands with preceding valid entry and ' . $name => ['POST', $complete, [
                'commands' => [[
                    'type' => 'complete_workflow',
                    'result' => 42,
                ], $command],
            ], 'commands.1', 'Each command must be an object with a string type field.'];
        }

        foreach (['workflow-tasks/{task}', 'activity-attempts/missing-attempt'] as $target) {
            $fail = '/validation/' . $target . '/fail';
            yield $target . ' failure required' => [
                'POST',
                $fail,
                [],
                'failure',
                'The failure field is required and must be a string or object.',
            ];
            foreach ([
                'scalar' => 42,
                'list' => ['failure'],
            ] as $name => $failure) {
                yield $target . ' failure ' . $name => ['POST', $fail, [
                    'failure' => $failure,
                ], 'failure', 'The failure field must be a string or object.'];
            }
        }

        $heartbeat = '/validation/activity-attempts/missing-attempt/heartbeat';
        foreach ([
            'scalar' => false,
            'list' => ['message'],
        ] as $name => $progress) {
            yield 'heartbeat progress ' . $name => ['POST', $heartbeat, [
                'progress' => $progress,
            ], 'progress', 'The progress field must be an object.'];
        }
        yield 'heartbeat unknown progress field' => ['POST', $heartbeat, [
            'progress' => [
                'unexpected' => true,
            ],
        ], 'progress', 'Heartbeat progress only supports [message, current, total, unit, details]; unknown keys: [unexpected].'];
        yield 'heartbeat unit without a counter' => ['POST', $heartbeat, [
            'progress' => [
                'unit' => 'items',
            ],
        ], 'progress', 'Heartbeat progress [unit] requires [current] or [total].'];

        foreach (['workflow-tasks', 'activity-tasks'] as $kind) {
            foreach (['connection', 'queue', 'compatibility'] as $field) {
                yield $kind . ' poll ' . $field . ' must be string' => ['GET', '/validation/' . $kind . '/poll', [
                    $field => ['invalid'],
                ], $field, 'The ' . $field . ' field must be a string.'];
            }
            yield $kind . ' poll limit must be numeric' => ['GET', '/validation/' . $kind . '/poll', [
                'limit' => 'invalid',
            ], 'limit', 'The limit field must be an integer between 1 and 100.'];
        }
    }

    #[DataProvider('acceptedLeaseOwners')]
    public function testAcceptedLeaseOwnerBoundaryReachesMissingTaskDiagnosticWithoutChangingExistingWork(
        string $kind,
        string $owner,
    ): void {
        $before = $this->records();

        foreach ([1, 2] as $_) {
            $this->postJson('/validation/' . $kind . '/missing-task/claim', [
                'lease_owner' => $owner,
            ])
                ->assertStatus(404)
                ->assertJsonPath('reason', 'task_not_found');
            $this->assertSame($before, $this->records());
            $this->assertSame(0, WebhookValidationProbeWorkflow::$calls);
            Queue::assertNothingPushed();
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function acceptedLeaseOwners(): iterable
    {
        foreach (['workflow-tasks', 'activity-tasks'] as $kind) {
            yield $kind . ' surrounding owner whitespace accepted' => [$kind, '  worker-a  '];
            yield $kind . ' 255 Unicode characters' => [$kind, str_repeat('é', 255)];
        }
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function records(): array
    {
        $records = [];
        foreach ([
            WorkflowInstance::class,
            WorkflowRun::class,
            WorkflowTask::class,
            WorkflowCommand::class,
            WorkflowHistoryEvent::class,
            WorkflowFailure::class,
            WorkflowRunSummary::class,
        ] as $model) {
            $records[$model] = $model::query()->orderBy('id')->get()->map(
                static fn (Model $row): array => $row->getRawOriginal()
            )->all();
        }

        return $records;
    }
}

#[Type('webhook-validation-probe')]
final class WebhookValidationProbeWorkflow extends Workflow
{
    public static int $calls = 0;

    public function handle(string $name): string
    {
        ++self::$calls;

        return $name;
    }
}
