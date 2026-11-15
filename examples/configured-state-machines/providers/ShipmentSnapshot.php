<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ConfiguredStateMachines\Providers;

/** Historical delivery survives return. Saleable inspection and committed restocking remain separate facts. */
final readonly class ShipmentSnapshot
{
    public function __construct(
        public ShipmentDescriptor $descriptor,
        public array $observations,
        public array $events
    ) {
    }

    public function toArray(): array
    {
        $observations = [];
        foreach ($this->observations as $key => $value) {
            $observations[$key] = [
                'status' => $value->status->value,
                'effectId' => $value->effectId,
                'admissionClosed' => $value->admissionClosed,
            ];
        }
        $inspection = $this->events['inspected'] ?? null;
        $original = $this->descriptor->booking->terms['quantity'];
        return [
            'descriptorFingerprint' => $this->descriptor->fingerprint(),
            'originalTerms' => $this->descriptor->booking->terms,
            'observations' => $observations,
            'events' => $this->events,
            'facts' => [
                'booking_confirmed' => $this->confirmed('booking'),
                'inventory_accounted' => $this->descriptor->deduction !== null,
                'deducted_quantity' => $this->descriptor->deduction !== null && $this->confirmed(
                    'deduction'
                ) ? $original : 0,
                'original_carrier_handover' => isset($this->events['handed-over']),
                'original_recipient_delivered' => isset($this->events['delivered']),
                'return_requested' => $this->confirmed('return'),
                'warehouse_received_quantity' => $this->events['received']['quantity'] ?? 0,
                'warehouse_inspected' => $inspection !== null,
                'accepted_quantity' => $inspection['accepted'] ?? 0,
                'saleable_quantity' => $inspection['saleable'] ?? 0,
                'full_return_accepted' => $inspection !== null && $inspection['accepted'] === $original,
                'restocked_quantity' => $this->confirmed('restock') ? $original : 0,
                'carrier_loss_confirmed' => isset($this->events['lost']),
                'transport_damage_confirmed' => isset($this->events['damaged']),
                'non_collection_confirmed' => isset($this->events['non-collection']),
            ],
        ];
    }

    private function confirmed(string $key): bool
    {
        $value = $this->observations[$key];
        return $value->status === Status::Confirmed && $value->effectId === 'effect-' . $this->descriptor->{$key}->identity->key();
    }
}
