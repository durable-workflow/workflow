<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ConfiguredStateMachines;

use LogicException;
use Workflow\V2\Activity;
use Workflow\V2\Attributes\Type;

#[Type('example.configured.boundary.v1')]
final class BoundaryActivity extends Activity
{
    public function handle(string $operation, array $input): mixed
    {
        $fields = match ($operation) {
            'initialize' => ['subject', 'rootRun', 'begin'],
            'initialize-finalizer' => ['subject', 'rootRun', 'requestKey'],
            'execution' => ['key'],
            'binding', 'record-start' => ['reservation'],
            'active-binding' => [],
            'outcome' => ['binding', 'outcome'],
            'verify-step' => ['execution', 'key'],
            default => throw new LogicException('The configured boundary operation is unavailable.'),
        };
        $keys = array_keys($input);
        sort($keys);
        sort($fields);
        if ($keys !== $fields) {
            throw new LogicException('The configured boundary input is malformed.');
        }
        $store = app(MachineStore::class);
        return match ($operation) {
            'initialize' => $store->initialize($this->run, $input['subject'], $input['rootRun'], $input['begin']),
            'initialize-finalizer' => $store->initializeFinalizer(
                $this->run,
                $input['subject'],
                $input['rootRun'],
                $input['requestKey']
            ),
            'execution' => $store->execution($this->run, $input['key']),
            'binding' => $store->binding($this->run, $input['reservation']),
            'record-start' => $store->recordStart($this->run, $input['reservation']),
            'active-binding' => $store->activeBinding($this->run),
            'outcome' => $store->observeOutcome($this->run, $input['binding'], $input['outcome']),
            'verify-step' => $store->verifyStep($this->run, $input['execution'], $input['key']),
        };
    }
}
