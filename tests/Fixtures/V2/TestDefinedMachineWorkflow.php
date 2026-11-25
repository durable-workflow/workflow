<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Throwable;
use Workflow\V2\StateMachine\Definition\ConditionDefinition;
use Workflow\V2\StateMachine\Definition\MachineDefinition;
use Workflow\V2\StateMachine\Definition\MachineState;
use Workflow\V2\StateMachine\Definition\PolicyProfile;
use Workflow\V2\StateMachine\Definition\RuleReference;
use Workflow\V2\StateMachine\Definition\TransitionDefinition;
use Workflow\V2\StateMachine\Execution\ActivityTransitionRuntime;
use Workflow\V2\StateMachine\Execution\ExecutionReference;
use Workflow\V2\StateMachine\Rules\ConditionEvaluation;
use Workflow\V2\StateMachine\Rules\Decision;
use Workflow\V2\StateMachine\Rules\MachineAssessment;
use Workflow\V2\StateMachine\Rules\RuleEvaluation;
use Workflow\V2\StateMachine\Rules\Verdict;
use Workflow\V2\StateMachine\TransitionRunner;
use Workflow\V2\Workflow;

final class TestDefinedMachineWorkflow extends Workflow
{
    public static function definition(): MachineDefinition
    {
        return new MachineDefinition(
            'example',
            '1',
            [new MachineState('Received', 'Business input has been accepted.'),
                new MachineState('Processing', 'The original provider operation is confirmed.')],
            ['process'],
            ConditionDefinition::rule(new RuleReference('example.owner-current', '1'))
        );
    }

    public static function profile(string $name = 'prepare', bool $overrideInvariant = false): PolicyProfile
    {
        return PolicyProfile::forDefinition(self::definition(), $name, '1', [
            new TransitionDefinition('process', 'received', [
                'Received',
            ], 'Processing', $name === 'imported' ? 'import' : 'prepare', '1'),
        ], conditions: $overrideInvariant ? [
            'machine.invariants' => ConditionDefinition::not(self::definition()->invariants),
        ] : []);
    }

    /**
     * Simulated authoritative recorded rule outcome for boundary/refusal tests.
     */
    public static function assessment(string $snapshot, string $verdict = 'satisfied'): MachineAssessment
    {
        $definition = self::definition();
        return MachineAssessment::fromEvaluation($definition, ConditionEvaluation::leaf(
            $definition->invariants,
            new RuleEvaluation(
                $definition->invariants->rule,
                $snapshot,
                new Decision(Verdict::from($verdict), 'owner_' . $verdict, ['owner-proof:' . $snapshot])
            )
        ));
    }

    public function handle(array $options = []): array
    {
        // Reuse the existing native step implementations rather than another executor or retry loop.
        class_exists(TestBusinessStateMachineWorkflow::class);
        $profile = self::profile($options['profile'] ?? 'prepare', $options['overrideInvariant'] ?? false);
        $execution = new ExecutionReference(
            'example-app',
            'subject-1',
            'operation-1',
            $profile->fingerprint(),
            'Received',
            'revision-1'
        );
        $runtime = new ActivityTransitionRuntime(
            'defined.snapshot',
            'defined.admit',
            'defined.commit',
            ($options['omitRuntimeDefinition'] ?? false) ? null : self::definition()
        );
        try {
            $result = (new TransitionRunner())->run($this, $profile, $execution, 'received', $runtime, [
                $profile->transitions[0]->handlerKey() => $profile->profile === 'imported' ? new ExampleImportedStep() : new ExampleBusinessStep(),
            ]);
            return [
                ...$result->toArray(),
                'explanation' => $result->explain(),
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
