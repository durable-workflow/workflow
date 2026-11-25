<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Illuminate\Support\Facades\DB;
use LogicException;
use Workflow\V2\Activity;
use Workflow\V2\Compensation\Definition\Obligation;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Compensation\DurableScope;
use Workflow\V2\Compensation\Execution\ForwardResult;
use Workflow\V2\Compensation\Execution\OperationContext;
use Workflow\V2\Compensation\Execution\Resolution;
use Workflow\V2\Compensation\Execution\ScopedOperation;
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

    /**
     * Kept outside the returned decision, whose shape the replay regression corpus pins.
     */
    public ?array $explanation = null;

    public function handle(
        int $commands,
        ?int $events = null,
        ?int $bytes = null,
        bool $compensate = false,
        bool $retained = false
    ): array {
        $transition = new TransitionDefinition('prepare', 'begin', ['received'], 'prepared', 'prepare', '1');
        $profile = (new PolicyProfile(
            'budget-example',
            '1',
            'bounded',
            '1',
            ['received', 'prepared'],
            [$transition]
        ))->withLimits(new ExecutionLimits($commands, $events, $bytes));
        $execution = new ExecutionReference(
            'budget-example',
            'original-subject',
            'original-operation',
            $profile->fingerprint(),
            'received',
            '1'
        );
        $scope = $retained ? new DurableScope($this, self::scope(), 'budget-worker') : null;
        $result = (new TransitionRunner())->run($this, $profile, $execution, 'begin', new ActivityTransitionRuntime(
            BudgetSnapshotActivity::class,
            BudgetAdmissionActivity::class,
            BudgetCommitActivity::class
        ), [
            $transition->handlerKey() => new BudgetTransitionStep($scope),
        ]);
        if ($compensate && $result->status === TransitionStatus::Blocked) {
            $this->compensate();
        }
        $this->explanation = $result->explain();
        return $this->decision = $result->toArray();
    }

    public static function scope(): ScopeIdentity
    {
        return new ScopeIdentity('budget-example', null, 'original-subject', 'original-generation');
    }
}

final class BudgetTransitionStep extends AssessedTransitionStep
{
    public function __construct(
        private readonly ?DurableScope $scope = null
    ) {
    }

    public function execute(TransitionContext $context): ExecutionReceipt
    {
        foreach (['first', 'second'] as $action) {
            if ($this->scope !== null) {
                $this->scope->execute(new Obligation($action, 'budget.sql', '1', [
                    'execution' => $context->execution()
                        ->toArray(),
                    'action' => $action,
                ]));
            } else {
                Workflow::activity(BudgetEffectActivity::class, $context->execution()->toArray(), $action);
            }
        }
        return new ExecutionReceipt($context->execution(), ['first', 'second']);
    }

    public function compensate(TransitionContext $context, ExecutionReference $originalExecution): void
    {
        Workflow::activity(BudgetUndoActivity::class, $originalExecution->toArray());
    }
}

/** Permanent SQL receipts reject late forward execution after compensation. */
final class BudgetRetainedOperation implements ScopedOperation
{
    public function assertInput(array $input): void
    {
        if (! is_array($input['execution'] ?? null) || ! in_array(
            $input['action'] ?? null,
            ['first', 'second'],
            true
        )) {
            throw new LogicException('The synthetic retained operation input is invalid.');
        }
    }

    public function execute(OperationContext $context): ForwardResult
    {
        $input = $context->obligation->input;
        DB::transaction(static function () use ($input): void {
            $row = DB::table('budget_effect_journal')->where('action', $input['action'])->lockForUpdate()->first();
            if ($row === null) {
                (new BudgetEffectActivity())->handle($input['execution'], $input['action']);
            } elseif (json_decode($row->execution, true, flags: JSON_THROW_ON_ERROR) !== $input['execution']
                || $row->status !== 'active') {
                throw new LogicException('The original operation is foreign or permanently compensated.');
            }
        });
        return ForwardResult::observed([
            'operation' => $context->operationId,
        ], ['sql:' . $context->operationId]);
    }

    public function compensate(OperationContext $context): Resolution
    {
        $input = $context->obligation->input;
        DB::transaction(static function () use ($input): void {
            $row = DB::table('budget_effect_journal')->where('action', $input['action'])->lockForUpdate()->first();
            if ($row !== null && json_decode(
                $row->execution,
                true,
                flags: JSON_THROW_ON_ERROR
            ) !== $input['execution']) {
                throw new LogicException('Compensation cannot target another original operation.');
            }
            DB::table('budget_effect_journal')->updateOrInsert([
                'action' => $input['action'],
            ], [
                'execution' => json_encode($input['execution'], JSON_THROW_ON_ERROR),
                'status' => 'compensated',
            ]);
        });
        return Resolution::verified(['sql-compensated:' . $context->operationId]);
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
