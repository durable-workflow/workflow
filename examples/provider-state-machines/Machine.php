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

    public function transition(string $key): TransitionResult
    {
        $execution = Authority::reference(Workflow::activity(BoundaryActivity::class, 'execution', [
            'key' => $key,
        ]));
        $recipe = $this->initialization['recipe'] ?? Catalogue::RESERVE_FIRST;
        $profile = Catalogue::profile($this->initialization['role'], $recipe);
        $step = new RetainedStep($this->scope, $this->identity, $this->initialization);
        $handlers = [];
        foreach ($profile->transitions as $transition) {
            $handlers[$transition->handlerKey()] = $step;
        }
        return (new TransitionRunner())->run(
            $this->workflow,
            $profile,
            $execution,
            Catalogue::transition($this->initialization['role'], $key, $recipe)->event,
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
}
