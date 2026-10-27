<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Workflow\V2\Exceptions\ServiceOperationAuthorizationException;
use Workflow\V2\Models\WorkflowService;
use Workflow\V2\Models\WorkflowServiceCall;
use Workflow\V2\Models\WorkflowServiceEndpoint;
use Workflow\V2\Models\WorkflowServiceOperation;
use Workflow\V2\Support\DefaultServiceControlPlane;
use Workflow\V2\Support\ServiceCallPrincipal;

final class HttpServiceCallReplayTest extends TestCase
{
    #[DataProvider('replayPaths')]
    public function testHttpReplayRefusesAnotherCallerWithoutChangingItsIssuedHandler(bool $explicit): void
    {
        $this->catalog('activity_execution');
        $control = new DefaultServiceControlPlane();
        $control->execute('billing', 'invoices', 'create', $this->executionOptions());
        $call = WorkflowServiceCall::query()->sole();
        $before = $call->getRawOriginal();
        try {
            $control->execute('billing', 'invoices', 'create', array_replace($this->executionOptions(), [
                'caller_namespace' => 'foreign',
            ], $explicit ? [
                'service_call_id' => $call->id,
            ] : []));
            $this->fail('An HTTP caller must not reuse another caller namespace\'s key.');
        } catch (ServiceOperationAuthorizationException $exception) {
            $this->assertSame('service_call_not_owned', $exception->getMessage());
        }
        $this->assertSame($before, $call->fresh()->getRawOriginal());
        $this->assertSame(1, WorkflowServiceCall::query()->count());
    }

    #[DataProvider('handlerReplayPaths')]
    public function testIssuedHandlerIdentityIsImmutableOnEveryReplayPath(string $kind, bool $explicit): void
    {
        $operation = $this->catalog($kind);
        $control = new DefaultServiceControlPlane();
        $control->execute('billing', 'invoices', 'create', $this->executionOptions());
        $call = WorkflowServiceCall::query()->sole();
        $this->assertSame('accepted', $call->status);
        $before = $call->getRawOriginal();
        $operation->forceFill([
            'handler_target_reference' => 'replacement-target',
        ])->save();
        $result = $control->execute('billing', 'invoices', 'create', array_replace(
            $this->executionOptions(),
            $explicit ? [
                'service_call_id' => $call->id,
            ] : [],
        ));
        $this->assertTrue($result['accepted']);
        $this->assertSame($call->id, $result['service_call_id']);
        $this->assertSame($before, $call->fresh()->getRawOriginal());
        $this->assertSame(1, WorkflowServiceCall::query()->count());
    }

    #[DataProvider('replayPaths')]
    public function testHttpReplayChecksCurrentPolicyWithoutReadmittingTheCall(bool $explicit): void
    {
        $operation = $this->catalog('activity_execution');
        $control = new DefaultServiceControlPlane();
        $control->execute('billing', 'invoices', 'create', $this->executionOptions());
        $call = WorkflowServiceCall::query()->sole();
        $before = $call->getRawOriginal();
        $operation->forceFill([
            'boundary_policy' => [
                'deny_callers' => ['client'],
            ],
        ])->save();
        $this->expectException(ServiceOperationAuthorizationException::class);
        try {
            $control->execute('billing', 'invoices', 'create', array_replace(
                $this->executionOptions(),
                $explicit ? [
                    'service_call_id' => $call->id,
                ] : [],
            ));
        } finally {
            $this->assertSame($before, $call->fresh()->getRawOriginal());
        }
    }

    public function testAnAdmittedRowWithoutAnIssuedHandlerCanStillDispatchExactlyOnce(): void
    {
        $this->catalog('activity_execution');
        $control = new DefaultServiceControlPlane();
        $admitted = $control->execute('billing', 'invoices', 'create', $this->executionOptions() + [
            'dispatch_handler' => false,
        ]);
        $call = WorkflowServiceCall::query()->sole();
        $this->assertArrayNotHasKey('activity_execution_id', $call->metadata ?? []);
        $options = $this->executionOptions() + [
            'service_call_id' => $admitted['service_call_id'],
        ];
        $result = $control->execute('billing', 'invoices', 'create', $options);
        $this->assertTrue($result['accepted']);
        $call->refresh();
        $this->assertNotEmpty($call->metadata['activity_execution_id']);
        $issued = $call->getRawOriginal();
        $control->execute('billing', 'invoices', 'create', $options);
        $this->assertSame($issued, $call->fresh()->getRawOriginal());
    }

    public static function replayPaths(): iterable
    {
        yield 'key' => [false];
        yield 'explicit call' => [true];
    }

    public static function handlerReplayPaths(): iterable
    {
        foreach (['activity_execution', 'invocable_carrier_request'] as $kind) {
            foreach ([false, true] as $explicit) {
                yield $kind . '-' . (int) $explicit => [$kind, $explicit];
            }
        }
    }

    private function executionOptions(): array
    {
        return [
            'namespace' => 'billing',
            'caller_namespace' => 'client',
            'principal' => new ServiceCallPrincipal(subject: 'http-client', method: 'token', roles: ['worker']),
            'idempotency_key' => 'stable-key',
        ];
    }

    private function catalog(string $kind): WorkflowServiceOperation
    {
        $endpoint = WorkflowServiceEndpoint::query()->create([
            'namespace' => 'billing',
            'endpoint_name' => 'billing',
        ]);
        $service = WorkflowService::query()->create([
            'namespace' => 'billing',
            'workflow_service_endpoint_id' => $endpoint->id,
            'service_name' => 'invoices',
        ]);
        return WorkflowServiceOperation::query()->create([
            'namespace' => 'billing',
            'workflow_service_endpoint_id' => $endpoint->id,
            'workflow_service_id' => $service->id,
            'operation_name' => 'create',
            'operation_mode' => 'async',
            'handler_binding_kind' => $kind,
            'handler_target_reference' => 'original-target',
        ]);
    }
}
