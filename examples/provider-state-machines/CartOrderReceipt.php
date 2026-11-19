<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ProviderStateMachines;

use LogicException;
use Workflow\V2\Compensation\Definition\PortableDescriptor;
use Workflow\V2\StateMachine\Binding\ChildBinding;

/** Read-only validation of the original purchase-pinned Order and live paid publication. */
final readonly class CartOrderReceipt
{
    public function __construct(
        private Authority $authority
    ) {
    }

    public function read(string $key, object $row, CartPlan $plan): array
    {
        if ($row->order_id === null) {
            throw new LogicException('This cart has no formal Order association.');
        }
        $order = $this->authority->db->table('provider_cart_orders')
            ->where('id', $row->order_id)
            ->sole();
        if ($order->purchase_key !== $key || strlen($order->receipt) > PortableDescriptor::MAX_BYTES
            || ! hash_equals($row->order_sha256, hash('sha256', $order->receipt))
            || ! hash_equals($order->receipt_sha256, $row->order_sha256)) {
            throw new LogicException('The original formal Order association is unavailable or changed.');
        }
        $value = json_decode($order->receipt, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($value) || ! is_string($row->selected) || ! is_array($value['paymentBinding'] ?? null)
            || ! is_array($value['paymentPublication'] ?? null)) {
            throw new LogicException('The original formal Order receipt is malformed.');
        }
        $binding = $this->authority->plan($key);
        $expectedId = hash(
            'sha256',
            PortableDescriptor::encode([$plan->initial->reserve->identity->client, $plan->orderReference])
        );
        $expected = [
            'orderReference' => $plan->orderReference,
            'cartId' => $plan->initial->terms->cartId,
            'purchaseKey' => $key,
            'rootRun' => $binding->rootRun,
            'scope' => $binding->rootScope()
                ->toArray(),
            'planFingerprint' => $plan->fingerprint(),
            'assignmentFingerprint' => $row->assignment_sha256,
            'selected' => $row->selected,
            'reservation' => $plan->lease($row->selected)
                ->reserve->toArray(),
            'paymentBinding' => ChildBinding::fromArray($value['paymentBinding'])->toArray(),
            'paymentPublication' => $value['paymentPublication'],
        ];
        if ($row->order_id !== $expectedId || PortableDescriptor::encode($value) !== PortableDescriptor::encode(
            $expected
        )
            || $value['paymentBinding']['identity'] !== PortableDescriptor::snapshot(
                $binding->binding('payment')
                    ->toArray()
            )) {
            throw new LogicException('The formal Order substituted its original owner, plan or reservation.');
        }
        $this->assertOrderPublication($key, $value['paymentPublication']);
        return $value;
    }

    private function assertOrderPublication(string $key, array $publication): void
    {
        $id = $publication['id'] ?? null;
        if (! is_string($id)) {
            throw new LogicException('The original paid publication is unavailable.');
        }
        $paid = $this->authority->db->table('example_machine_publications')
            ->where('id', $id)
            ->first();
        unset($publication['id']);
        if ($paid === null || $paid->subject_id !== Authority::subjectId($key, 'payment')
            || strlen($paid->payload) > PortableDescriptor::MAX_BYTES
            || PortableDescriptor::encode(json_decode($paid->payload, true, flags: JSON_THROW_ON_ERROR))
                !== PortableDescriptor::encode($publication)) {
            throw new LogicException('The original paid publication is unavailable or changed.');
        }
    }
}
