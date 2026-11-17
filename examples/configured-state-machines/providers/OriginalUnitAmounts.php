<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ConfiguredStateMachines\Providers;

use InvalidArgumentException;
use Workflow\V2\Compensation\Definition\PortableDescriptor;

/** Original explicit charged-unit allocation; no inferred tax, shipping or rounding policy. */
final readonly class OriginalUnitAmounts
{
    public function __construct(
        public Request $capture,
        public array $amounts,
    ) {
        $total = $capture->terms['amountMinor'] ?? null;
        $currency = $capture->terms['currency'] ?? null;
        if ($capture->action !== 'capture' || ! is_int($total) || $total <= 0
            || ! is_string($currency) || preg_match('/\A[A-Z]{3}\z/D', $currency) !== 1
            || ! array_is_list($amounts) || $amounts === [] || count($amounts) > 1000) {
            throw new InvalidArgumentException(
                'Unit allocation requires its complete original direct capture and explicit unit amounts.'
            );
        }
        $sum = 0;
        foreach ($amounts as $amount) {
            if (! is_int($amount) || $amount < 0 || $sum > PHP_INT_MAX - $amount) {
                throw new InvalidArgumentException(
                    'Original unit amounts must be nonnegative integers without overflow.'
                );
            }
            $sum += $amount;
        }
        if ($sum !== $total) {
            throw new InvalidArgumentException(
                'Original unit amounts must equal the complete captured command amount.'
            );
        }
    }

    public function ordinals(array $units): array
    {
        if (! array_is_list($units) || $units === []) {
            throw new InvalidArgumentException('A refund requires explicit unique original unit ordinals.');
        }
        foreach ($units as $unit) {
            if (! is_int($unit) || $unit < 0 || $unit >= count($this->amounts)) {
                throw new InvalidArgumentException('A refund cannot target another original unit.');
            }
        }
        if (count($units) !== count(array_unique($units))) {
            throw new InvalidArgumentException('A refund cannot repeat original unit ordinals.');
        }
        sort($units);
        return $units;
    }

    public function amountFor(array $units): int
    {
        $sum = 0;
        foreach ($this->ordinals($units) as $unit) {
            $sum += $this->amounts[$unit];
        }
        return $sum;
    }

    public function toArray(): array
    {
        return [
            'version' => 1,
            'capture' => $this->capture->toArray(),
            'amounts' => $this->amounts,
        ];
    }

    public function fingerprint(): string
    {
        return hash('sha256', PortableDescriptor::encode($this->toArray()));
    }

    public static function fromArray(array $value): self
    {
        $keys = array_keys($value);
        sort($keys);
        if ($keys !== ['amounts', 'capture', 'version'] || $value['version'] !== 1
            || ! is_array($value['capture']) || ! is_array($value['amounts'])) {
            throw new InvalidArgumentException('The original charged-unit allocation fields are malformed.');
        }
        return new self(Request::fromArray($value['capture']), $value['amounts']);
    }
}
