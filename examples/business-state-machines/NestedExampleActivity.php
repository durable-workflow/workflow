<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\BusinessStateMachines;

use LogicException;
use Workflow\V2\Activity;
use Workflow\V2\Attributes\Type;

/** Recorded application SQL boundaries for the local example. */
#[Type('example.fulfilment.sql.v1')]
final class NestedExampleActivity extends Activity
{
    public function handle(string $operation, array $input): array
    {
        if ($this->run->namespace !== ExampleOwnershipPolicy::NAMESPACE) {
            throw new LogicException('The native activity namespace is outside this example.');
        }
        $fields = match ($operation) {
            'initialize' => ['subject', 'integration', 'version', 'role', 'parentRun'],
            'bind' => ['reservation'],
            'shipment-completed' => ['subject', 'integration', 'version'],
            'order-completed' => ['binding', 'outcome', 'integration', 'version'],
            default => throw new LogicException('Unknown example application boundary.'),
        };
        $keys = array_keys($input);
        sort($keys);
        sort($fields);
        if ($keys !== $fields) {
            throw new LogicException('Malformed example application boundary.');
        }
        foreach ($fields as $field) {
            $valid = in_array($field, ['reservation', 'binding', 'outcome'], true)
                ? is_array($input[$field]) : is_string($input[$field]);
            if (! $valid) {
                throw new LogicException('Malformed example application field.');
            }
        }
        $example = app(NestedCompensationExample::class);
        return match ($operation) {
            'initialize' => $example->initialize(
                $input['subject'],
                $input['integration'],
                $input['version'],
                $input['role'],
                $input['parentRun'],
                $this->runId(),
                $this->workflowId(),
            ),
            'bind' => $example->recordAdmission($input['reservation'], $this->runId()),
            'shipment-completed' => $example->completeShipment(
                $input['subject'],
                $input['integration'],
                $input['version'],
                $this->runId(),
            ),
            'order-completed' => $example->completeOrder(
                $input['binding'],
                $input['outcome'],
                $input['integration'],
                $input['version'],
                $this->runId(),
            ),
            default => throw new LogicException('Unknown example application boundary.'),
        };
    }
}
