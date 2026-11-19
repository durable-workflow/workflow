<?php

declare(strict_types=1);

namespace Workflow\V2\Compensation\Execution;

use InvalidArgumentException;
use Workflow\V2\Compensation\Definition\PortableDescriptor;

/**
 * Outcome of a forward provider operation, returned by ScopedOperation::execute().
 *
 * - observed(): the effect is confirmed. The value is returned to the workflow.
 * - unknown(): the provider outcome is uncertain. Native retries reconcile it by operation ID.
 * - rejected(): the provider definitely made no effect. The workflow receives a non-retryable
 *   `scope_forward_rejected` failure, and compensation of this obligation verifies without a provider call.
 *
 * @experimental The Compensation API is not covered by stability guarantees and may change in a minor release.
 */
final readonly class ForwardResult
{
    public const OBSERVED = 'observed';

    public const UNKNOWN = 'unknown';

    public const REJECTED = 'rejected';

    /**
     * Whether the outcome is OBSERVED.
     */
    public bool $observed;

    private function __construct(
        public string $outcome,
        public array $value,
        public array $evidence,
        public string $reason
    ) {
        if (! in_array($outcome, [self::OBSERVED, self::UNKNOWN, self::REJECTED], true)) {
            throw new InvalidArgumentException('Unsupported forward outcome.');
        }
        PortableDescriptor::identifier($reason, 'forward reason');
        EvidenceReferences::validate($evidence);
        if ($outcome === self::OBSERVED && $evidence === []) {
            throw new InvalidArgumentException('An observed forward outcome requires evidence.');
        }
        if ($outcome !== self::OBSERVED && $value !== []) {
            throw new InvalidArgumentException('Only an observed forward outcome carries a value.');
        }
        if ($outcome === self::UNKNOWN && $evidence !== []) {
            throw new InvalidArgumentException('An unknown forward outcome carries no evidence.');
        }
        $this->observed = $outcome === self::OBSERVED;
        PortableDescriptor::encode($this->toArray());
    }

    /**
     * @param list<string> $evidence 1 to 32 references of at most 128 bytes
     */
    public static function observed(array $value, array $evidence): self
    {
        PortableDescriptor::userData(
            $value,
            'forward value',
            PortableDescriptor::USER_VALUE_MAX_BYTES,
            PortableDescriptor::USER_VALUE_MAX_VALUES,
            PortableDescriptor::USER_VALUE_MAX_DEPTH,
        );
        return new self(self::OBSERVED, $value, $evidence, 'observed');
    }

    public static function unknown(string $reason): self
    {
        return new self(self::UNKNOWN, [], [], $reason);
    }

    /**
     * @param list<string> $evidence optional proof of the definite refusal
     */
    public static function rejected(string $reason, array $evidence = []): self
    {
        return new self(self::REJECTED, [], $evidence, $reason);
    }

    /**
     * @internal Ledger envelope. Outcomes expressible in version 1 are still written as version 1.
     */
    public function toArray(): array
    {
        if ($this->outcome === self::REJECTED) {
            return [
                'version' => 2,
                'outcome' => $this->outcome,
                'value' => $this->value,
                'evidence' => $this->evidence,
                'reason' => $this->reason,
            ];
        }
        return [
            'version' => 1,
            'observed' => $this->observed,
            'value' => $this->value,
            'evidence' => $this->evidence,
            'reason' => $this->reason,
        ];
    }

    /**
     * @internal Decodes retained version-1 and version-2 envelopes without applying caller limits.
     */
    public static function fromArray(array $value): self
    {
        $keys = array_keys($value);
        sort($keys, SORT_STRING);
        if (($value['version'] ?? null) === 1
            && $keys === ['evidence', 'observed', 'reason', 'value', 'version'] && is_bool($value['observed'])) {
            return new self(
                $value['observed'] ? self::OBSERVED : self::UNKNOWN,
                $value['value'],
                $value['evidence'],
                $value['reason'],
            );
        }
        if (($value['version'] ?? null) === 2
            && $keys === ['evidence', 'outcome', 'reason', 'value', 'version'] && is_string($value['outcome'])) {
            return new self($value['outcome'], $value['value'], $value['evidence'], $value['reason']);
        }
        throw new InvalidArgumentException('Unsupported compensation forward-result format.');
    }
}
