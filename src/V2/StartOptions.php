<?php

declare(strict_types=1);

namespace Workflow\V2;

use LogicException;
use Workflow\V2\Enums\DuplicateStartPolicy;

final class StartOptions
{
    public readonly DuplicateStartPolicy $duplicateStartPolicy;

    public readonly ?string $businessKey;

    /**
     * @var array<string, string>
     */
    public readonly array $labels;

    /**
     * @param array<string, scalar|null> $labels
     */
    public function __construct(
        DuplicateStartPolicy $duplicateStartPolicy = DuplicateStartPolicy::RejectDuplicate,
        ?string $businessKey = null,
        array $labels = [],
    ) {
        $this->duplicateStartPolicy = $duplicateStartPolicy;
        $this->businessKey = self::normalizeBusinessKey($businessKey);
        $this->labels = self::normalizeLabels($labels);
    }

    public static function rejectDuplicate(): self
    {
        return new self(DuplicateStartPolicy::RejectDuplicate);
    }

    public static function returnExistingActive(): self
    {
        return new self(DuplicateStartPolicy::ReturnExistingActive);
    }

    /**
     * @param array<string, scalar|null> $labels
     */
    public static function withVisibility(
        ?string $businessKey = null,
        array $labels = [],
        DuplicateStartPolicy $duplicateStartPolicy = DuplicateStartPolicy::RejectDuplicate,
    ): self {
        return new self($duplicateStartPolicy, $businessKey, $labels);
    }

    public function withBusinessKey(?string $businessKey): self
    {
        return new self($this->duplicateStartPolicy, $businessKey, $this->labels);
    }

    /**
     * @param array<string, scalar|null> $labels
     */
    public function withLabels(array $labels): self
    {
        return new self($this->duplicateStartPolicy, $this->businessKey, $labels);
    }

    private static function normalizeBusinessKey(?string $businessKey): ?string
    {
        if ($businessKey === null) {
            return null;
        }

        $businessKey = trim($businessKey);

        if ($businessKey === '' || strlen($businessKey) > 191) {
            throw new LogicException('Workflow v2 business keys must be non-empty strings up to 191 characters.');
        }

        return $businessKey;
    }

    /**
     * @param array<string, scalar|null> $labels
     * @return array<string, string>
     */
    private static function normalizeLabels(array $labels): array
    {
        $normalized = [];

        foreach ($labels as $key => $value) {
            if (! is_string($key) || preg_match('/^[A-Za-z0-9_.:-]{1,64}$/', $key) !== 1) {
                throw new LogicException(
                    'Workflow v2 visibility label keys must be 1-64 URL-safe characters using letters, numbers, ".", "_", "-", and ":".'
                );
            }

            if (! is_scalar($value) && $value !== null) {
                throw new LogicException(sprintf(
                    'Workflow v2 visibility label [%s] must be a scalar value or null.',
                    $key
                ));
            }

            if ($value === null) {
                continue;
            }

            $stringValue = trim((string) $value);

            if ($stringValue === '' || strlen($stringValue) > 191) {
                throw new LogicException(sprintf(
                    'Workflow v2 visibility label [%s] must be a non-empty string up to 191 characters.',
                    $key
                ));
            }

            $normalized[$key] = $stringValue;
        }

        ksort($normalized);

        return $normalized;
    }
}
