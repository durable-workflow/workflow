<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ProviderStateMachines;

use DurableWorkflow\Examples\BusinessStateMachines\SqlBusinessStore;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Status;
use Illuminate\Support\Carbon;
use LogicException;
use Workflow\V2\Compensation\Definition\PortableDescriptor;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\StateMachine\Execution\ExecutionReference;
use Workflow\V2\StateMachine\Rules\Decision;
use Workflow\V2\StateMachine\Rules\MachineAssessment;
use Workflow\V2\StateMachine\Rules\RecordedFacts;
use Workflow\V2\StateMachine\Rules\Verdict;
use Workflow\V2\StateMachine\Transition\FactSnapshot;
use Workflow\V2\StateMachine\Transition\SnapshotPhase;
use Workflow\V2\StateMachine\Transition\TransitionContext;

/** Recorded provider facts and protected period Payment commits through the common StateMachine runtime. */
final readonly class BillingPeriodAssessment
{
    public function __construct(
        private BillingPeriodStore $store
    ) {
    }

    public function snapshot(WorkflowRun $actual, string $activityId, array $request): array
    {
        [$period, $subject, $execution, $edge] = $this->request($actual, $request, false);
        $id = hash('sha256', PortableDescriptor::encode([$actual->id, $activityId, $request]));
        $requestHash = hash('sha256', PortableDescriptor::encode($request));
        $old = $this->store->db->table('provider_billing_assessments')
            ->where('id', $id)
            ->first();
        if ($old !== null) {
            if ($old->period_id !== $period->subjectKey() || $old->request_sha256 !== $requestHash) {
                throw new LogicException('The original period snapshot identity changed.');
            }
            return $this->read($old)['snapshot'];
        }
        $observed = $this->store->observation($actual);
        $profile = $this->store->profile($period);
        $confirmed = $observed['receipt']['status'] === Status::Confirmed->value;
        $values = [
            'capture_confirmed' => $confirmed,
            'merchant_receipt_confirmed' => $confirmed,
            'forward_open' => ! $observed['cancelled'],
            'model_consistent' => (int) $subject->revision > 0 && in_array(
                $subject->state,
                $profile->definition->stateIds(),
                true
            ),
        ];
        $facts = new RecordedFacts($id, $values, array_combine(array_keys($values), array_map(
            static fn (string $key): string => 'billing-fact:' . $id . ':' . $key,
            array_keys($values)
        )));
        $rules = Catalogue::registry();
        $snapshot = [
            'execution' => $execution->toArray(),
            'transitionKey' => $edge->key,
            'phase' => $request['phase'],
            'snapshotId' => $id,
            'state' => $subject->state,
            'revision' => (string) $subject->revision,
            'target' => $rules->evaluateCondition(
                $profile->condition($edge->key . '.target'),
                $facts
            )->decision->toArray(),
            'permission' => $rules->evaluateCondition(
                $profile->condition($edge->key . '.permission'),
                $facts
            )->decision->toArray(),
            'machineAssessment' => MachineAssessment::fromEvaluation(
                $profile->definition,
                $profile->definition->assess($rules, $facts)
            )->toArray(),
        ];
        $payload = PortableDescriptor::encode([
            'snapshot' => $snapshot,
            'facts' => $facts->toArray(),
            'receipt' => $observed['receipt'],
        ]);
        $this->store->db->table('provider_billing_assessments')
            ->insert([
                'id' => $id,
                'period_id' => $period->subjectKey(),
                'request_sha256' => $requestHash,
                'payload' => $payload,
                'payload_sha256' => hash('sha256', $payload),
            ]);
        return $snapshot;
    }

    public function admit(WorkflowRun $actual, array $request): array
    {
        [$period, $context] = $this->context($actual, $request);
        $execution = $context->execution();
        $id = hash('sha256', PortableDescriptor::encode($execution->toArray()));
        $hash = hash('sha256', PortableDescriptor::encode($request));
        return $this->store->db->transaction(function () use ($period, $context, $execution, $id, $hash): array {
            $current = $this->store->subject($period->subjectKey(), $period, true);
            $old = $this->store->db->table('provider_billing_admissions')
                ->where('id', $id)
                ->first();
            if ($old !== null && ($old->period_id !== $period->subjectKey() || $old->request_sha256 !== $hash)) {
                throw new LogicException('The original period transition admission changed content.');
            }
            $allowed = ! (bool) $current->cancellation_requested && $current->state === $execution->sourceState
                && (string) $current->revision === $execution->sourceRevision && $context->snapshot->permission->satisfied();
            // A late acknowledgement of an original confirmed effect may commit its fact.
            // An unexecuted charge cannot first be dispatched after the original deadline.
            $allowed = $allowed && ($context->transition->key !== 'prepare' || $context->snapshot->target->satisfied()
                || Carbon::now()->getTimestamp() < $period->paymentDeadline);
            if ($allowed && $old !== null) {
                return json_decode($old->decision, true, flags: JSON_THROW_ON_ERROR);
            }
            $decision = [
                'execution' => $execution->toArray(),
                'decision' => (new Decision(
                    $allowed ? Verdict::Satisfied : Verdict::Unsatisfied,
                    $allowed ? 'original-period-admitted' : 'original-period-admission-refused',
                    ['billing-admission:' . $id]
                ))->toArray(),
            ];
            if ($old === null) {
                $this->store->db->table('provider_billing_admissions')
                    ->insert([
                        'id' => $id,
                        'period_id' => $period->subjectKey(),
                        'request_sha256' => $hash,
                        'decision' => PortableDescriptor::encode($decision),
                    ]);
            }
            return $decision;
        });
    }

    public function commit(WorkflowRun $actual, array $request): array
    {
        [$period, $context] = $this->context($actual, $request);
        $host = new SqlBusinessStore($this->store->db, function (
            ExecutionReference $execution,
            string $principal,
            string $operation,
            ?TransitionContext $proposed
        ) use ($actual, $period, $context): string {
            $this->store->actor($actual);
            if ($principal !== $this->store->principal || $operation !== 'commit' || $proposed !== $context
                || ! $execution->equals($context->execution()) || ! $context->snapshot->target->satisfied()) {
                throw new LogicException('A period commit requires its original satisfied recorded assessment.');
            }
            return $this->store->version($period);
        });
        $result = $host->commit($context, $this->store->principal);
        return [
            'execution' => $result->execution->toArray(),
            'status' => $result->status->value,
            'reason' => $result->reason,
        ];
    }

    private function request(WorkflowRun $actual, array $request, bool $protected): array
    {
        [$period, $subject] = $this->store->actor($actual);
        $execution = Authority::reference($request['execution'] ?? []);
        $keys = array_keys($request);
        sort($keys);
        $expected = $protected
            ? [
                'execution',
                'transition',
                'snapshotId',
                'phase',
                'expectedState',
                'expectedRevision',
                'machineAssessment',
            ]
            : ['execution', 'transition', 'phase', 'machineDefinitionFingerprint'];
        sort($expected);
        if ($keys !== $expected || ! is_array($request['transition']) || ! is_string($request['phase'])) {
            throw new LogicException('The recorded period boundary input is malformed.');
        }
        SnapshotPhase::from($request['phase']);
        $profile = $this->store->profile($period);
        $matches = array_values(
            array_filter(
                $profile->transitions,
                static fn ($edge): bool => $edge->key === ($request['transition']['key'] ?? null)
            )
        );
        if (count($matches) !== 1) {
            throw new LogicException('The original period transition is unavailable.');
        }
        $edge = $matches[0];
        if ($execution->scope !== BillingPeriodStore::APPLICATION || $execution->subject !== $period->subjectKey()
            || $execution->policyFingerprint !== $profile->fingerprint()
            || $execution->executionId !== $actual->id . '.' . $edge->key . '.' . $execution->sourceRevision
            || ! in_array($execution->sourceState, $edge->from, true)
            || PortableDescriptor::encode($request['transition']) !== PortableDescriptor::encode(get_object_vars($edge))
            || (! $protected && $request['machineDefinitionFingerprint'] !== $profile->definition->fingerprint())) {
            throw new LogicException('The original period execution, transition or definition was substituted.');
        }
        return [$period, $subject, $execution, $edge];
    }

    private function context(WorkflowRun $actual, array $request): array
    {
        [$period, , $execution, $edge] = $this->request($actual, $request, true);
        $row = $this->store->db->table('provider_billing_assessments')
            ->where('id', $request['snapshotId'])->sole();
        if ($row->period_id !== $period->subjectKey()) {
            throw new LogicException('The protected period request selected another snapshot owner.');
        }
        $snapshot = $this->read($row)['snapshot'];
        if (PortableDescriptor::encode($snapshot['execution']) !== PortableDescriptor::encode($execution->toArray())
            || $snapshot['transitionKey'] !== $edge->key || $snapshot['phase'] !== $request['phase']
            || $snapshot['state'] !== $request['expectedState'] || $snapshot['revision'] !== $request['expectedRevision']
            || PortableDescriptor::encode($snapshot['machineAssessment']) !== PortableDescriptor::encode(
                $request['machineAssessment']
            )) {
            throw new LogicException('The period commit differs from its immutable original assessment.');
        }
        $machine = MachineAssessment::fromArray(
            $snapshot['machineAssessment'],
            $this->store->profile($period)
->definition
        );
        if (! $machine->decision->satisfied()) {
            throw new LogicException('The period commit requires the common Payment invariant.');
        }
        $decision = static fn (array $value): Decision => new Decision(Verdict::from(
            $value['verdict']
        ), $value['reason'], $value['evidence']);
        return [$period, new TransitionContext($edge, new FactSnapshot(
            $execution,
            $edge->key,
            SnapshotPhase::from($snapshot['phase']),
            $snapshot['snapshotId'],
            $snapshot['state'],
            $snapshot['revision'],
            $decision($snapshot['target']),
            $decision($snapshot['permission']),
            $machine
        ))];
    }

    private function read(object $row): array
    {
        if (strlen($row->payload) > PortableDescriptor::MAX_BYTES
            || ! hash_equals($row->payload_sha256, hash('sha256', $row->payload))) {
            throw new LogicException('The immutable original period assessment was corrupted.');
        }
        return json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR);
    }
}
