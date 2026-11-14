<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ConfiguredStateMachines;

use Workflow\V2\Compensation\Definition\Obligation;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Compensation\DurableScope;
use Workflow\V2\StateMachine\Definition\PolicyProfile;
use Workflow\V2\StateMachine\Execution\ActivityTransitionRuntime;
use Workflow\V2\StateMachine\Execution\TransitionResult;
use Workflow\V2\StateMachine\TransitionRunner;
use Workflow\V2\Workflow;

/** Workflow-only composition; all changing facts and protected writes cross recorded activities. */
final readonly class MachineExecution
{
    public ScopeIdentity $identity;

    public DurableScope $scope;

    private PolicyProfile $profile;

    public function __construct(
        private Workflow $workflow,
        private array $initialization,
        private string $principal,
        string $entry = ''
    ) {
        $this->identity = $initialization['role'] === 'shipment'
            ? new ScopeIdentity(
                Ownership::APPLICATION,
                Ownership::NAMESPACE,
                $initialization['subject'] . '.shipment.' . explode('.', $entry)[2],
                $entry
            )
            : Ownership::scope($initialization['subject'], $initialization['rootRun']);
        $this->scope = new DurableScope($workflow, $this->identity, $principal);
        $this->profile = $initialization['role'] === 'shipment'
            ? Catalogue::shipment($initialization['integration'], $initialization['version'])
            : Catalogue::order($initialization['integration'], $initialization['version']);
    }

    public function registerOwner(string $entry = ''): array
    {
        return $this->scope->execute(new Obligation('owner', 'configured.owner', '1', [
            'subject' => $this->initialization['subject'],
            'rootRun' => $this->initialization['rootRun'],
            'nativeRun' => $this->initialization['nativeRun'],
            'role' => $this->initialization['role'],
            'entry' => $entry,
        ]));
    }

    public function transition(string $key): TransitionResult
    {
        $execution = MachineStore::reference(Workflow::activity(BoundaryActivity::class, 'execution', [
            'key' => $key,
        ]));
        $step = new RetainedStep($this->scope, $this->identity, $this->principal, $this->initialization);
        $handlers = [];
        foreach ($this->profile->transitions as $definition) {
            $handlers[$definition->handlerKey()] = $step;
        }
        return (new TransitionRunner())->run(
            $this->workflow,
            $this->profile,
            $execution,
            Catalogue::transition($this->profile, $key)->event,
            new ActivityTransitionRuntime(SnapshotActivity::class, AdmissionActivity::class, CommitActivity::class),
            $handlers
        );
    }
}
