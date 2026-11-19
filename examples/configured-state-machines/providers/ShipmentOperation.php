<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ConfiguredStateMachines\Providers;

use Closure;
use Workflow\V2\Compensation\Execution\ForwardResult;
use Workflow\V2\Compensation\Execution\OperationContext;
use Workflow\V2\Compensation\Execution\Resolution;
use Workflow\V2\Compensation\Execution\ScopedOperation;

/** First full-parcel policy: original booking cancellation, or accepted suitable return and confirmed restock. */
final readonly class ShipmentOperation implements ScopedOperation
{
    /**
     * @param Closure(OperationContext, string): void $authorize
     * The host must bind the original actor, scope, child admission and complete shipment graph before provider SQL.
     */
    public function __construct(
        private Journal $provider,
        private Closure $authorize
    ) {
    }

    public function assertInput(array $input): void
    {
        ShipmentDescriptor::fromArray($input);
    }

    public function execute(OperationContext $context): ForwardResult
    {
        $descriptor = ShipmentDescriptor::fromArray($context->obligation->input);
        ($this->authorize)($context, 'forward');
        $booking = $descriptor->booking;
        $value = $context->reconcileBeforeForward ? $this->provider->lookup($booking) : $this->provider->attempt(
            $booking
        );
        if ($value->status === Status::Unknown || (($value->status === Status::Confirmed) !== ($value->effectId !== null))) {
            return ForwardResult::unknown('shipment-booking-unknown');
        }
        return ForwardResult::observed(
            [
                'operation' => $booking->identity->key(),
                'status' => $value->status->value,
                'effectId' => $value->effectId,
                'admissionClosed' => $value->admissionClosed,
            ],
            [$value->effectId ?? 'shipment-status:' . $booking->identity->key() . ':' . $value->status->value]
        );
    }

    public function compensate(OperationContext $context): Resolution
    {
        $descriptor = ShipmentDescriptor::fromArray($context->obligation->input);
        ($this->authorize)($context, 'compensate');
        $deduction = $descriptor->deduction === null ? null : $this->provider->closeAdmission($descriptor->deduction);
        $booking = $this->provider->closeAdmission($descriptor->booking);
        if ($booking->admissionClosed && $booking->effectId === null
            && in_array($booking->status, [Status::NotExecuted, Status::Refused], true)) {
            return $deduction === null || $this->absent($deduction)
                ? Resolution::verified(['provider-fenced:' . $descriptor->booking->identity->key()])
                : Resolution::unknown('shipment-inventory-without-booking');
        }
        if ($booking->status !== Status::Confirmed || $booking->effectId === null) {
            return Resolution::unknown('shipment-booking-' . $booking->status->value);
        }
        return $this->settleConfirmed($descriptor, $booking, $deduction);
    }

    private function settleConfirmed(
        ShipmentDescriptor $descriptor,
        Observation $booking,
        ?Observation $deduction
    ): Resolution {
        $snapshot = $this->provider->shipments()
            ->inspect($descriptor);
        if (! isset($snapshot->events['handed-over'])) {
            if ($deduction !== null && ! $this->absent($deduction)) {
                return Resolution::unknown('shipment-deduction-without-handover-evidence');
            }
            // The provider serializes actual handover against this cancellation's admission.
            $cancel = $this->provider->attempt($descriptor->cancel);
            return $this->settled($cancel) ? Resolution::verified([$booking->effectId, $cancel->effectId])
                : Resolution::unknown('shipment-cancellation-' . $cancel->status->value);
        }
        return $this->settleReturn($descriptor, $booking, $deduction);
    }

    private function settleReturn(
        ShipmentDescriptor $descriptor,
        Observation $booking,
        ?Observation $deduction
    ): Resolution {
        $return = $this->provider->attempt($descriptor->return);
        if (! $this->settled($return)) {
            return Resolution::unknown('shipment-return-' . $return->status->value);
        }
        $snapshot = $this->provider->shipments()
            ->inspect($descriptor)
            ->toArray();
        $facts = $snapshot['facts'];
        if (! $facts['full_return_accepted'] || $facts['saleable_quantity'] !== $descriptor->booking->terms['quantity']) {
            // Partial, damaged, lost or unreceived goods need their own explicit settlement policy.
            return Resolution::unknown('shipment-physical-return-unsettled');
        }
        return $this->settleInventory($descriptor, $booking, $deduction, $return, $snapshot);
    }

    private function settleInventory(
        ShipmentDescriptor $descriptor,
        Observation $booking,
        ?Observation $deduction,
        Observation $return,
        array $snapshot
    ): Resolution {
        if ($deduction !== null && $this->absent($deduction)) {
            return Resolution::verified([$booking->effectId, $return->effectId,
                'provider-fenced:' . $descriptor->deduction->identity->key(),
                'original-receipt:' . $snapshot['events']['received']['id'],
                'original-inspection:' . $snapshot['events']['inspected']['id']]);
        }
        if ($deduction !== null && ! $this->settled($deduction)) {
            return Resolution::unknown('shipment-deduction-evidence-unsettled');
        }
        return $this->restock($descriptor, $booking, $deduction, $return, $snapshot);
    }

    private function restock(
        ShipmentDescriptor $descriptor,
        Observation $booking,
        ?Observation $deduction,
        Observation $return,
        array $snapshot
    ): Resolution {
        $restock = $this->provider->attempt($descriptor->restock);
        if (! $this->settled($restock)) {
            return Resolution::unknown('shipment-restock-' . $restock->status->value);
        }
        $events = $snapshot['events'];
        $evidence = [$booking->effectId, $return->effectId, $restock->effectId,
            'original-receipt:' . $events['received']['id'], 'original-inspection:' . $events['inspected']['id']];
        if ($deduction !== null) {
            $evidence[] = $deduction->effectId;
        }
        return Resolution::verified($evidence);
    }

    private function settled(Observation $value): bool
    {
        return $value->status === Status::Confirmed && $value->effectId !== null;
    }

    private function absent(Observation $value): bool
    {
        return $value->admissionClosed && $value->effectId === null
            && in_array($value->status, [Status::NotExecuted, Status::Refused], true);
    }
}
