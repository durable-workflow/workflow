<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Tests\TestCase;
use Workflow\V2\Enums\ServiceCallBindingKind;
use Workflow\V2\Enums\ServiceCallOperationMode;
use Workflow\V2\Enums\ServiceCallOutcome;
use Workflow\V2\Support\DefaultServiceBoundaryPolicy;
use Workflow\V2\Support\ServiceBoundaryRequest;
use Workflow\V2\Support\ServiceCallPrincipal;

/**
 * Coverage for the cross-namespace service-boundary policy contract.
 *
 * Each test exercises one gate so a future regression points at one
 * specific fence (authz, namespace policy, circuit-break, concurrency,
 * rate-limit) rather than at "the boundary".
 */
final class DefaultServiceBoundaryPolicyTest extends TestCase
{
    public function test_default_rules_allow_a_well_formed_call(): void
    {
        $decision = (new DefaultServiceBoundaryPolicy())
            ->evaluate($this->request());

        $this->assertSame(ServiceCallOutcome::Accepted, $decision->outcome);
        $this->assertTrue($decision->isAllowed());
        $this->assertSame('accepted', $decision->reason);
        $this->assertSame(DefaultServiceBoundaryPolicy::POLICY_NAME, $decision->policyName);
    }

    public function test_authorization_denial_short_circuits_remaining_gates(): void
    {
        $policy = new DefaultServiceBoundaryPolicy([
            'authorization' => ['required_roles' => ['service.call']],
            'rate_limit' => ['requests_per_minute' => 1],
        ]);

        $decision = $policy->evaluate($this->request(roles: ['workflow.read']));

        $this->assertSame(ServiceCallOutcome::RejectedForbidden, $decision->outcome);
        $this->assertSame('missing_required_role', $decision->reason);
        $this->assertSame('rejected', $decision->outcome->category());
        $this->assertTrue($decision->isDenied());
    }

    public function test_explicit_caller_namespace_deny(): void
    {
        $policy = new DefaultServiceBoundaryPolicy([
            'namespaces' => ['deny_callers' => ['untrusted']],
        ]);

        $decision = $policy->evaluate($this->request(callerNamespace: 'untrusted'));

        $this->assertSame(ServiceCallOutcome::RejectedForbidden, $decision->outcome);
        $this->assertSame('caller_namespace_denied', $decision->reason);
    }

    public function test_caller_not_in_allow_list_is_blocked(): void
    {
        $policy = new DefaultServiceBoundaryPolicy([
            'namespaces' => ['allow_callers' => ['analytics']],
        ]);

        $decision = $policy->evaluate($this->request(callerNamespace: 'finance'));

        $this->assertSame(ServiceCallOutcome::RejectedForbidden, $decision->outcome);
        $this->assertSame('caller_namespace_not_allowed', $decision->reason);
    }

    public function test_cross_namespace_default_deny_blocks_unequal_namespaces(): void
    {
        $policy = new DefaultServiceBoundaryPolicy([
            'namespaces' => ['cross_namespace_default' => 'deny'],
        ]);

        $decision = $policy->evaluate($this->request(
            callerNamespace: 'finance',
            targetNamespace: 'billing',
        ));

        $this->assertSame(ServiceCallOutcome::RejectedForbidden, $decision->outcome);
        $this->assertSame('cross_namespace_blocked', $decision->reason);
    }

    public function test_circuit_open_blocks_target_with_retry_advice(): void
    {
        $request = $this->request();

        $policy = new DefaultServiceBoundaryPolicy([
            'circuit_break' => [
                'open_targets' => [$request->targetKey()],
                'retry_after_seconds' => 30,
            ],
        ]);

        $decision = $policy->evaluate($request);

        $this->assertSame(ServiceCallOutcome::RejectedCircuitOpen, $decision->outcome);
        $this->assertSame(30, $decision->retryAfterSeconds);
        $this->assertSame($request->targetKey(), $decision->metadata['target_key']);
    }

    public function test_concurrency_limit_blocks_excess_sync_calls(): void
    {
        $policy = new DefaultServiceBoundaryPolicy([
            'concurrency' => ['max_in_flight' => 2, 'sync_only' => true],
        ]);

        $first = $policy->evaluate($this->request(mode: ServiceCallOperationMode::Sync));
        $second = $policy->evaluate($this->request(mode: ServiceCallOperationMode::Sync));
        $third = $policy->evaluate($this->request(mode: ServiceCallOperationMode::Sync));

        $this->assertTrue($first->isAllowed());
        $this->assertTrue($second->isAllowed());
        $this->assertSame(ServiceCallOutcome::RejectedConcurrencyLimited, $third->outcome);
        $this->assertSame(2, $third->metadata['max_in_flight']);
        $this->assertSame(2, $third->metadata['observed_in_flight']);
    }

    public function test_concurrency_sync_only_does_not_count_async_calls(): void
    {
        $policy = new DefaultServiceBoundaryPolicy([
            'concurrency' => ['max_in_flight' => 1, 'sync_only' => true],
        ]);

        $async1 = $policy->evaluate($this->request(mode: ServiceCallOperationMode::Async));
        $async2 = $policy->evaluate($this->request(mode: ServiceCallOperationMode::Async));
        $sync1 = $policy->evaluate($this->request(mode: ServiceCallOperationMode::Sync));
        $sync2 = $policy->evaluate($this->request(mode: ServiceCallOperationMode::Sync));

        $this->assertTrue($async1->isAllowed());
        $this->assertTrue($async2->isAllowed());
        $this->assertTrue($sync1->isAllowed());
        $this->assertSame(ServiceCallOutcome::RejectedConcurrencyLimited, $sync2->outcome);
    }

    public function test_release_clears_in_flight_counter(): void
    {
        $policy = new DefaultServiceBoundaryPolicy([
            'concurrency' => ['max_in_flight' => 1, 'sync_only' => false],
        ]);

        $request = $this->request();
        $first = $policy->evaluate($request);
        $blocked = $policy->evaluate($request);
        $policy->release($request);
        $afterRelease = $policy->evaluate($request);

        $this->assertTrue($first->isAllowed());
        $this->assertSame(ServiceCallOutcome::RejectedConcurrencyLimited, $blocked->outcome);
        $this->assertTrue($afterRelease->isAllowed());
    }

    public function test_rate_limit_blocks_after_window_capacity_reached(): void
    {
        $policy = new DefaultServiceBoundaryPolicy([
            'rate_limit' => ['requests_per_minute' => 2, 'retry_after_seconds' => 5],
        ]);

        $first = $policy->evaluate($this->request());
        $second = $policy->evaluate($this->request());
        $third = $policy->evaluate($this->request());

        $this->assertTrue($first->isAllowed());
        $this->assertTrue($second->isAllowed());
        $this->assertSame(ServiceCallOutcome::RejectedThrottled, $third->outcome);
        $this->assertSame(5, $third->retryAfterSeconds);
        $this->assertSame(2, $third->metadata['requests_per_minute']);
    }

    public function test_axis_allow_lists_permit_when_every_axis_allows_caller(): void
    {
        $policy = new DefaultServiceBoundaryPolicy();

        $decision = $policy->evaluate($this->request(
            callerNamespace: 'analytics',
            endpointBoundaryPolicy: [
                'authorization' => [
                    'caller_namespaces' => ['allow' => ['analytics']],
                ],
            ],
            serviceBoundaryPolicy: [
                'authorization' => [
                    'caller_namespaces' => ['allow' => ['analytics']],
                ],
            ],
            operationBoundaryPolicy: [
                'authorization' => [
                    'caller_namespaces' => ['allow' => ['analytics']],
                ],
            ],
        ));

        $this->assertTrue($decision->isAllowed());
    }

    public function test_endpoint_service_and_operation_policy_record_denied_axis(): void
    {
        $policy = new DefaultServiceBoundaryPolicy();

        $decision = $policy->evaluate($this->request(
            callerNamespace: 'analytics',
            operationBoundaryPolicy: [
                'authorization' => [
                    'caller_namespaces' => ['deny' => ['analytics']],
                ],
            ],
        ));

        $this->assertSame(ServiceCallOutcome::RejectedForbidden, $decision->outcome);
        $this->assertSame('operation', $decision->metadata['forbidden_axis']);
    }

    public function test_outcome_categories_map_consistently_for_audit_consumers(): void
    {
        $this->assertSame('accepted', ServiceCallOutcome::Accepted->category());
        $this->assertSame('rejected', ServiceCallOutcome::RejectedForbidden->category());
        $this->assertSame('rejected', ServiceCallOutcome::RejectedThrottled->category());
        $this->assertSame('rejected', ServiceCallOutcome::RejectedCircuitOpen->category());
        $this->assertSame('handler', ServiceCallOutcome::Completed->category());
        $this->assertSame('handler', ServiceCallOutcome::HandlerFailed->category());
    }

    /**
     * @param list<string> $roles
     */
    private function request(
        ?string $callerNamespace = 'finance',
        string $targetNamespace = 'finance',
        ServiceCallOperationMode $mode = ServiceCallOperationMode::Sync,
        array $roles = ['service.call'],
        array $endpointBoundaryPolicy = [],
        array $serviceBoundaryPolicy = [],
        array $operationBoundaryPolicy = [],
    ): ServiceBoundaryRequest {
        return new ServiceBoundaryRequest(
            principal: new ServiceCallPrincipal(
                subject: 'user:tester',
                method: 'token',
                roles: $roles,
                tenant: 'acme',
            ),
            callerNamespace: $callerNamespace,
            targetNamespace: $targetNamespace,
            endpointName: 'billing',
            serviceName: 'invoicing',
            operationName: 'create',
            operationMode: $mode,
            resolvedBindingKind: ServiceCallBindingKind::WorkflowUpdate->value,
            resolvedTargetReference: 'updates.invoice.create',
            endpointBoundaryPolicy: $endpointBoundaryPolicy,
            serviceBoundaryPolicy: $serviceBoundaryPolicy,
            operationBoundaryPolicy: $operationBoundaryPolicy,
        );
    }
}
