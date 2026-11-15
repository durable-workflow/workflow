<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Illuminate\Support\Facades\DB;
use LogicException;
use Workflow\V2\Activity;
use Workflow\V2\StateMachine\Definition\ExecutionLimits;
use Workflow\V2\StateMachine\Definition\PolicyProfile;
use Workflow\V2\StateMachine\Definition\TransitionDefinition;
use Workflow\V2\StateMachine\Execution\ActivityTransitionRuntime;
use Workflow\V2\StateMachine\Execution\ExecutionReceipt;
use Workflow\V2\StateMachine\Execution\ExecutionReference;
use Workflow\V2\StateMachine\Execution\TransitionStatus;
use Workflow\V2\StateMachine\Transition\AssessedTransitionStep;
use Workflow\V2\StateMachine\Transition\TransitionContext;
use Workflow\V2\StateMachine\TransitionRunner;
use Workflow\V2\Workflow;

/** Actual native activities; the independent SQL journal is only a synthetic service. */
final class TestBudgetedTransitionWorkflow extends Workflow
{
    public array $decision = [];

    public function handle(int $commands, ?int $events = null, ?int $bytes = null, bool $compensate = false): array
    {
        $transition = new TransitionDefinition('prepare', 'begin', ['received'], 'prepared', 'prepare', '1');
        $profile = new PolicyProfile(
            'budget-example',
            '1',
            'bounded',
            '1',
            ['received', 'prepared'],
            [$transition],
            limits: new ExecutionLimits($commands, $events, $bytes)
        );
        $execution = new ExecutionReference(
            'budget-example',
            'original-subject',
            'original-operation',
            $profile->fingerprint(),
            'received',
            '1'
        );
        $result = (new TransitionRunner())->run($this, $profile, $execution, 'begin', new ActivityTransitionRuntime(
            BudgetSnapshotActivity::class,
            BudgetAdmissionActivity::class,
            BudgetCommitActivity::class
        ), [
            $transition->handlerKey() => new BudgetTransitionStep(),
        ]);
        if ($compensate && $result->status === TransitionStatus::Blocked) {
            $this->compensate();
        }
        return $this->decision = $result->toArray();
    }
}

final class BudgetTransitionStep extends AssessedTransitionStep
{
    public function execute(TransitionContext $context): ExecutionReceipt
    {
        foreach (['first', 'second'] as $action) {
            Workflow::activity(BudgetEffectActivity::class, $context->execution()->toArray(), $action);
        }
        return new ExecutionReceipt($context->execution(), ['first', 'second']);
    }

    public function compensate(TransitionContext $context, ExecutionReference $originalExecution): void
    {
        Workflow::activity(BudgetUndoActivity::class, $originalExecution->toArray());
    }
}

final class BudgetSnapshotActivity extends Activity
{
    public function handle(array $request): array
    {
        $satisfied = $request['phase'] === 'after';
        return [
            'execution' => $request['execution'],
            'transitionKey' => $request['transition']['key'],
            'phase' => $request['phase'],
            'snapshotId' => 'budget-snapshot-' . $request['phase'],
            'state' => 'received',
            'revision' => '1',
            'target' => [
                'verdict' => $satisfied ? 'satisfied' : 'unsatisfied',
                'reason' => 'fixture_target',
                'evidence' => [],
            ],
            'permission' => [
                'verdict' => 'satisfied',
                'reason' => 'fixture_permission',
                'evidence' => [],
            ],
        ];
    }
}

final class BudgetAdmissionActivity extends Activity
{
    public function handle(array $request): array
    {
        return [
            'execution' => $request['execution'],
            'decision' => [
                'verdict' => 'satisfied',
                'reason' => 'fixture_admission',
                'evidence' => [],
            ],
        ];
    }
}

final class BudgetCommitActivity extends Activity
{
    public function handle(array $request): array
    {
        if (DB::table('budget_effect_journal')->where('status', 'active')->count() !== 2) {
            throw new LogicException('The synthetic service has not confirmed both original effects.');
        }
        return [
            'execution' => $request['execution'],
            'status' => 'committed',
            'reason' => 'fixture_commit',
        ];
    }
}

final class BudgetEffectActivity extends Activity
{
    public function handle(array $execution, string $action): void
    {
        DB::table('budget_effect_journal')->insert([
            'action' => $action,
            'execution' => json_encode($execution, JSON_THROW_ON_ERROR),
            'status' => 'active',
        ]);
    }
}

final class BudgetUndoActivity extends Activity
{
    public function handle(array $execution): void
    {
        foreach (DB::table('budget_effect_journal')->get() as $effect) {
            if (json_decode($effect->execution, true, flags: JSON_THROW_ON_ERROR) !== $execution) {
                throw new LogicException('Compensation attempted another original effect.');
            }
        }
        DB::table('budget_effect_journal')->where('status', 'active')->update([
            'status' => 'compensated',
        ]);
    }
}
