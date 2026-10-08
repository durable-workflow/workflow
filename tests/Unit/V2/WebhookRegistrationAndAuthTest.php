<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Workflow\Auth\WebhookAuthenticator;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowSignal;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowUpdate;
use Workflow\V2\Support\WorkerCompatibilityFleet;
use Workflow\V2\Webhooks;
use Workflow\V2\Workflow;
use Workflow\V2\WorkflowStub;

final class WebhookRegistrationAndAuthTest extends TestCase
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
        WebhookRegistrationProbeWorkflow::$calls = 0;
        WebhookRequestAuthenticator::$calls = 0;
        Webhooks::routes([
            'auth-probe' => WebhookRegistrationProbeWorkflow::class,
        ], '/auth');

        $workflow = WorkflowStub::make(WebhookRegistrationProbeWorkflow::class, 'auth-existing');
        $this->assertTrue($workflow->attemptStart('Existing')->accepted());
        $this->run = WorkflowRun::query()->findOrFail($workflow->runId());
        $this->task = WorkflowTask::query()->where('workflow_run_id', $this->run->id)->firstOrFail();
        Queue::fake();
    }

    protected function tearDown(): void
    {
        WorkerCompatibilityFleet::clear();
        WebhookRegistrationProbeWorkflow::$calls = 0;
        WebhookRequestAuthenticator::$calls = 0;

        parent::tearDown();
    }

    /**
     * @param array<int|string, mixed> $invalid
     */
    #[DataProvider('invalidRegistrations')]
    public function testInvalidRegistrationRejectsTheWholeBatchBeforeAddingAnyRoutes(
        array $invalid,
        string $message,
    ): void {
        $before = Route::getRoutes()->getRoutes();

        foreach ([1, 2] as $_) {
            try {
                Webhooks::routes([
                    'candidate' => WebhookRegistrationProbeWorkflow::class,
                    ...$invalid,
                ], '/candidate');
                $this->fail('Invalid webhook registration was accepted.');
            } catch (LogicException $exception) {
                $this->assertSame($message, $exception->getMessage());
            }

            $this->assertSame($before, Route::getRoutes()->getRoutes());
            $this->assertSame(0, WebhookRegistrationProbeWorkflow::$calls);
            Queue::assertNothingPushed();
        }
    }

    /**
     * @return iterable<string, array{array<int|string, mixed>, string}>
     */
    public static function invalidRegistrations(): iterable
    {
        foreach ([42, false, \stdClass::class, 'Missing\\Workflow'] as $workflow) {
            yield 'invalid workflow ' . var_export($workflow, true) => [[
                'invalid' => $workflow,
            ], sprintf('Webhook workflow [%s] must extend %s.', (string) $workflow, Workflow::class)];
        }

        foreach ([
            '',
            'with space',
            'path/segment',
            'colon:alias',
            'query?alias',
            'é',
            'line' . "\n" . 'break',
        ] as $alias) {
            yield 'invalid alias ' . var_export($alias, true) => [[
                $alias => WebhookRegistrationProbeWorkflow::class,
            ], sprintf(
                'Webhook alias [%s] for workflow [%s] contains unsupported characters.',
                $alias,
                WebhookRegistrationProbeWorkflow::class,
            )];
        }

        yield 'inference requires a Type attribute' => [[UntypedWebhookProbeWorkflow::class], sprintf(
            'Workflow [%s] must define a #[Type(...)] attribute or be registered with an explicit webhook alias.',
            UntypedWebhookProbeWorkflow::class,
        )];
    }

    /**
     * @param array<int|string, class-string<Workflow>> $workflows
     */
    #[DataProvider('acceptedRegistrations')]
    public function testAcceptedAliasRegistersARealStartRouteAndPreservesTheWorkflowType(
        array $workflows,
        string $alias,
        string $type,
    ): void {
        Webhooks::routes($workflows, '/registered///');

        $this->postJson('/registered/start/' . $alias, [
            'workflow_id' => 'new-registration',
            'name' => 'Ada',
        ])
            ->assertStatus(202)
            ->assertJsonPath('outcome', 'started_new')
            ->assertJsonPath('workflow_type', $type)
            ->assertJsonPath('workflow_id', 'new-registration');

        $this->assertDatabaseHas('workflow_instances', [
            'id' => 'new-registration',
            'workflow_type' => $type,
        ]);
        $this->assertSame(2, WorkflowInstance::query()->count());
        $this->assertSame(2, WorkflowTask::query()->count());
        $this->assertSame(0, WebhookRegistrationProbeWorkflow::$calls);
        Queue::assertNothingPushed();
    }

    /**
     * @return iterable<string, array{array<int|string, class-string<Workflow>>, string, string}>
     */
    public static function acceptedRegistrations(): iterable
    {
        yield 'inferred type alias' => [[WebhookRegistrationProbeWorkflow::class],
            'registered.type_v2',
            'registered.type_v2',
        ];
        yield 'explicit mixed URL-safe alias' => [[
            'A.b_9-X' => WebhookRegistrationProbeWorkflow::class,
        ], 'A.b_9-X', 'registered.type_v2'];
        yield 'explicit alias permits an untyped workflow' => [[
            'untyped' => UntypedWebhookProbeWorkflow::class,
        ], 'untyped', UntypedWebhookProbeWorkflow::class];
    }

    #[DataProvider('protectedEndpoints')]
    public function testEveryWebhookEndpointAuthenticatesBeforeReadingOrChangingDurableState(
        string $method,
        string $path,
    ): void {
        $path = strtr($path, [
            '{workflow}' => $this->run->workflow_instance_id,
            '{run}' => $this->run->id,
            '{task}' => $this->task->id,
        ]);

        $this->assertDeniedWithoutDatabaseAccess($method, $path, [
            'workflows.webhook_auth.method' => 'token',
            'workflows.webhook_auth.token.header' => 'X-Workflow-Token',
            'workflows.webhook_auth.token.token' => 'test-only-token',
        ]);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function protectedEndpoints(): iterable
    {
        yield 'start' => ['POST', '/auth/start/auth-probe'];
        yield 'signal with start' => ['POST', '/auth/start/auth-probe/signals/approve'];
        foreach (['workflow-tasks', 'activity-tasks'] as $kind) {
            yield $kind . ' poll' => ['GET', '/auth/' . $kind . '/poll'];
            yield $kind . ' claim' => ['POST', '/auth/' . $kind . '/{task}/claim'];
        }
        yield 'activity attempt status' => ['GET', '/auth/activity-attempts/missing-attempt'];
        foreach (['heartbeat', 'complete', 'fail'] as $operation) {
            yield 'activity ' . $operation => ['POST', '/auth/activity-attempts/missing-attempt/' . $operation];
        }
        yield 'workflow history' => ['GET', '/auth/workflow-tasks/{task}/history'];
        foreach (['execute', 'complete', 'fail', 'heartbeat'] as $operation) {
            yield 'workflow ' . $operation => ['POST', '/auth/workflow-tasks/{task}/' . $operation];
        }
        foreach (['/auth/instances/{workflow}', '/auth/instances/{workflow}/runs/{run}'] as $scope) {
            foreach ([
                'queries/state',
                'signals/approve',
                'updates/approve',
                'repair',
                'cancel',
                'terminate',
                'archive',
            ] as $operation) {
                yield $scope . ' ' . $operation => ['POST', $scope . '/' . $operation];
            }
            yield $scope . ' update status' => ['GET', $scope . '/updates/missing-update'];
            yield $scope . ' describe' => ['GET', $scope . '/describe'];
        }
        yield 'control-plane start' => ['POST', '/auth/control-plane/start'];
        yield 'priority fairness' => ['GET', '/auth/task-queues/default/priority-fairness'];
    }

    /**
     * @param array<string, mixed> $configuration
     */
    #[DataProvider('rejectedAuthentication')]
    public function testRejectedAuthenticationPrecedesEvenMalformedClaimInput(array $configuration): void
    {
        $this->assertDeniedWithoutDatabaseAccess(
            'POST',
            '/auth/workflow-tasks/' . $this->task->id . '/claim',
            $configuration
        );
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function rejectedAuthentication(): iterable
    {
        yield 'unsupported method' => [[
            'workflows.webhook_auth.method' => 'unsupported',
        ]];
        foreach ([\stdClass::class, WebhookAuthenticator::class, 'Missing\\Authenticator'] as $class) {
            yield 'custom class ' . $class => [[
                'workflows.webhook_auth.method' => 'custom',
                'workflows.webhook_auth.custom.class' => $class,
            ]];
        }
        yield 'missing signature' => [[
            'workflows.webhook_auth.method' => 'signature',
            'workflows.webhook_auth.signature.header' => 'X-Workflow-Signature',
            'workflows.webhook_auth.signature.secret' => 'test-only-secret',
        ]];
        yield 'custom authenticator rejection' => [[
            'workflows.webhook_auth.method' => 'custom',
            'workflows.webhook_auth.custom.class' => WebhookRequestAuthenticator::class,
        ]];
    }

    #[DataProvider('acceptedAuthentication')]
    public function testAcceptedAuthenticationReachesTheClaimContract(string $method): void
    {
        config([
            'workflows.webhook_auth.method' => $method,
            'workflows.webhook_auth.token.header' => 'X-Workflow-Token',
            'workflows.webhook_auth.token.token' => 'test-only-token',
            'workflows.webhook_auth.signature.header' => 'X-Workflow-Signature',
            'workflows.webhook_auth.signature.secret' => 'test-only-secret',
            'workflows.webhook_auth.custom.class' => WebhookRequestAuthenticator::class,
        ]);
        $body = '{"lease_owner":"worker-a"}';
        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'X-Workflow-Token' => 'test-only-token',
            'X-Workflow-Signature' => hash_hmac('sha256', $body, 'test-only-secret'),
            'X-Test-Auth' => 'accepted',
        ];
        $before = $this->records();

        foreach ([1, 2] as $_) {
            $this->call(
                'POST',
                '/auth/workflow-tasks/missing-task/claim',
                [],
                [],
                [],
                $this->transformHeadersToServerVars($headers),
                $body
            )
                ->assertStatus(404)
                ->assertJsonPath('reason', 'task_not_found');
            $this->assertSame($before, $this->records());
            Queue::assertNothingPushed();
        }
        $this->assertSame($method === 'custom' ? 2 : 0, WebhookRequestAuthenticator::$calls);

        if ($method === 'signature') {
            $this->call(
                'POST',
                '/auth/workflow-tasks/missing-task/claim',
                [],
                [],
                [],
                $this->transformHeadersToServerVars($headers),
                '{"lease_owner":"worker-b"}'
            )
                ->assertStatus(401)
                ->assertJsonPath('message', 'Unauthorized');
            $this->assertSame($before, $this->records());
        }
        if ($method === 'token') {
            $this->postJson('/auth/workflow-tasks/missing-task/claim', [
                'lease_owner' => 'worker-a',
            ], [
                'X-Workflow-Token' => 'wrong-test-token',
            ])->assertStatus(401)
                ->assertJsonPath('message', 'Unauthorized');
            $this->assertSame($before, $this->records());
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function acceptedAuthentication(): iterable
    {
        foreach (['none', 'token', 'signature', 'custom'] as $method) {
            yield $method => [$method];
        }
    }

    public function testTheRequestReturnedByCustomAuthenticationIsUsedForInputValidation(): void
    {
        config([
            'workflows.webhook_auth.method' => 'custom',
            'workflows.webhook_auth.custom.class' => ReplacingWebhookRequestAuthenticator::class,
        ]);
        $before = $this->records();

        $this->postJson('/auth/workflow-tasks/' . $this->task->id . '/claim', [
            'lease_owner' => 'worker-a',
        ])
            ->assertStatus(422)
            ->assertJsonPath(
                'errors.lease_owner',
                ['The lease_owner field must be a non-empty string up to 255 characters.']
            );

        $this->assertSame($before, $this->records());
        Queue::assertNothingPushed();
    }

    /**
     * @param array<string, mixed> $configuration
     */
    private function assertDeniedWithoutDatabaseAccess(string $method, string $path, array $configuration): void
    {
        config($configuration);
        $before = $this->records();
        $queries = [];
        $observing = false;
        DB::listen(static function (QueryExecuted $event) use (&$queries, &$observing): void {
            if ($observing) {
                $queries[] = $event->sql;
            }
        });

        foreach ([1, 2] as $_) {
            $observing = true;
            $response = $method === 'GET'
                ? $this->getJson($path)
                : $this->postJson($path, [
                    'lease_owner' => 42,
                    'commands' => false,
                    'arguments' => false,
                    'workflow_id' => $this->run->workflow_instance_id,
                    'name' => 'Ada',
                ]);
            $observing = false;

            $response->assertStatus(401)
                ->assertJsonPath('message', 'Unauthorized');
            $this->assertSame([], $queries);
            $this->assertSame($before, $this->records());
            $this->assertSame(0, WebhookRegistrationProbeWorkflow::$calls);
            Queue::assertNothingPushed();
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
            WorkflowSignal::class,
            WorkflowUpdate::class,
            ActivityExecution::class,
            ActivityAttempt::class,
        ] as $model) {
            $records[$model] = $model::query()->orderBy('id')->get()->map(
                static fn (Model $row): array => $row->getRawOriginal()
            )->all();
        }

        return $records;
    }
}

#[Type('registered.type_v2')]
final class WebhookRegistrationProbeWorkflow extends Workflow
{
    public static int $calls = 0;

    public function handle(string $name): string
    {
        ++self::$calls;

        return $name;
    }
}

final class UntypedWebhookProbeWorkflow extends Workflow
{
    public function handle(string $name): string
    {
        return $name;
    }
}

final class WebhookRequestAuthenticator implements WebhookAuthenticator
{
    public static int $calls = 0;

    public function validate(Request $request): Request
    {
        ++self::$calls;
        if ($request->header('X-Test-Auth') !== 'accepted') {
            abort(401, 'Unauthorized');
        }

        return $request;
    }
}

final class ReplacingWebhookRequestAuthenticator implements WebhookAuthenticator
{
    public function validate(Request $request): Request
    {
        return Request::create($request->getPathInfo(), $request->getMethod(), [
            'lease_owner' => 42,
        ]);
    }
}
