<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Workflow\V2\Enums\ServiceCallBindingKind;
use Workflow\V2\Enums\ServiceCallOutcome;
use Workflow\V2\Enums\ServiceCallStatus;
use Workflow\V2\Models\WorkflowService;
use Workflow\V2\Models\WorkflowServiceCall;
use Workflow\V2\Models\WorkflowServiceEndpoint;
use Workflow\V2\Models\WorkflowServiceOperation;
use Workflow\V2\Support\ServiceCallView;
use Workflow\V2\Support\ServiceCatalog;
use Workflow\V2\Support\ServiceEndpointView;
use Workflow\V2\Support\ServiceOperationView;
use Workflow\V2\Support\ServiceView;

class ServiceCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function testEndpointsQueryFiltersByNamespace(): void
    {
        $billing = $this->createEndpoint('billing', 'invoices');
        $shipping = $this->createEndpoint('shipping', 'invoices');

        $results = ServiceCatalog::endpointsQuery('billing')->get();

        $this->assertCount(1, $results);
        $this->assertSame($billing->id, $results->first()->id);
        $this->assertNotContains($shipping->id, $results->pluck('id')->all());
    }

    public function testEndpointsQueryWithoutNamespaceReturnsEverything(): void
    {
        $this->createEndpoint('billing', 'invoices');
        $this->createEndpoint('shipping', 'invoices');

        $results = ServiceCatalog::endpointsQuery(null)->get();

        $this->assertCount(2, $results);
    }

    public function testFindEndpointReturnsNullForOutOfNamespaceEndpoint(): void
    {
        $shipping = $this->createEndpoint('shipping', 'invoices');

        $this->assertNull(ServiceCatalog::findEndpoint($shipping->id, 'billing'));
        $this->assertSame($shipping->id, ServiceCatalog::findEndpoint($shipping->id, 'shipping')->id);
        $this->assertSame($shipping->id, ServiceCatalog::findEndpoint($shipping->id, null)->id);
    }

    public function testFindServiceReturnsNullForOutOfNamespaceService(): void
    {
        $endpoint = $this->createEndpoint('shipping', 'invoices');
        $service = $this->createService($endpoint, 'shipping', 'inbox');

        $this->assertNull(ServiceCatalog::findService($service->id, 'billing'));
        $this->assertSame($service->id, ServiceCatalog::findService($service->id, 'shipping')->id);
    }

    public function testFindOperationReturnsNullForOutOfNamespaceOperation(): void
    {
        $endpoint = $this->createEndpoint('shipping', 'invoices');
        $service = $this->createService($endpoint, 'shipping', 'inbox');
        $operation = $this->createOperation($endpoint, $service, 'shipping', 'create');

        $this->assertNull(ServiceCatalog::findOperation($operation->id, 'billing'));
        $this->assertSame($operation->id, ServiceCatalog::findOperation($operation->id, 'shipping')->id);
    }

    public function testFindServiceCallRequiresDurableNamespaceMatch(): void
    {
        $endpoint = $this->createEndpoint('shipping', 'invoices');
        $service = $this->createService($endpoint, 'shipping', 'inbox');
        $operation = $this->createOperation($endpoint, $service, 'shipping', 'create');

        $crossNamespaceCall = $this->createServiceCall($endpoint, $service, $operation, [
            'namespace' => 'shipping',
            'caller_namespace' => 'billing',
            'target_namespace' => 'shipping',
            'status' => ServiceCallStatus::Accepted->value,
        ]);

        $this->assertSame($crossNamespaceCall->id, ServiceCatalog::findServiceCall($crossNamespaceCall->id, 'shipping')->id);
        $this->assertNull(ServiceCatalog::findServiceCall($crossNamespaceCall->id, 'billing'));
        $this->assertNull(ServiceCatalog::findServiceCall($crossNamespaceCall->id, 'finance'));
        $this->assertSame($crossNamespaceCall->id, ServiceCatalog::findServiceCall($crossNamespaceCall->id, null)->id);
    }

    public function testServiceCallsQueryHonorsScope(): void
    {
        $endpoint = $this->createEndpoint('shipping', 'invoices');
        $service = $this->createService($endpoint, 'shipping', 'inbox');
        $operation = $this->createOperation($endpoint, $service, 'shipping', 'create');

        $callerCall = $this->createServiceCall($endpoint, $service, $operation, [
            'namespace' => 'billing',
            'caller_namespace' => 'billing',
            'target_namespace' => 'shipping',
            'status' => ServiceCallStatus::Accepted->value,
        ]);

        $targetCall = $this->createServiceCall($endpoint, $service, $operation, [
            'namespace' => 'billing',
            'caller_namespace' => 'shipping',
            'target_namespace' => 'billing',
            'status' => ServiceCallStatus::Started->value,
        ]);

        $crossNamespaceCallerCall = $this->createServiceCall($endpoint, $service, $operation, [
            'namespace' => 'shipping',
            'caller_namespace' => 'billing',
            'target_namespace' => 'shipping',
            'status' => ServiceCallStatus::Accepted->value,
        ]);

        $ownedCall = $this->createServiceCall($endpoint, $service, $operation, [
            'namespace' => 'billing',
            'caller_namespace' => 'billing',
            'target_namespace' => 'billing',
            'status' => ServiceCallStatus::Completed->value,
        ]);

        $owned = ServiceCatalog::serviceCallsQuery('billing', ServiceCatalog::SCOPE_OWNED)->get();
        $caller = ServiceCatalog::serviceCallsQuery('billing', ServiceCatalog::SCOPE_CALLER)->get();
        $target = ServiceCatalog::serviceCallsQuery('billing', ServiceCatalog::SCOPE_TARGET)->get();
        $relevant = ServiceCatalog::serviceCallsQuery('billing', ServiceCatalog::SCOPE_RELEVANT)->get();

        $this->assertEqualsCanonicalizing(
            [$ownedCall->id, $callerCall->id, $targetCall->id],
            $owned->pluck('id')->all(),
        );
        $this->assertEqualsCanonicalizing(
            [$ownedCall->id, $callerCall->id],
            $caller->pluck('id')->all(),
        );
        $this->assertEqualsCanonicalizing(
            [$ownedCall->id, $targetCall->id],
            $target->pluck('id')->all(),
        );
        $this->assertEqualsCanonicalizing(
            [$ownedCall->id, $callerCall->id, $targetCall->id],
            $relevant->pluck('id')->all(),
        );
        $this->assertNotContains($crossNamespaceCallerCall->id, $owned->pluck('id')->all());
        $this->assertNotContains($crossNamespaceCallerCall->id, $caller->pluck('id')->all());
        $this->assertNotContains($crossNamespaceCallerCall->id, $target->pluck('id')->all());
        $this->assertNotContains($crossNamespaceCallerCall->id, $relevant->pluck('id')->all());
    }

    public function testServiceCallsQueryFiltersByStatus(): void
    {
        $endpoint = $this->createEndpoint('billing', 'invoices');
        $service = $this->createService($endpoint, 'billing', 'inbox');
        $operation = $this->createOperation($endpoint, $service, 'billing', 'create');

        $accepted = $this->createServiceCall($endpoint, $service, $operation, [
            'namespace' => 'billing',
            'status' => ServiceCallStatus::Accepted->value,
        ]);
        $failed = $this->createServiceCall($endpoint, $service, $operation, [
            'namespace' => 'billing',
            'status' => ServiceCallStatus::Failed->value,
        ]);

        $failedCalls = ServiceCatalog::serviceCallsQuery('billing', ServiceCatalog::SCOPE_OWNED, ServiceCallStatus::Failed->value)->get();

        $this->assertSame([$failed->id], $failedCalls->pluck('id')->all());
        $this->assertNotContains($accepted->id, $failedCalls->pluck('id')->all());
    }

    public function testServiceCallsQueryFiltersByOutcome(): void
    {
        $endpoint = $this->createEndpoint('billing', 'invoices');
        $service = $this->createService($endpoint, 'billing', 'inbox');
        $operation = $this->createOperation($endpoint, $service, 'billing', 'create');

        $policy = $this->createServiceCall($endpoint, $service, $operation, [
            'namespace' => 'billing',
            'status' => ServiceCallStatus::Failed->value,
            'outcome' => ServiceCallOutcome::RejectedForbidden->value,
        ]);
        $handlerFailure = $this->createServiceCall($endpoint, $service, $operation, [
            'namespace' => 'billing',
            'status' => ServiceCallStatus::Failed->value,
            'outcome' => ServiceCallOutcome::HandlerFailed->value,
        ]);

        $policyCalls = ServiceCatalog::serviceCallsQuery(
            'billing',
            ServiceCatalog::SCOPE_OWNED,
            null,
            ServiceCallOutcome::RejectedForbidden->value,
        )->get();

        $this->assertSame([$policy->id], $policyCalls->pluck('id')->all());
        $this->assertNotContains($handlerFailure->id, $policyCalls->pluck('id')->all());
    }

    public function testServiceCallStatusAndOutcomeBucketsArePreservedInListItem(): void
    {
        $endpoint = $this->createEndpoint('billing', 'invoices');
        $service = $this->createService($endpoint, 'billing', 'inbox');
        $operation = $this->createOperation($endpoint, $service, 'billing', 'create');

        $rejected = $this->createServiceCall($endpoint, $service, $operation, [
            'namespace' => 'billing',
            'status' => ServiceCallStatus::Failed->value,
            'outcome' => ServiceCallOutcome::RejectedForbidden->value,
        ]);

        $shape = ServiceCallView::listItem($rejected);

        $this->assertSame('failed', $shape['status']);
        $this->assertSame('failed', $shape['status_bucket']);
        $this->assertSame('rejected_forbidden', $shape['outcome']);
        $this->assertSame('policy', $shape['outcome_bucket']);
        $this->assertTrue($shape['is_terminal']);
        $this->assertTrue($shape['is_policy_outcome']);
    }

    public function testServiceCallDetailFlagsForeignNamespaceLinks(): void
    {
        $endpoint = $this->createEndpoint('billing', 'invoices');
        $service = $this->createService($endpoint, 'billing', 'inbox');
        $operation = $this->createOperation($endpoint, $service, 'billing', 'create');

        $call = $this->createServiceCall($endpoint, $service, $operation, [
            'namespace' => 'billing',
            'caller_namespace' => 'billing',
            'caller_workflow_instance_id' => 'inst-billing-1',
            'caller_workflow_run_id' => 'run-billing-1',
            'target_namespace' => 'shipping',
            'linked_workflow_instance_id' => 'inst-shipping-1',
            'linked_workflow_run_id' => 'run-shipping-1',
            'linked_workflow_update_id' => 'upd-shipping-1',
            'status' => ServiceCallStatus::Started->value,
        ]);

        $detail = ServiceCallView::detail($call, 'billing');

        $this->assertSame('billing', $detail['caller_link']['namespace']);
        $this->assertTrue($detail['caller_link']['in_observer_namespace']);

        $this->assertSame('shipping', $detail['linked_run_ref']['namespace']);
        $this->assertFalse(
            $detail['linked_run_ref']['in_observer_namespace'],
            'Linked run in another namespace must be flagged so Waterline does not browse-through.'
        );

        $this->assertSame('shipping', $detail['linked_update_ref']['namespace']);
        $this->assertSame('inst-shipping-1', $detail['linked_update_ref']['workflow_instance_id']);
        $this->assertFalse($detail['linked_update_ref']['in_observer_namespace']);
    }

    public function testEndpointDetailIncludesNestedServicesAndOperations(): void
    {
        $endpoint = $this->createEndpoint('billing', 'invoices');
        $service = $this->createService($endpoint, 'billing', 'inbox');
        $this->createOperation($endpoint, $service, 'billing', 'create');

        $detail = ServiceEndpointView::detail($endpoint);

        $this->assertSame('invoices', $detail['endpoint_name']);
        $this->assertCount(1, $detail['services']);
        $this->assertCount(1, $detail['operations']);
        $this->assertSame('inbox', $detail['services'][0]['service_name']);
    }

    public function testEndpointDetailFiltersNestedCatalogRowsByObserverNamespace(): void
    {
        $endpoint = $this->createEndpoint('billing', 'invoices');
        $billingService = $this->createService($endpoint, 'billing', 'inbox');
        $shippingService = $this->createService($endpoint, 'shipping', 'inbox');
        $this->createOperation($endpoint, $billingService, 'billing', 'create');
        $this->createOperation($endpoint, $shippingService, 'shipping', 'create');

        $detail = ServiceEndpointView::detail($endpoint, 'billing');
        $unscoped = ServiceEndpointView::detail($endpoint, null);

        $this->assertSame([$billingService->id], collect($detail['services'])->pluck('id')->all());
        $this->assertSame(['billing'], collect($detail['operations'])->pluck('namespace')->all());
        $this->assertCount(2, $unscoped['services']);
        $this->assertCount(2, $unscoped['operations']);
    }

    public function testServiceDetailIncludesEndpointAndOperations(): void
    {
        $endpoint = $this->createEndpoint('billing', 'invoices');
        $service = $this->createService($endpoint, 'billing', 'inbox');
        $this->createOperation($endpoint, $service, 'billing', 'create');

        $detail = ServiceView::detail($service);

        $this->assertSame('inbox', $detail['service_name']);
        $this->assertSame('invoices', $detail['endpoint']['endpoint_name']);
        $this->assertCount(1, $detail['operations']);
    }

    public function testServiceDetailFiltersNestedOperationsAndForeignEndpointByObserverNamespace(): void
    {
        $endpoint = $this->createEndpoint('shipping', 'invoices');
        $service = $this->createService($endpoint, 'billing', 'inbox');
        $billingOperation = $this->createOperation($endpoint, $service, 'billing', 'create');
        $this->createOperation($endpoint, $service, 'shipping', 'create');

        $detail = ServiceView::detail($service, 'billing');

        $this->assertNull($detail['endpoint']);
        $this->assertSame([$billingOperation->id], collect($detail['operations'])->pluck('id')->all());
    }

    public function testOperationDetailIncludesEndpointAndService(): void
    {
        $endpoint = $this->createEndpoint('billing', 'invoices');
        $service = $this->createService($endpoint, 'billing', 'inbox');
        $operation = $this->createOperation($endpoint, $service, 'billing', 'create');

        $detail = ServiceOperationView::detail($operation);

        $this->assertSame('create', $detail['operation_name']);
        $this->assertSame('invoices', $detail['endpoint']['endpoint_name']);
        $this->assertSame('inbox', $detail['service']['service_name']);
    }

    public function testOperationDetailHidesForeignEndpointAndServiceForObserverNamespace(): void
    {
        $endpoint = $this->createEndpoint('shipping', 'invoices');
        $service = $this->createService($endpoint, 'shipping', 'inbox');
        $operation = $this->createOperation($endpoint, $service, 'billing', 'create');

        $detail = ServiceOperationView::detail($operation, 'billing');

        $this->assertNull($detail['endpoint']);
        $this->assertNull($detail['service']);
    }

    private function createEndpoint(string $namespace, string $name): WorkflowServiceEndpoint
    {
        return WorkflowServiceEndpoint::create([
            'namespace' => $namespace,
            'endpoint_name' => $name,
            'description' => null,
        ]);
    }

    private function createService(
        WorkflowServiceEndpoint $endpoint,
        string $namespace,
        string $name,
    ): WorkflowService {
        return WorkflowService::create([
            'workflow_service_endpoint_id' => $endpoint->id,
            'namespace' => $namespace,
            'service_name' => $name,
        ]);
    }

    private function createOperation(
        WorkflowServiceEndpoint $endpoint,
        WorkflowService $service,
        string $namespace,
        string $name,
    ): WorkflowServiceOperation {
        return WorkflowServiceOperation::create([
            'workflow_service_endpoint_id' => $endpoint->id,
            'workflow_service_id' => $service->id,
            'namespace' => $namespace,
            'operation_name' => $name,
            'operation_mode' => 'request_reply',
            'handler_binding_kind' => 'workflow_class',
            'handler_target_reference' => 'Tests\\HandlerWorkflow',
        ]);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function createServiceCall(
        WorkflowServiceEndpoint $endpoint,
        WorkflowService $service,
        WorkflowServiceOperation $operation,
        array $overrides,
    ): WorkflowServiceCall {
        $defaults = [
            'workflow_service_endpoint_id' => $endpoint->id,
            'workflow_service_id' => $service->id,
            'workflow_service_operation_id' => $operation->id,
            'endpoint_name' => $endpoint->endpoint_name,
            'service_name' => $service->service_name,
            'operation_name' => $operation->operation_name,
            'operation_mode' => $operation->operation_mode,
            'resolved_binding_kind' => ServiceCallBindingKind::WorkflowRun->value,
        ];

        return WorkflowServiceCall::create(array_merge($defaults, $overrides));
    }
}
