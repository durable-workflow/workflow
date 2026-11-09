<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use RuntimeException;
use Throwable;
use Workflow\V2\StateMachine\Definition\PolicyProfile;
use Workflow\V2\StateMachine\Definition\TransitionDefinition;
use Workflow\V2\StateMachine\Execution\ActivityTransitionRuntime;
use Workflow\V2\StateMachine\Execution\ExecutionReceipt;
use Workflow\V2\StateMachine\Execution\ExecutionReference;
use Workflow\V2\StateMachine\Transition\AssessedTransitionStep;
use Workflow\V2\StateMachine\Transition\TransitionContext;
use Workflow\V2\StateMachine\TransitionRunner;
use Workflow\V2\Workflow;

/** Runnable through the real V2 fiber runner, with portable activity inputs and results. */
final class TestBusinessStateMachineWorkflow extends Workflow
{
    public static function profile(
        string $name = 'prepare',
        bool $ambiguous = false,
        bool $hasWork = true
    ): PolicyProfile {
        $handler = $name === 'imported' ? 'import' : 'prepare';
        $transitions = [
            new TransitionDefinition('process', 'received', ['Received'], 'Processing', $handler, '1', $hasWork),
        ];
        if ($ambiguous) {
            $transitions[] = new TransitionDefinition('process-other', 'received', [
                'Received',
            ], 'Processing', 'prepare', '1');
        }
        return new PolicyProfile('example', '1', $name, '1', ['Received', 'Processing'], $transitions);
    }

    public function handle(array $options = []): array
    {
        $profile = self::profile(
            $options['profile'] ?? 'prepare',
            $options['ambiguous'] ?? false,
            $options['hasWork'] ?? true
        );
        $execution = new ExecutionReference(
            'example-app',
            'subject-1',
            'operation-1',
            $options['fingerprint'] ?? $profile->fingerprint(),
            'Received',
            'revision-1'
        );
        $runtime = new ActivityTransitionRuntime('example.snapshot', 'example.admit', 'example.commit');
        // Earlier committed work stays in the same native Saga while this run remains open.
        if ($options['earlier'] ?? false) {
            $this->addCompensation(static fn () => Workflow::activity('example.undo-earlier'));
        }
        try {
            $result = (new TransitionRunner())->run(
                $this,
                $profile,
                $execution,
                $options['event'] ?? 'received',
                $runtime,
                ($options['missingHandler'] ?? false) ? [] : [
                    $profile->transitions[0]->handlerKey() => $profile->profile === 'imported'
                        ? new ExampleImportedStep()
                        : new ExampleBusinessStep(),
                ]
            );
            return [
                'status' => $result->status->value,
                'reason' => $result->reason,
                'transition' => $result->transitionKey,
                'evidence' => $result->assessment?->evidence ?? [],
            ];
        } catch (Throwable $error) {
            if (! ($options['compensateOnFailure'] ?? false)) {
                throw $error;
            }
            self::cancellationShield(fn () => $this->compensate());
            return [
                'status' => 'compensated',
            ];
        }
    }
}

final class ExampleBusinessStep extends AssessedTransitionStep
{
    public function execute(TransitionContext $context): ExecutionReceipt
    {
        $reference = $context->execution();
        if (Workflow::activity('example.first-effect', $reference->toArray()) === 'reject') {
            throw new RuntimeException('First operation rejected.');
        }
        if (Workflow::activity('example.second-effect', $reference->toArray()) === 'reject') {
            throw new RuntimeException('Second operation rejected.');
        }
        return new ExecutionReceipt($reference, ['first-effect', 'second-effect']);
    }

    public function compensate(TransitionContext $context, ExecutionReference $originalExecution): void
    {
        $result = Workflow::activity(
            'example.reconcile-and-compensate',
            $originalExecution->toArray(),
            $context->snapshot->snapshotId
        );
        if ($result !== 'verified') {
            throw new RuntimeException('Compensation remains unresolved.');
        }
    }
}

/** A second integration uses the same states with different forward and reverse operations. */
final class ExampleImportedStep extends AssessedTransitionStep
{
    public function execute(TransitionContext $context): ExecutionReceipt
    {
        $reference = $context->execution();
        if (Workflow::activity('example.import-effect', $reference->toArray()) === 'reject') {
            throw new RuntimeException('Import operation rejected.');
        }
        return new ExecutionReceipt($reference, ['import-effect']);
    }

    public function compensate(TransitionContext $context, ExecutionReference $originalExecution): void
    {
        $result = Workflow::activity(
            'example.reconcile-import',
            $originalExecution->toArray(),
            $context->snapshot->snapshotId
        );
        if ($result !== 'verified') {
            throw new RuntimeException('Import compensation remains unresolved.');
        }
    }
}
