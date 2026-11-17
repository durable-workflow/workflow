<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ProviderStateMachines;

use DurableWorkflow\Examples\BusinessStateMachines\SqlBusinessStore;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Journal;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Request;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Status;
use Illuminate\Support\Carbon;
use LogicException;
use Workflow\V2\Compensation\Definition\PortableDescriptor;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\StateMachine\Execution\ExecutionReference;

/** Authoritative original money and the genuine Order's protected Payment publication. */
final readonly class OrderPeriodPaymentReceipt
{
    public function __construct(
        private Authority $authority,
        private Journal $provider,
    ) {
    }

    public function confirmedAt(BillingPeriod $period, WorkflowRun $root, bool $publication = true): ?int
    {
        $terms = $period->order ?? throw new LogicException('An original Order is required.');
        $key = $terms->subjectKey();
        $payment = $this->originalPayment($terms, $root);
        if ($payment === null) {
            return null;
        }
        [$actualActor, $subject, $plan] = $this->authority->actor($payment);
        if ($actualActor->role !== 'payment' || $payment->workflow_class !== FundingPayment::class
            || $plan->rootRun !== $root->id || $plan->recipe !== FundingCatalogue::IMMEDIATE) {
            throw new LogicException('The period payment belongs to another original native graph.');
        }
        if ($publication ? $subject->state !== 'paid'
            : ! in_array($subject->state, ['ready', 'collected', 'paid'], true)) {
            return null;
        }
        [, , , $rows] = (new FundingAuthority($this->authority))->rows($payment);
        if ($rows['effect']['forward_status'] !== 'observed') {
            throw new LogicException('The original Payment has no recorded capture outcome.');
        }
        $request = Request::fromArray($terms->descriptor('payment')['forward']);
        $receipt = $this->provider->timedLookup($request);
        if ($receipt->observation->status !== Status::Confirmed
            || $receipt->observation->effectId !== 'effect-' . $request->identity->key()
            || $receipt->confirmedAt === null || $receipt->confirmedAt < $period->startsAt
            || $receipt->confirmedAt > Carbon::now()->getTimestamp()) {
            throw new LogicException('The original Order lacks its authoritative period payment receipt.');
        }
        if ($publication) {
            $this->assertPublication($key, $payment, $subject);
        }
        return $receipt->confirmedAt;
    }

    private function originalPayment(OrderTerms $terms, WorkflowRun $root): ?WorkflowRun
    {
        $key = $terms->subjectKey();
        [$assignment, $original] = $this->authority->assignment($key);
        if (PortableDescriptor::encode($original->toArray()) !== PortableDescriptor::encode($terms->toArray())) {
            throw new LogicException('The period substituted its original Order terms.');
        }
        if ($assignment->root_run === null) {
            return null;
        }
        if ($assignment->root_run !== $root->id) {
            throw new LogicException('The period substituted its original Order owner.');
        }
        $actor = $this->authority->db->table('provider_machine_actors')
            ->where('id', Authority::subjectId($key, 'payment'))->first();
        return $actor === null ? null : WorkflowRun::query()->findOrFail($actor->native_run);
    }

    private function assertPublication(string $key, WorkflowRun $payment, object $subject): void
    {
        $revision = (int) $subject->revision;
        if ($revision < 2) {
            throw new LogicException('The original paid revision is unavailable.');
        }
        $execution = new ExecutionReference(
            Catalogue::APPLICATION,
            Authority::subjectName($key, 'payment'),
            $payment->id . '.confirm-receipt.' . ($revision - 1),
            $this->authority->profile($key, 'payment')
                ->fingerprint(),
            'collected',
            (string) ($revision - 1),
        );
        $id = SqlBusinessStore::decisionId($execution, 'transition', $execution->executionId);
        $row = $this->authority->db->table('example_machine_publications')
            ->where('id', $id)
            ->first();
        $expected = [
            'execution' => $execution->toArray(),
            'transitionKey' => 'confirm-receipt',
            'state' => 'paid',
            'revision' => (string) $revision,
        ];
        if ($row === null || $row->subject_id !== Authority::subjectId($key, 'payment')
            || strlen($row->payload) > PortableDescriptor::MAX_BYTES
            || PortableDescriptor::encode(json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR))
                !== PortableDescriptor::encode($expected)) {
            throw new LogicException('The original Order Payment publication is missing or substituted.');
        }
    }
}
