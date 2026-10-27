<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Workflow\V2\Contracts\ServiceBoundaryPolicy;
use Workflow\V2\Contracts\WorkflowControlPlane;
use Workflow\V2\Models\WorkflowService;
use Workflow\V2\Models\WorkflowServiceCall;
use Workflow\V2\Models\WorkflowServiceEndpoint;
use Workflow\V2\Models\WorkflowServiceOperation;
use Workflow\V2\Support\DefaultServiceControlPlane;
use Workflow\V2\Support\ServiceCallPrincipal;
use Workflow\V2\Support\UpdateWaitPolicy;

final class ServiceCallUpdateWaitValidationTest extends TestCase
{
    #[DataProvider('completionWaits')]
    public function testZeroCompletionWaitIsRefusedBeforeCreatingACall(string $mode, ?string $waitFor): void
    {
        $this->catalog($mode);
        $options = $this->executionOptions($waitFor, false);
        $boundary = Mockery::mock(ServiceBoundaryPolicy::class);
        $boundary->shouldNotReceive('evaluate');
        $observed = 0;
        $workflows = Mockery::mock(WorkflowControlPlane::class);
        $workflows->shouldReceive('update')
            ->andReturnUsing(static function (string $instance, string $name, array $options) use (&$observed): array {
                $observed++;
                if (($options['wait_for'] ?? 'completed') === 'completed') {
                    UpdateWaitPolicy::requestedTimeoutSeconds($options['wait_timeout_seconds'] ?? null);
                }

                return [
                    'accepted' => true,
                    'workflow_instance_id' => 'target',
                    'run_id' => 'run',
                    'update_id' => 'update',
                    'update_status' => 'accepted',
                ];
            });
        $refused = false;
        try {
            (new DefaultServiceControlPlane($workflows, $boundary))->execute(
                'billing',
                'invoices',
                'approve',
                $options
            );
        } catch (ValidationException $failure) {
            $refused = true;
            $this->assertArrayHasKey('wait_timeout_seconds', $failure->errors());
        }
        $this->assertSame(
            0,
            WorkflowServiceCall::query()->count(),
            'An invalid completion wait must not create an audit/admission row.'
        );
        $this->assertSame(0, $observed, 'Input validation must precede the handler.');
        $this->assertTrue($refused, 'A zero completion wait requires a typed input refusal.');
    }

    public static function completionWaits(): iterable
    {
        yield 'sync default' => ['sync', null];
        yield 'sync explicit' => ['sync', 'completed'];
        yield 'async explicit' => ['async', 'completed'];
        yield 'sync padded' => ['sync', ' completed '];
        yield 'sync blank' => ['sync', ''];
    }

    #[DataProvider('acceptedWaits')]
    public function testZeroAcceptedWaitRemainsBoundedAndAnExistingKeyKeepsItsCall(
        string $mode,
        bool $keyed,
        ?string $waitFor,
    ): void {
        $this->catalog($mode);
        $workflows = Mockery::mock(WorkflowControlPlane::class);
        $workflows->shouldReceive('update')
            ->once()
            ->withArgs(static fn (string $instance, string $name, array $options): bool =>
                $options['wait_for'] === 'accepted' && ($keyed
                    ? ! array_key_exists('wait_timeout_seconds', $options)
                    : $options['wait_timeout_seconds'] === 0))
            ->andReturn([
                'accepted' => true,
                'workflow_instance_id' => 'target',
                'run_id' => 'run',
                'update_id' => 'update',
                'update_status' => 'accepted',
            ]);
        $control = new DefaultServiceControlPlane($workflows);
        $result = $control->execute('billing', 'invoices', 'approve', $this->executionOptions($waitFor, $keyed));
        $this->assertTrue($result['accepted']);
        $this->assertSame(1, WorkflowServiceCall::query()->count());
        if ($keyed) {
            $replay = $control->execute('billing', 'invoices', 'approve', $this->executionOptions('completed', true));
            $this->assertTrue($replay['idempotent_replay']);
            $this->assertSame($result['service_call_id'], $replay['service_call_id']);
            $this->assertSame(1, WorkflowServiceCall::query()->count());
        }
    }

    public static function acceptedWaits(): iterable
    {
        foreach (['sync', 'async'] as $mode) {
            yield $mode . ' unkeyed' => [$mode, false, 'accepted'];
            foreach (['accepted', 'completed', ' completed ', '', null] as $waitFor) {
                yield $mode . ' keyed ' . var_export($waitFor, true) => [$mode, true, $waitFor];
            }
        }
    }

    public function testAnExistingTransactionKeepsZeroWaitAcceptedOnly(): void
    {
        $this->catalog('sync');
        $workflows = Mockery::mock(WorkflowControlPlane::class);
        $workflows->shouldReceive('update')
            ->once()
            ->withArgs(static fn (string $instance, string $name, array $options): bool =>
                $options['wait_for'] === 'accepted' && ! array_key_exists('wait_timeout_seconds', $options))
            ->andReturn([
                'accepted' => true,
                'workflow_instance_id' => 'target',
                'run_id' => 'run',
                'update_id' => 'update',
                'update_status' => 'accepted',
            ]);
        $result = DB::transaction(fn (): array => (new DefaultServiceControlPlane($workflows))->execute(
            'billing',
            'invoices',
            'approve',
            $this->executionOptions('completed', false)
        ));
        $this->assertTrue($result['accepted']);
        $this->assertSame(1, WorkflowServiceCall::query()->count());
    }

    public function testAnAsyncDefaultZeroWaitStillAdmitsItsUpdate(): void
    {
        $this->catalog('async');
        $workflows = Mockery::mock(WorkflowControlPlane::class);
        $workflows->shouldReceive('update')
            ->once()
            ->andReturn([
                'accepted' => true,
                'workflow_instance_id' => 'target',
                'run_id' => 'run',
                'update_id' => 'update',
                'update_status' => 'accepted',
            ]);
        $result = (new DefaultServiceControlPlane($workflows))->execute(
            'billing',
            'invoices',
            'approve',
            $this->executionOptions(null, false)
        );
        $this->assertTrue($result['accepted']);
        $this->assertSame(1, WorkflowServiceCall::query()->count());
    }

    public function testAQueryCompletionZeroWaitKeepsItsExistingContract(): void
    {
        $this->catalog('async');
        WorkflowServiceOperation::query()->sole()->update([
            'handler_binding_kind' => 'query_workflow',
        ]);
        $workflows = Mockery::mock(WorkflowControlPlane::class);
        $workflows->shouldReceive('query')
            ->once()
            ->andReturn([
                'success' => true,
                'result' => 7,
            ]);
        $result = (new DefaultServiceControlPlane($workflows))->execute(
            'billing',
            'invoices',
            'approve',
            $this->executionOptions('completed', false)
        );
        $this->assertSame('completed', $result['status']);
        $this->assertSame(1, WorkflowServiceCall::query()->count());
    }

    public function testUnresolvedCatalogStillRecordsItsExistingResolutionFailure(): void
    {
        $result = (new DefaultServiceControlPlane())->execute(
            'billing',
            'invoices',
            'approve',
            $this->executionOptions('completed', false)
        );
        $this->assertSame('endpoint_not_found', $result['reason']);
        $this->assertSame(1, WorkflowServiceCall::query()->count());
    }

    public function testCatalogChangesCannotReplaceTheBindingWhoseWaitWasValidated(): void
    {
        $this->catalog('async');
        $operation = WorkflowServiceOperation::query()->sole();
        $operation->update([
            'handler_binding_kind' => 'query_workflow',
        ]);
        $retargeted = false;
        DB::connection()->beforeExecuting(static function (string $sql) use ($operation, &$retargeted): void {
            if ($retargeted || ! str_starts_with(
                str_replace(['"', '`'], '', $sql),
                'insert into workflow_service_calls'
            )) {
                return;
            }
            $retargeted = true;
            WorkflowServiceOperation::query()->whereKey($operation->id)->update([
                'handler_binding_kind' => 'update_workflow',
            ]);
        });
        $workflows = Mockery::mock(WorkflowControlPlane::class);
        $workflows->shouldReceive('query')
            ->once()
            ->andReturn([
                'success' => true,
                'result' => 7,
            ]);
        $result = (new DefaultServiceControlPlane($workflows))->execute(
            'billing',
            'invoices',
            'approve',
            $this->executionOptions('completed', false)
        );
        $this->assertTrue($retargeted);
        $this->assertSame('completed', $result['status']);
        $this->assertSame('workflow_query', $result['resolved_binding_kind']);
    }

    private function catalog(string $mode): void
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
        WorkflowServiceOperation::query()->create([
            'namespace' => 'billing',
            'workflow_service_endpoint_id' => $endpoint->id,
            'workflow_service_id' => $service->id,
            'operation_name' => 'approve',
            'operation_mode' => $mode,
            'handler_binding_kind' => 'update_workflow',
            'handler_target_reference' => 'approve',
            'handler_binding' => [
                'workflow_instance_id' => 'target',
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function executionOptions(?string $waitFor, bool $keyed): array
    {
        $options = [
            'namespace' => 'billing',
            'caller_namespace' => 'client',
            'principal' => new ServiceCallPrincipal(subject: 'client', method: 'token', roles: ['worker']),
            'wait_timeout_seconds' => 0,
        ];
        if ($waitFor !== null) {
            $options['wait_for'] = $waitFor;
        }
        if ($keyed) {
            $options['idempotency_key'] = 'update-wait';
        }

        return $options;
    }
}
