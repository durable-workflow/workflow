<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Tests\Fixtures\TestActivity;
use Tests\Fixtures\TestOtherActivity;
use Tests\TestCase;
use Workflow\Models\StoredWorkflow;
use Workflow\Signal;
use Workflow\States\WorkflowCompletedStatus;
use Workflow\States\WorkflowWaitingStatus;
use Workflow\Webhooks;
use Workflow\WorkflowStub;

final class WebhookWorkflowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'workflows.webhook_auth.method' => 'none',
        ]);

        Webhooks::routes('Tests\\Fixtures', __DIR__ . '/../Fixtures');
    }

    public function testWebhookRoutesCanBeSerializedForRouteCaching(): void
    {
        $routes = Route::getRoutes();
        $routeNames = array_map(
            static fn (RoutingRoute $route): ?string => $route->getName(),
            $routes->getRoutes()
        );

        $this->assertContains('workflows.start.test-workflow', $routeNames);
        $this->assertContains('workflows.signal.test-workflow.cancel', $routeNames);

        foreach ($routes as $route) {
            if (! str_starts_with((string) $route->getName(), 'workflows.')) {
                continue;
            }

            /** @var RoutingRoute $route */
            $route->prepareForSerialization();

            $this->assertIsString(serialize($route));
        }
    }

    public function testStart(): void
    {
        $response = $this->postJson('/webhooks/start/test-webhook-workflow');

        $this->assertSame(1, StoredWorkflow::count());

        $response->assertStatus(200);
        $response->assertJson([
            'message' => 'Workflow started',
        ]);

        $workflow = WorkflowStub::load(1);

        $this->waitForWorkflow(
            $workflow,
            static fn (WorkflowStub $workflow): bool => $workflow->status() === WorkflowWaitingStatus::class,
            'the webhook workflow to await cancellation',
        );

        $workflow->cancel();

        $this->waitForWorkflow(
            $workflow,
            static fn (WorkflowStub $workflow): bool => $workflow->isCanceled(),
            'the webhook workflow cancel signal to be observed',
        );

        $this->waitForWorkflow($workflow);

        $this->assertSame(WorkflowCompletedStatus::class, $workflow->status());
        $this->assertSame('workflow_activity_other', $workflow->output());
        $this->assertSame([TestActivity::class, TestOtherActivity::class, Signal::class], $workflow->logs()
            ->pluck('class')
            ->sort()
            ->values()
            ->toArray());
    }

    public function testSignal(): void
    {
        $this->postJson('/webhooks/start/test-webhook-workflow');

        $this->assertSame(1, StoredWorkflow::count());

        $response = $this->postJson('/webhooks/signal/test-webhook-workflow/1/cancel');

        $response->assertStatus(200);
        $response->assertJson([
            'message' => 'Signal sent',
        ]);

        $workflow = WorkflowStub::load(1);

        $this->waitForWorkflow(
            $workflow,
            static fn (WorkflowStub $workflow): bool => $workflow->isCanceled(),
            'the webhook cancel signal to be observed',
        );

        $this->waitForWorkflow($workflow);

        $this->assertSame(WorkflowCompletedStatus::class, $workflow->status());
        $this->assertSame('workflow_activity_other', $workflow->output());
        $this->assertSame([TestActivity::class, TestOtherActivity::class, Signal::class], $workflow->logs()
            ->pluck('class')
            ->sort()
            ->values()
            ->toArray());
    }

    public function testNotFound(): void
    {
        config([
            'workflows.webhook_auth.method' => 'none',
        ]);

        $response = $this->postJson('/webhooks/start/does-not-exist');

        $response->assertStatus(404);
    }

    public function testSignatureAuth()
    {
        $secret = 'test-secret';
        $header = 'X-Signature';

        config([
            'workflows.webhook_auth.method' => 'signature',
            'workflows.webhook_auth.signature.secret' => $secret,
            'workflows.webhook_auth.signature.header' => $header,
        ]);

        $payload = json_encode([]);
        $validSignature = hash_hmac('sha256', $payload, $secret);

        $response = $this->postJson('/webhooks/start/test-webhook-workflow');

        $response->assertStatus(401);
        $response->assertJson([
            'message' => 'Unauthorized',
        ]);

        $response = $this->postJson('/webhooks/start/test-webhook-workflow', [], [
            $header => 'invalid-signature',
        ]);

        $response->assertStatus(401);
        $response->assertJson([
            'message' => 'Unauthorized',
        ]);

        $response = $this->postJson('/webhooks/start/test-webhook-workflow', [], [
            $header => $validSignature,
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'message' => 'Workflow started',
        ]);

        $this->assertSame(1, StoredWorkflow::count());

        $workflow = WorkflowStub::load(1);

        $workflow->cancel();

        $this->waitForWorkflow(
            $workflow,
            static fn (WorkflowStub $workflow): bool => $workflow->isCanceled(),
            'the signed webhook cancel signal to be observed',
        );

        $this->waitForWorkflow($workflow);

        $this->assertSame(WorkflowCompletedStatus::class, $workflow->status());
        $this->assertSame('workflow_activity_other', $workflow->output());
        $this->assertSame([TestActivity::class, TestOtherActivity::class, Signal::class], $workflow->logs()
            ->pluck('class')
            ->sort()
            ->values()
            ->toArray());
    }

    public function testTokenAuth()
    {
        $token = 'valid-token';
        $header = 'Authorization';

        config([
            'workflows.webhook_auth.method' => 'token',
            'workflows.webhook_auth.token.token' => $token,
            'workflows.webhook_auth.token.header' => $header,
        ]);

        $response = $this->postJson('/webhooks/start/test-webhook-workflow');

        $response->assertStatus(401);
        $response->assertJson([
            'message' => 'Unauthorized',
        ]);

        $response = $this->postJson('/webhooks/start/test-webhook-workflow', [], [
            $header => 'invalid-token',
        ]);

        $response->assertStatus(401);
        $response->assertJson([
            'message' => 'Unauthorized',
        ]);

        $response = $this->postJson('/webhooks/start/test-webhook-workflow', [], [
            $header => $token,
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'message' => 'Workflow started',
        ]);

        $this->assertSame(1, StoredWorkflow::count());

        $workflow = WorkflowStub::load(1);

        $workflow->cancel();

        $this->waitForWorkflow(
            $workflow,
            static fn (WorkflowStub $workflow): bool => $workflow->isCanceled(),
            'the token-authenticated webhook cancel signal to be observed',
        );

        $this->waitForWorkflow($workflow);

        $this->assertSame(WorkflowCompletedStatus::class, $workflow->status());
        $this->assertSame('workflow_activity_other', $workflow->output());
        $this->assertSame([TestActivity::class, TestOtherActivity::class, Signal::class], $workflow->logs()
            ->pluck('class')
            ->sort()
            ->values()
            ->toArray());
    }
}
