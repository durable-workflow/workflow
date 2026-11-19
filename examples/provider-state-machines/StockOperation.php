<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ProviderStateMachines;

use Closure;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Journal;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Request;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\RetainedOperation;
use Workflow\V2\Compensation\Execution\ForwardResult;
use Workflow\V2\Compensation\Execution\OperationContext;
use Workflow\V2\Compensation\Execution\Resolution;
use Workflow\V2\Compensation\Execution\ScopedOperation;

/**
 * The original reservation command plus its physical warehouse allocation.
 * Units are held only after the original command confirms; release returns only suitable held units.
 */
final readonly class StockOperation implements ScopedOperation
{
    private RetainedOperation $command;

    /**
     * @param Closure(OperationContext, string): void $authorize
     */
    public function __construct(
        Journal $provider,
        private StockWarehouse $warehouse,
        private Closure $authorize,
    ) {
        $this->command = new RetainedOperation($provider, $authorize);
    }

    public function assertInput(array $input): void
    {
        $this->command->assertInput($input);
        $this->requests($input);
    }

    public function execute(OperationContext $context): ForwardResult
    {
        $result = $this->command->execute($context);
        if (! $result->observed || $result->value['status'] !== 'confirmed') {
            return $result;
        }
        [$original, $release] = $this->requests($context->obligation->input);
        // Idempotent per original reservation: a retry or uncertain replay never holds stock twice.
        $allocation = $this->warehouse->reserve($original, $release);
        return ForwardResult::observed([
            ...$result->value,
            'allocationFingerprint' => StockAllocation::fromArray($allocation['allocation'])->fingerprint(),
        ], $result->evidence);
    }

    public function compensate(OperationContext $context): Resolution
    {
        [$original, $release] = $this->requests($context->obligation->input);
        $resolution = $this->command->compensate($context);
        // Held units return to saleable stock only after the original provider release verifies.
        // Both commands are idempotent, so a lost acknowledgement repeats neither effect.
        if (! $resolution->verified || $this->warehouse->find($original) === null) {
            return $resolution;
        }
        $receipt = $this->warehouse->release($release);
        return Resolution::verified([
            ...$resolution->evidence,
            'warehouse-release:' . $original->identity->key() . ':' . $receipt['revision'],
        ]);
    }

    /**
     * @return array{Request, Request}
     */
    private function requests(array $input): array
    {
        $original = Request::fromArray($input['forward']);
        $release = Request::fromArray($input['compensation']);
        if ($original->action !== 'reserve') {
            throw new \InvalidArgumentException('Warehouse allocation only belongs to the original reservation.');
        }
        return [$original, $release];
    }
}
