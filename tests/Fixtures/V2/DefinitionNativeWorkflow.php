<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Illuminate\Support\Facades\DB;
use LogicException;
use Workflow\V2\Activity;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Compensation\Definition\PortableDescriptor;
use Workflow\V2\StateMachine\Definition\ConditionDefinition;
use Workflow\V2\StateMachine\Definition\MachineDefinition;
use Workflow\V2\StateMachine\Definition\MachineState;
use Workflow\V2\StateMachine\Definition\PolicyProfile;
use Workflow\V2\StateMachine\Definition\RuleReference;
use Workflow\V2\StateMachine\Definition\TransitionDefinition;
use Workflow\V2\StateMachine\Execution\ActivityTransitionRuntime;
use Workflow\V2\StateMachine\Execution\ExecutionReceipt;
use Workflow\V2\StateMachine\Execution\ExecutionReference;
use Workflow\V2\StateMachine\Rules\ConditionRule;
use Workflow\V2\StateMachine\Rules\Decision;
use Workflow\V2\StateMachine\Rules\MachineAssessment;
use Workflow\V2\StateMachine\Rules\RecordedFacts;
use Workflow\V2\StateMachine\Rules\RuleEvaluation;
use Workflow\V2\StateMachine\Rules\RuleRegistry;
use Workflow\V2\StateMachine\Rules\Verdict;
use Workflow\V2\StateMachine\Transition\AssessedTransitionStep;
use Workflow\V2\StateMachine\Transition\TransitionContext;
use Workflow\V2\StateMachine\TransitionRunner;
use Workflow\V2\Workflow;

/** Native boundary fixture. Its counters deliberately expose any work/commit the runner should have refused. */
#[Type('fixture.machine-definition.workflow.v1')]
final class DefinitionNativeWorkflow extends Workflow
{
    public const NAMESPACE = 'definition-test';

    public const TABLE = 'defined_machine_fixture';

    public static function definition(): MachineDefinition
    {
        return new MachineDefinition('native-example', '1', [
            new MachineState('Received', 'Original business input accepted.'),
            new MachineState('Processing', 'Provider work confirmed.'),
        ], ['process'], ConditionDefinition::rule(new RuleReference('fixture.owner-current', '1')));
    }

    public static function profile(): PolicyProfile
    {
        return new PolicyProfile('native-example', '1', 'prepare', '1', self::definition()->stateIds(), [
            new TransitionDefinition('process', 'received', ['Received'], 'Processing', 'work', '1'),
        ], definition: self::definition());
    }

    public static function execution(string $run): ExecutionReference
    {
        return new ExecutionReference(
            'definition-fixture',
            'subject',
            $run . '.process',
            self::profile()->fingerprint(),
            'Received',
            '1'
        );
    }

    public function handle(): array
    {
        $profile = self::profile();
        return (new TransitionRunner())->run(
            $this,
            $profile,
            self::execution($this->runId()),
            'received',
            new ActivityTransitionRuntime(
                DefinitionSnapshotActivity::class,
                DefinitionAdmissionActivity::class,
                DefinitionCommitActivity::class,
                self::definition()
            ),
            [
                $profile->transitions[0]->handlerKey() => new DefinitionNativeStep(),
            ]
        )->toArray();
    }
}

trait DefinitionActivityOwner
{
    private function original(array $execution): object
    {
        if ($this->run->namespace !== DefinitionNativeWorkflow::NAMESPACE || $this->run->workflow_class !== DefinitionNativeWorkflow::class
            || PortableDescriptor::encode($execution) !== PortableDescriptor::encode(
                DefinitionNativeWorkflow::execution($this->runId())->toArray()
            )) {
            throw new LogicException('The native fixture boundary belongs to another original execution.');
        }
        return DB::table(DefinitionNativeWorkflow::TABLE)->where('run_id', $this->runId())->sole();
    }
}

final class DefinitionOwnerRule implements ConditionRule
{
    public function assertParameters(array $parameters): void
    {
        if ($parameters !== []) {
            throw new LogicException('The fixture owner rule accepts no configuration overrides.');
        }
    }

    public function evaluate(RuleReference $rule, RecordedFacts $facts): RuleEvaluation
    {
        $value = $facts->values['owner_current'] ?? null;
        $verdict = match ($value) {
            null => Verdict::Unknown,true => Verdict::Satisfied,default => Verdict::Unsatisfied
        };
        return new RuleEvaluation($rule, $facts->snapshotId, new Decision(
            $verdict,
            'owner_' . $verdict->value,
            $facts->has('owner_current') ? [$facts->evidence['owner_current']] : []
        ), $value === null ? ['owner_current'] : []);
    }
}

#[Type('fixture.machine-definition.snapshot.v1')]
final class DefinitionSnapshotActivity extends Activity
{
    use DefinitionActivityOwner;

    public function handle(array $request): array
    {
        $profile = DefinitionNativeWorkflow::profile();
        if (($request['machineDefinitionFingerprint'] ?? null) !== $profile->definition->fingerprint()
            || PortableDescriptor::encode($request['transition'] ?? []) !== PortableDescriptor::encode(
                get_object_vars($profile->transitions[0])
            )) {
            throw new LogicException('The snapshot does not select its original shared machine definition.');
        }
        $row = $this->original($request['execution']);
        $values = $row->owner_current === null ? [] : [
            'owner_current' => (bool) $row->owner_current,
        ];
        $id = hash(
            'sha256',
            $this->activityId() . PortableDescriptor::encode([$values, (bool) $row->target_ready, $row->state])
        );
        $facts = new RecordedFacts($id, $values, $values === [] ? [] : [
            'owner_current' => 'fixture-owner:' . $this->runId(),
        ]);
        $evaluation = $profile->definition->assess(new RuleRegistry([
            'fixture.owner-current' => [
                '1' => new DefinitionOwnerRule(),
            ],
        ]), $facts);
        $proof = MachineAssessment::fromEvaluation($profile->definition, $evaluation)->toArray();
        if ($row->proof_corruption === 'definition') {
            $proof['definitionFingerprint'] = str_repeat('f', 64);
        }
        $result = [
            'execution' => $request['execution'],
            'transitionKey' => 'process',
            'phase' => $request['phase'],
            'snapshotId' => $id,
            'state' => $row->state,
            'revision' => '1',
            'target' => [
                'verdict' => $row->target_ready ? 'satisfied' : 'unsatisfied',
                'reason' => 'target_observed',
                'evidence' => ['fixture-target'],
            ],
            'permission' => [
                'verdict' => 'satisfied',
                'reason' => 'permission_observed',
                'evidence' => ['fixture-permission'],
            ],
            'machineAssessment' => $proof,
        ];
        if ($row->proof_corruption === 'missing') {
            unset($result['machineAssessment']);
        }
        return $result;
    }
}

#[Type('fixture.machine-definition.admission.v1')]
final class DefinitionAdmissionActivity extends Activity
{
    use DefinitionActivityOwner;

    public function handle(array $request): array
    {
        $this->original($request['execution']);
        return [
            'execution' => $request['execution'],
            'decision' => [
                'verdict' => 'satisfied',
                'reason' => 'fixture_admitted',
                'evidence' => ['fixture-admission'],
            ],
        ];
    }
}

#[Type('fixture.machine-definition.effect.v1')]
final class DefinitionEffectActivity extends Activity
{
    use DefinitionActivityOwner;

    public function handle(array $execution): string
    {
        $row = $this->original($execution);
        DB::table(DefinitionNativeWorkflow::TABLE)->where('run_id', $this->runId())->update([
            'effect_count' => $row->effect_count + 1,
            'target_ready' => true,
            'owner_current' => $row->after_owner_current,
        ]);
        return 'fixture-effect:' . $this->runId();
    }
}

#[Type('fixture.machine-definition.commit.v1')]
final class DefinitionCommitActivity extends Activity
{
    use DefinitionActivityOwner;

    public function handle(array $request): array
    {
        $row = $this->original($request['execution']);
        // This probe does not repeat the invariant guard: an erroneous call must be visible to the test.
        DB::table(DefinitionNativeWorkflow::TABLE)->where('run_id', $this->runId())->update([
            'commit_count' => $row->commit_count + 1,
            'state' => 'Processing',
        ]);
        return [
            'execution' => $request['execution'],
            'status' => 'committed',
            'reason' => 'fixture_state_committed',
        ];
    }
}

final class DefinitionNativeStep extends AssessedTransitionStep
{
    public function execute(TransitionContext $context): ExecutionReceipt
    {
        $operation = Workflow::activity(DefinitionEffectActivity::class, $context->execution()->toArray());
        return new ExecutionReceipt($context->execution(), [$operation]);
    }

    public function compensate(TransitionContext $context, ExecutionReference $originalExecution): void
    {
        throw new LogicException('These forward-boundary probes do not implement a provider compensation handler.');
    }
}
