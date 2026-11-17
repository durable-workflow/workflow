<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ProviderStateMachines;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Identity;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Request;
use InvalidArgumentException;
use Workflow\V2\Compensation\Definition\PortableDescriptor;

/** Trusted immutable assignment for one subscription period, with an optional genuine Order. */
final readonly class BillingPeriod
{
    public const PAYMENT_FIRST = 'payment-first';

    public const GRACE = 'grace';

    public function __construct(
        public string $client,
        public string $subscription,
        public string $generation,
        public string $period,
        public int $amountMinor,
        public string $currency,
        public int $startsAt,
        public int $endsAt,
        public int $paymentDeadline,
        public string $activationPolicy,
        public bool $accessDuringGrace = false,
        public ?OrderTerms $order = null,
    ) {
        foreach (['client', 'subscription', 'generation', 'period'] as $field) {
            PortableDescriptor::identifier($this->{$field}, $field);
        }
        if ($amountMinor <= 0 || preg_match('/\A[A-Z]{3}\z/D', $currency) !== 1
            || $startsAt <= 0 || $endsAt <= $startsAt || $paymentDeadline < $startsAt
            || $paymentDeadline >= $endsAt) {
            throw new InvalidArgumentException('A billing period requires original money and fixed ordered deadlines.');
        }
        if (! in_array($activationPolicy, [self::PAYMENT_FIRST, self::GRACE], true)
            || ($activationPolicy === self::PAYMENT_FIRST && $accessDuringGrace)
            || ($activationPolicy === self::GRACE && $paymentDeadline === $startsAt)) {
            throw new InvalidArgumentException('The original activation and grace-access policy is unavailable.');
        }
        if ($order !== null && ($order->client !== $client || $order->amountMinor !== $amountMinor
            || $order->currency !== $currency || $order->generation !== $this->ownerGeneration()
            || $order->authorization !== null || $order->incomingPayment !== null
            || $order->codPayment !== null || $order->advanceBalance !== null)) {
            throw new InvalidArgumentException('A period Order must preserve this exact period owner and money.');
        }
    }

    /**
     * The configured example is ten elapsed UTC days, fixed at assignment rather than at a retry.
     */
    public static function graceDeadline(int $startsAt, int $graceSeconds = 864000): int
    {
        if ($startsAt <= 0 || $graceSeconds <= 0 || $startsAt > PHP_INT_MAX - $graceSeconds) {
            throw new InvalidArgumentException('The original grace interval must have a finite integer deadline.');
        }
        return $startsAt + $graceSeconds;
    }

    public function ownerGeneration(): string
    {
        return hash('sha256', PortableDescriptor::encode([
            'billing-period.v1', $this->client, $this->subscription, $this->generation, $this->period,
        ]));
    }

    public function subjectKey(): string
    {
        return hash('sha256', PortableDescriptor::encode([
            $this->client, $this->subscription, $this->ownerGeneration(),
        ]));
    }

    /**
     * Neither cancellation of future billing nor a later period selects this original refund.
     */
    public function paymentDescriptor(): array
    {
        $identity = fn (string $operation): Identity => new Identity(
            $this->client,
            $this->subscription,
            $this->ownerGeneration(),
            'period.' . $operation,
        );
        $capture = $identity('capture');
        $money = [
            'amountMinor' => $this->amountMinor,
            'currency' => $this->currency,
        ];
        return [
            'forward' => (new Request($capture, 'capture', $money))->toArray(),
            'compensation' => (new Request($identity('refund'), 'refund', $money, $capture))->toArray(),
        ];
    }

    public function toArray(): array
    {
        $value = get_object_vars($this);
        $value['order'] = $this->order?->toArray();
        return $value;
    }

    public function fingerprint(): string
    {
        return hash('sha256', PortableDescriptor::encode($this->toArray()));
    }

    public static function fromArray(array $value): self
    {
        $keys = array_keys($value);
        sort($keys);
        if ($keys !== [
            'accessDuringGrace', 'activationPolicy', 'amountMinor', 'client', 'currency', 'endsAt',
            'generation', 'order', 'paymentDeadline', 'period', 'startsAt', 'subscription',
        ]) {
            throw new InvalidArgumentException('The original billing-period assignment fields are malformed.');
        }
        foreach (['client', 'subscription', 'generation', 'period', 'currency', 'activationPolicy'] as $field) {
            if (! is_string($value[$field])) {
                throw new InvalidArgumentException('Billing-period identity and policy fields must be strings.');
            }
        }
        foreach (['amountMinor', 'startsAt', 'endsAt', 'paymentDeadline'] as $field) {
            if (! is_int($value[$field])) {
                throw new InvalidArgumentException('Billing-period money and deadlines must be integers.');
            }
        }
        if (! is_bool($value['accessDuringGrace']) || ($value['order'] !== null && ! is_array($value['order']))) {
            throw new InvalidArgumentException('Billing-period access and optional Order terms are malformed.');
        }
        $value['order'] = $value['order'] === null ? null : OrderTerms::fromArray($value['order']);
        return new self(...$value);
    }
}
