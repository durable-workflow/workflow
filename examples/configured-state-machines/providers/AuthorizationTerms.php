<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ConfiguredStateMachines\Providers;

use InvalidArgumentException;

/** Original provider terms: an authorization is permission to capture, not captured money. */
final readonly class AuthorizationTerms
{
    public function __construct(
        public int $amountMinor,
        public string $currency,
        public int $expiresAt,
    ) {
        if ($amountMinor <= 0 || preg_match('/\A[A-Z]{3}\z/D', $currency) !== 1 || $expiresAt <= 0) {
            throw new InvalidArgumentException(
                'Authorization requires positive integer money, currency and absolute expiry.'
            );
        }
    }

    public function toArray(): array
    {
        return [
            'amountMinor' => $this->amountMinor,
            'currency' => $this->currency,
            'expiresAt' => $this->expiresAt,
        ];
    }

    public static function fromArray(array $value): self
    {
        $keys = array_keys($value);
        sort($keys);
        if ($keys !== ['amountMinor', 'currency', 'expiresAt'] || ! is_int($value['amountMinor'])
            || ! is_string($value['currency']) || ! is_int($value['expiresAt'])) {
            throw new InvalidArgumentException('Authorization terms have unexpected, missing or coerced fields.');
        }
        return new self($value['amountMinor'], $value['currency'], $value['expiresAt']);
    }

    public function canAdmitCaptureAt(int $time): bool
    {
        if ($time < 0) {
            throw new InvalidArgumentException('Provider admission time must be a nonnegative Unix timestamp.');
        }
        return $time < $this->expiresAt;
    }

    public function captureTerms(): array
    {
        return [
            'amountMinor' => $this->amountMinor,
            'currency' => $this->currency,
        ];
    }
}
