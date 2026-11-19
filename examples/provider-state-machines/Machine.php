<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ProviderStateMachines;

use DurableWorkflow\Examples\BusinessStateMachines\BusinessCancellationRequested;
use DurableWorkflow\Examples\BusinessStateMachines\SqlBusinessStore;
use LogicException;
use Workflow\V2\Compensation\Definition\Obligation;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Compensation\DurableScope;
use Workflow\V2\StateMachine\Execution\ActivityTransitionRuntime;
use Workflow\V2\StateMachine\Execution\TransitionResult;
use Workflow\V2\StateMachine\Execution\TransitionStatus;
use Workflow\V2\StateMachine\Transition\FactSnapshot;
use Workflow\V2\StateMachine\Transition\SnapshotPhase;
use Workflow\V2\StateMachine\Transition\TransitionStep;
use Workflow\V2\StateMachine\TransitionRunner;
use Workflow\V2\Workflow;

/** Workflow composition only. Every mutable read, provider call and protected write is recorded. */
final readonly class Machine
{
    public DurableScope $scope;

    public ScopeIdentity $identity;

    public function __construct(
        private Workflow $workflow,
        public array $initialization
    ) {
        if (($initialization['nativeRun'] ?? null) !== $workflow->runId()
            || ($initialization['role'] ?? null) !== Authority::role($workflow::class)) {
            throw new LogicException('The recorded initialization belongs to another native machine.');
        }
        $this->identity = ScopeIdentity::fromArray($initialization['scope']);
        $this->scope = new DurableScope($workflow, $this->identity, $initialization['principal']);
    }

    public function registerOwner(): void
    {
        $owner = $this->scope->execute(new Obligation('owner', 'provider.owner', '1', array_intersect_key(
            $this->initialization,
            array_flip(['key', 'rootRun', 'nativeRun', 'role'])
        )));
        if (($owner['fenced'] ?? null) !== false) {
            throw new BusinessCancellationRequested('The original business generation is already cancelled.');
        }
    }

    public function transition(string $key, ?TransitionStep $selectedStep = null): TransitionResult
    {
        $execution = Authority::reference(Workflow::activity(BoundaryActivity::class, 'execution', [
            'key' => $key,
        ]));
        $recipe = $this->initialization['recipe'] ?? Catalogue::RESERVE_FIRST;
        $remedy = isset($this->initialization['transitPolicy']);
        $profile = Catalogue::profile($this->initialization['role'], $recipe, $remedy);
        $step = $selectedStep ?? new RetainedStep($this->scope, $this->identity, $this->initialization);
        $handlers = [];
        foreach ($profile->transitions as $transition) {
            $handlers[$transition->handlerKey()] = $step;
        }
        return (new TransitionRunner())->run(
            $this->workflow,
            $profile,
            $execution,
            Catalogue::transition($this->initialization['role'], $key, $recipe, $remedy)->event,
            new ActivityTransitionRuntime(
                SnapshotActivity::class,
                AdmissionActivity::class,
                CommitActivity::class,
                $profile->definition
            ),
            $handlers,
        );
    }

    public function outcome(TransitionResult $result): array
    {
        $role = $this->initialization['role'];
        $confirmed = $result->status === TransitionStatus::Committed;
        $outcome = $role === 'payment' ? 'captured' : 'booked';
        if ($role === 'payment' && FundingCatalogue::selected($this->initialization['recipe'] ?? '')) {
            $outcome = $result->execution->sourceState === 'collected' ? 'paid' : 'unsettled';
        }
        if ($role === 'shipment' && (FinancialCatalogue::selected($this->initialization['recipe'] ?? '')
            || FundingCatalogue::selected($this->initialization['recipe'] ?? ''))
            && $result->execution->sourceState === 'in-transit') {
            $outcome = 'delivered';
        }
        return [
            'originalRunId' => $this->workflow->runId(),
            'businessOutcome' => $confirmed ? $outcome : 'unsettled',
            'publicationId' => $confirmed ? SqlBusinessStore::decisionId(
                $result->execution,
                'transition',
                $result->execution->executionId
            ) : null,
            'transition' => $result->toArray(),
        ];
    }

    public function observe(string $key): FactSnapshot
    {
        $recipe = $this->initialization['recipe'] ?? Catalogue::RESERVE_FIRST;
        $remedy = isset($this->initialization['transitPolicy']);
        $profile = Catalogue::profile($this->initialization['role'], $recipe, $remedy);
        $execution = Authority::reference(Workflow::activity(BoundaryActivity::class, 'execution', [
            'key' => $key,
        ]));
        return (new ActivityTransitionRuntime(
            SnapshotActivity::class,
            AdmissionActivity::class,
            CommitActivity::class,
            $profile->definition
        ))->snapshot(
            $execution,
            Catalogue::transition($this->initialization['role'], $key, $recipe, $remedy),
            SnapshotPhase::Before
        );
    }
}
