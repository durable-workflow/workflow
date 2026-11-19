<?php

declare(strict_types=1);

namespace Workflow\V2\Compensation\Execution;

use InvalidArgumentException;
use Workflow\V2\Compensation\Definition\PortableDescriptor;

/**
 * Outcome of a compensation, returned by ScopedOperation::compensate().
 *
 * - verified(): the effect is undone and no late forward effect is possible. Requires evidence.
 * - unknown(): compensation is not verified yet, including while a late forward effect is still possible.
 *   Native retries continue.
 * - failed(): compensation definitely cannot complete. The obligation stays unresolved with status
 *   `failed`, without further retries in this run, until a new authorized request.
 *
 * @api Stable compensation-scope API (#1063). See docs/api-stability.md#compensation-scopes.
 */
final readonly class Resolution
{
    public const VERIFIED = 'verified';

    public const UNKNOWN = 'unknown';

    public const FAILED = 'failed';

    /**
     * Whether the outcome is VERIFIED.
     */
    public bool $verified;

    private function __construct(
        public string $outcome,
        public string $reason,
        public array $evidence,
    ) {
        if (! in_array($outcome, [self::VERIFIED, self::UNKNOWN, self::FAILED], true)) {
            throw new InvalidArgumentException('Unsupported compensation outcome.');
        }
        PortableDescriptor::identifier($reason, 'outcome reason');
        EvidenceReferences::validate($evidence);
        if ($outcome === self::VERIFIED && $evidence === []) {
            throw new InvalidArgumentException('Verified compensation requires authoritative evidence.');
        }
        if ($outcome === self::UNKNOWN && $evidence !== []) {
            throw new InvalidArgumentException('An unknown compensation outcome carries no evidence.');
        }
        $this->verified = $outcome === self::VERIFIED;
        PortableDescriptor::encode($this->toArray());
    }

    /**
     * Passing a second boolean argument, the forwardSettled flag of earlier releases, is deprecated and
     * accepted for compatibility: `true` is ignored, and `false` returns unknown('late-forward-possible').
     *
     * @param list<string> $evidence 1 to 32 references of at most 128 bytes
     */
    public static function verified(array $evidence): self
    {
        if (func_num_args() > 1) {
            $forwardSettled = func_get_arg(1);
            if (func_num_args() > 2 || ! is_bool($forwardSettled)) {
                throw new HandlerContractViolation('Resolution::verified() takes only evidence.');
            }
            trigger_error(
                'Passing forwardSettled to Resolution::verified() is deprecated. Return verified($evidence) when no '
                . 'late forward effect is possible, and unknown() otherwise.',
                E_USER_DEPRECATED
            );
            if (! $forwardSettled) {
                return self::make(self::UNKNOWN, 'late-forward-possible', []);
            }
        }
        return self::make(self::VERIFIED, 'verified', $evidence);
    }

    public static function unknown(string $reason): self
    {
        return self::make(self::UNKNOWN, $reason, []);
    }

    /**
     * @param list<string> $evidence optional proof that compensation cannot complete
     */
    public static function failed(string $reason, array $evidence = []): self
    {
        return self::make(self::FAILED, $reason, $evidence);
    }

    /**
     * @internal Ledger envelope. Outcomes expressible in version 1 are still written as version 1.
     */
    public function toArray(): array
    {
        if ($this->outcome === self::FAILED) {
            return [
                'version' => 2,
                'outcome' => $this->outcome,
                'reason' => $this->reason,
                'evidence' => $this->evidence,
            ];
        }
        return [
            'version' => 1,
            'verified' => $this->verified,
            'reason' => $this->reason,
            'evidence' => $this->evidence,
            'forwardSettled' => $this->verified,
        ];
    }

    /**
     * @internal Decodes retained version-1 and version-2 envelopes.
     *
     * A version-1 verified outcome recorded with forwardSettled false meant a late forward effect was
     * still possible; the engine never accepted it as verified, so it decodes as unknown.
     */
    public static function fromArray(array $value): self
    {
        $keys = array_keys($value);
        sort($keys, SORT_STRING);
        if (($value['version'] ?? null) === 1
            && $keys === ['evidence', 'forwardSettled', 'reason', 'verified', 'version']
            && is_bool($value['verified']) && is_bool($value['forwardSettled'])) {
            if ($value['verified'] && $value['forwardSettled']) {
                return new self(self::VERIFIED, $value['reason'], $value['evidence']);
            }
            return new self(self::UNKNOWN, $value['verified'] ? 'late-forward-possible' : $value['reason'], []);
        }
        if (($value['version'] ?? null) === 2
            && $keys === ['evidence', 'outcome', 'reason', 'version'] && is_string($value['outcome'])) {
            return new self($value['outcome'], $value['reason'], $value['evidence']);
        }
        throw new InvalidArgumentException('Unsupported compensation resolution format.');
    }

    private static function make(string $outcome, string $reason, array $evidence): self
    {
        try {
            return new self($outcome, $reason, $evidence);
        } catch (InvalidArgumentException $violation) {
            throw new HandlerContractViolation($violation->getMessage(), $violation);
        }
    }
}
