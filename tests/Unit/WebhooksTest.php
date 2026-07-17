<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Mockery;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Fixtures\TestAuthenticator;
use Tests\TestCase;
use Workflow\Models\StoredWorkflow;
use Workflow\States\WorkflowPendingStatus;
use Workflow\Webhook;
use Workflow\Webhooks;

final class WebhooksTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Webhooks::routes('Tests\\Fixtures', __DIR__ . '/../Fixtures');
    }

    public function testScanDirectoryFindsPhpFiles()
    {
        $webhooksReflection = new ReflectionClass(Webhooks::class);
        $method = $webhooksReflection->getMethod('scanDirectory');
        $method->setAccessible(true);

        $files = $method->invoke(null, __DIR__ . '/../Fixtures');

        $this->assertIsArray($files);
        $this->assertTrue(count($files) > 0);
    }

    public function testDiscoverWorkflowsNotAFolder()
    {
        $webhooksReflection = new ReflectionClass(Webhooks::class);
        $method = $webhooksReflection->getMethod('discoverWorkflows');
        $method->setAccessible(true);

        $files = $method->invoke(null, 'namespace', 'does-not-exist');

        $this->assertIsArray($files);
        $this->assertTrue(count($files) === 0);
    }

    public function testGetClassFromFile()
    {
        $webhooksReflection = new ReflectionClass(Webhooks::class);
        $method = $webhooksReflection->getMethod('getClassFromFile');
        $method->setAccessible(true);

        $namespace = 'Tests\\Fixtures';
        $basePath = __DIR__ . '/../Fixtures';
        $filePath = __DIR__ . '/../Fixtures/TestWorkflow.php';

        $class = $method->invoke(null, $filePath, $namespace, $basePath);

        $this->assertEquals('Tests\\Fixtures\\TestWorkflow', $class);
    }

    public function testHasWebhookAttributeOnclassReturnsTrue()
    {
        $mockMethod = Mockery::mock(ReflectionClass::class);
        $mockMethod->shouldReceive('getAttributes')
            ->andReturn([new Webhook()]);

        $webhooksReflection = new ReflectionClass(Webhooks::class);
        $method = $webhooksReflection->getMethod('hasWebhookAttributeOnclass');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke(null, $mockMethod));
    }

    public function testHasWebhookAttributeOnclassReturnsFalse()
    {
        $mockMethod = Mockery::mock(ReflectionClass::class);
        $mockMethod->shouldReceive('getAttributes')
            ->andReturn([]);

        $webhooksReflection = new ReflectionClass(Webhooks::class);
        $method = $webhooksReflection->getMethod('hasWebhookAttributeOnclass');
        $method->setAccessible(true);

        $this->assertFalse($method->invoke(null, $mockMethod));
    }

    public function testHasWebhookAttributeOnMethodReturnsTrue()
    {
        $mockMethod = Mockery::mock(ReflectionMethod::class);
        $mockMethod->shouldReceive('getAttributes')
            ->andReturn([new Webhook()]);

        $webhooksReflection = new ReflectionClass(Webhooks::class);
        $method = $webhooksReflection->getMethod('hasWebhookAttributeOnMethod');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke(null, $mockMethod));
    }

    public function testHasWebhookAttributeOnMethodReturnsFalse()
    {
        $mockMethod = Mockery::mock(ReflectionMethod::class);
        $mockMethod->shouldReceive('getAttributes')
            ->andReturn([]);

        $webhooksReflection = new ReflectionClass(Webhooks::class);
        $method = $webhooksReflection->getMethod('hasWebhookAttributeOnMethod');
        $method->setAccessible(true);

        $this->assertFalse($method->invoke(null, $mockMethod));
    }

    public function testValidatesAuthForNone()
    {
        config([
            'workflows.webhook_auth.method' => 'none',
        ]);

        $request = Request::create('/webhooks/start/test-webhook-workflow', 'POST');

        $webhooksReflection = new ReflectionClass(Webhooks::class);
        $method = $webhooksReflection->getMethod('validateAuth');
        $method->setAccessible(true);

        $this->assertInstanceOf(Request::class, $method->invoke(null, $request));
    }

    public function testValidatesAuthForTokenSuccess()
    {
        config([
            'workflows.webhook_auth.method' => 'token',
            'workflows.webhook_auth.token.token' => 'valid-token',
            'workflows.webhook_auth.token.header' => 'Authorization',
        ]);

        $request = Request::create('/webhooks/start/test-webhook-workflow', 'POST', [], [], [], [
            'HTTP_AUTHORIZATION' => 'valid-token',
        ]);

        $webhooksReflection = new ReflectionClass(Webhooks::class);
        $method = $webhooksReflection->getMethod('validateAuth');
        $method->setAccessible(true);

        $this->assertInstanceOf(Request::class, $method->invoke(null, $request));
    }

    public function testValidatesAuthForTokenFailure()
    {
        $this->expectException(HttpException::class);

        config([
            'workflows.webhook_auth.method' => 'token',
            'workflows.webhook_auth.token.token' => 'valid-token',
            'workflows.webhook_auth.token.header' => 'Authorization',
        ]);

        $request = Request::create('/webhooks/start/test-webhook-workflow', 'POST', [], [], [], [
            'HTTP_AUTHORIZATION' => 'invalid-token',
        ]);

        $webhooksReflection = new ReflectionClass(Webhooks::class);
        $method = $webhooksReflection->getMethod('validateAuth');
        $method->setAccessible(true);

        $method->invoke(null, $request);
    }

    public function testValidatesAuthForSignatureSuccess()
    {
        config([
            'workflows.webhook_auth.method' => 'signature',
            'workflows.webhook_auth.signature.secret' => 'test-secret',
            'workflows.webhook_auth.signature.header' => 'X-Signature',
        ]);

        $payload = json_encode([
            'data' => 'test',
        ]);
        $signature = hash_hmac('sha256', $payload, 'test-secret');

        $request = Request::create('/webhooks/start/test-webhook-workflow', 'POST', [], [], [], [
            'HTTP_X_SIGNATURE' => $signature,
        ], $payload);

        $webhooksReflection = new ReflectionClass(Webhooks::class);
        $method = $webhooksReflection->getMethod('validateAuth');
        $method->setAccessible(true);

        $this->assertInstanceOf(Request::class, $method->invoke(null, $request));
    }

    public function testValidatesAuthForSignatureFailure()
    {
        $this->expectException(HttpException::class);

        config([
            'workflows.webhook_auth.method' => 'signature',
            'workflows.webhook_auth.signature.secret' => 'test-secret',
            'workflows.webhook_auth.signature.header' => 'X-Signature',
        ]);

        $payload = json_encode([
            'data' => 'test',
        ]);
        $invalidSignature = 'invalid-signature';

        $request = Request::create('/webhooks/start/test-webhook-workflow', 'POST', [], [], [], [
            'HTTP_X_SIGNATURE' => $invalidSignature,
        ], $payload);

        $webhooksReflection = new ReflectionClass(Webhooks::class);
        $method = $webhooksReflection->getMethod('validateAuth');
        $method->setAccessible(true);

        $method->invoke(null, $request);
    }

    public function testValidatesAuthForCustomSuccess()
    {
        config([
            'workflows.webhook_auth.method' => 'custom',
            'workflows.webhook_auth.custom.class' => TestAuthenticator::class,
        ]);

        $request = Request::create('/webhooks/start/test-webhook-workflow', 'POST', [], [], [], [
            'HTTP_AUTHORIZATION' => 'valid-token',
        ]);

        $webhooksReflection = new ReflectionClass(Webhooks::class);
        $method = $webhooksReflection->getMethod('validateAuth');
        $method->setAccessible(true);

        $this->assertInstanceOf(Request::class, $method->invoke(null, $request));
    }

    public function testValidatesAuthForCustomFailure()
    {
        $this->expectException(HttpException::class);

        config([
            'workflows.webhook_auth.method' => 'custom',
            'workflows.webhook_auth.custom.class' => TestAuthenticator::class,
        ]);

        $request = Request::create('/webhooks/start/test-webhook-workflow', 'POST');

        $webhooksReflection = new ReflectionClass(Webhooks::class);
        $method = $webhooksReflection->getMethod('validateAuth');
        $method->setAccessible(true);

        $method->invoke(null, $request);
    }

    public function testResolveNamedParameters()
    {
        $payload = [
            'param1' => 'value1',
            'param2' => 'value2',
        ];

        $webhooksReflection = new ReflectionClass(Webhooks::class);
        $method = $webhooksReflection->getMethod('resolveNamedParameters');
        $method->setAccessible(true);

        $params = $method->invoke(null, TestClass::class, 'testMethod', $payload);

        $this->assertSame([
            'param1' => 'value1',
            'param2' => 'value2',
        ], $params);
    }

    public function testUnauthorizedRequestFails()
    {
        $this->expectException(HttpException::class);

        config([
            'workflows.webhook_auth.method' => 'unsupported',
        ]);

        $request = Request::create('/webhooks/start/test-webhook-workflow', 'POST');

        $webhooksReflection = new ReflectionClass(Webhooks::class);
        $method = $webhooksReflection->getMethod('validateAuth');
        $method->setAccessible(true);

        $method->invoke(null, $request);
    }

    public function testResolveNamedParametersUsesDefaults()
    {
        $payload = [
            'param1' => 'value1',
        ];

        $webhooksReflection = new ReflectionClass(Webhooks::class);
        $method = $webhooksReflection->getMethod('resolveNamedParameters');
        $method->setAccessible(true);

        $params = $method->invoke(null, TestClass::class, 'testMethodWithDefault', $payload);

        $this->assertSame([
            'param1' => 'value1',
            'param2' => 'default_value',
        ], $params);
    }

    public function testWebhookRegistration()
    {
        $routeMock = Mockery::mock();
        $routeMock->shouldReceive('name')
            ->andReturnSelf();

        Route::shouldReceive('post')
            ->times(4)
            ->andReturn($routeMock);

        Webhooks::routes('Tests\\Fixtures', __DIR__ . '/../Fixtures');
    }

    public function testStartAndSignal(): void
    {
        config([
            'workflows.webhook_auth.method' => 'none',
        ]);

        $response = $this->postJson('/webhooks/start/test-webhook-workflow');

        $this->assertSame(1, StoredWorkflow::count());

        $response->assertStatus(200);
        $response->assertJson([
            'message' => 'Workflow started',
        ]);

        $response = $this->postJson('/webhooks/signal/test-webhook-workflow/1/cancel');

        $response->assertStatus(200);
        $response->assertJson([
            'message' => 'Signal sent',
        ]);

        $workflow = \Workflow\WorkflowStub::load(1);

        $this->assertSame(WorkflowPendingStatus::class, $workflow->status());
    }

    public function testStartUnauthorized(): void
    {
        config([
            'workflows.webhook_auth.method' => 'invalid',
        ]);

        $response = $this->postJson('/webhooks/start/test-webhook-workflow');

        $response->assertStatus(401);
        $response->assertJson([
            'message' => 'Unauthorized',
        ]);
    }

    public function testSignalUnauthorized(): void
    {
        config([
            'workflows.webhook_auth.method' => 'none',
        ]);

        $response = $this->postJson('/webhooks/start/test-webhook-workflow');

        $this->assertSame(1, StoredWorkflow::count());

        $response->assertStatus(200);
        $response->assertJson([
            'message' => 'Workflow started',
        ]);

        config([
            'workflows.webhook_auth.method' => 'invalid',
        ]);

        $response = $this->postJson('/webhooks/signal/test-webhook-workflow/1/cancel');

        $response->assertStatus(401);
        $response->assertJson([
            'message' => 'Unauthorized',
        ]);
    }

    public function testWebhookRoutesCanBeSerializedForRouteCaching()
    {
        $webhookRoutes = [];

        foreach (Route::getRoutes() as $route) {
            if (str_starts_with((string) $route->getName(), 'workflows.')) {
                $webhookRoutes[] = $route;
            }
        }

        $this->assertNotEmpty($webhookRoutes);

        foreach ($webhookRoutes as $route) {
            // Mirrors what `php artisan route:cache` does. Closures must only
            // capture serializable values (no ReflectionMethod instances).
            $route->prepareForSerialization();
            serialize($route);
        }
    }
}

class TestClass
{
    public function testMethod($param1, $param2)
    {
    }

    public function testMethodWithDefault($param1, $param2 = 'default_value')
    {
    }
}
