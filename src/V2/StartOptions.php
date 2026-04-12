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
     * @var array<string, mixed>
     */
    public readonly array $memo;

    /**
     * @var array<string, scalar|null>
     */
    public readonly array $searchAttributes;

    /**
     * Seconds for the logical workflow execution timeout (spans retries and continue-as-new).
     */
    public readonly ?int $executionTimeoutSeconds;

    /**
     * Seconds for the current run timeout (resets on continue-as-new).
     */
    public readonly ?int $runTimeoutSeconds;

    /**
     * @param array<string, scalar|null> $labels
     * @param array<string, mixed> $memo
     * @param array<string, scalar|null> $searchAttributes
     */
    public function __construct(
        DuplicateStartPolicy $duplicateStartPolicy = DuplicateStartPolicy::RejectDuplicate,
        ?string $businessKey = null,
        array $labels = [],
        array $memo = [],
        array $searchAttributes = [],
        ?int $executionTimeoutSeconds = null,
        ?int $runTimeoutSeconds = null,
    ) {
        $this->duplicateStartPolicy = $duplicateStartPolicy;
        $this->businessKey = self::normalizeBusinessKey($businessKey);
        $this->labels = self::normalizeLabels($labels);
        $this->memo = self::normalizeMemo($memo);
        $this->searchAttributes = self::normalizeSearchAttributes($searchAttributes);
        $this->executionTimeoutSeconds = self::normalizeTimeout($executionTimeoutSeconds, 'execution');
        $this->runTimeoutSeconds = self::normalizeTimeout($runTimeoutSeconds, 'run');
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
        return new self(
            $this->duplicateStartPolicy,
            $businessKey,
            $this->labels,
            $this->memo,
            $this->searchAttributes,
            $this->executionTimeoutSeconds,
            $this->runTimeoutSeconds
        );
    }

    /**
     * @param array<string, scalar|null> $labels
     */
    public function withLabels(array $labels): self
    {
        return new self(
            $this->duplicateStartPolicy,
            $this->businessKey,
            $labels,
            $this->memo,
            $this->searchAttributes,
            $this->executionTimeoutSeconds,
            $this->runTimeoutSeconds
        );
    }

    /**
     * @param array<string, mixed> $memo
     */
    public function withMemo(array $memo): self
    {
        return new self(
            $this->duplicateStartPolicy,
            $this->businessKey,
            $this->labels,
            $memo,
            $this->searchAttributes,
            $this->executionTimeoutSeconds,
            $this->runTimeoutSeconds
        );
    }

    /**
     * @param array<string, scalar|null> $searchAttributes
     */
    public function withSearchAttributes(array $searchAttributes): self
    {
        return new self(
            $this->duplicateStartPolicy,
            $this->businessKey,
            $this->labels,
            $this->memo,
            $searchAttributes,
            $this->executionTimeoutSeconds,
            $this->runTimeoutSeconds
        );
    }

    public function withExecutionTimeout(?int $seconds): self
    {
        return new self(
            $this->duplicateStartPolicy,
            $this->businessKey,
            $this->labels,
            $this->memo,
            $this->searchAttributes,
            $seconds,
            $this->runTimeoutSeconds
        );
    }

    public function withRunTimeout(?int $seconds): self
    {
        return new self(
            $this->duplicateStartPolicy,
            $this->businessKey,
            $this->labels,
            $this->memo,
            $this->searchAttributes,
            $this->executionTimeoutSeconds,
            $seconds
        );
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

    /**
     * @param array<string, mixed> $memo
     * @return array<string, mixed>
     */
    private static function normalizeMemo(array $memo): array
    {
        return self::normalizeMemoObject($memo, 'memo');
    }

    /**
     * @param array<mixed, mixed> $memo
     * @return array<string, mixed>
     */
    private static function normalizeMemoObject(array $memo, string $path): array
    {
        $normalized = [];

        foreach ($memo as $key => $value) {
            if (! is_string($key) || $key === '' || strlen($key) > 64) {
                throw new LogicException(sprintf(
                    'Workflow v2 %s keys must be non-empty strings up to 64 characters.',
                    $path,
                ));
            }

            $normalized[$key] = self::normalizeMemoValue($value, sprintf('%s.%s', $path, $key));
        }

        ksort($normalized);

        return $normalized;
    }

    private static function normalizeMemoValue(mixed $value, string $path): mixed
    {
        if ($value === null || is_scalar($value)) {
            return $value;
        }

        if (! is_array($value)) {
            throw new LogicException(sprintf(
                'Workflow v2 %s values must be JSON-like scalars, null, arrays, or objects.',
                $path,
            ));
        }

        if (array_is_list($value)) {
            return array_map(
                static fn (mixed $entry): mixed => self::normalizeMemoValue($entry, $path . '[]'),
                $value,
            );
        }

        return self::normalizeMemoObject($value, $path);
    }

    /**
     * @param array<string, scalar|null> $searchAttributes
     * @return array<string, scalar|null>
     */
    private static function normalizeSearchAttributes(array $searchAttributes): array
    {
        $normalized = [];

        foreach ($searchAttributes as $key => $value) {
            if (! is_string($key) || preg_match('/^[A-Za-z0-9_.:-]{1,64}$/', $key) !== 1) {
                throw new LogicException(
                    'Workflow v2 search attribute keys must be 1-64 URL-safe characters using letters, numbers, ".", "_", "-", and ":".'
                );
            }

            if ($value !== null && ! is_scalar($value)) {
                throw new LogicException(sprintf(
                    'Workflow v2 search attribute [%s] must be a scalar value or null.',
                    $key,
                ));
            }

            if ($value === null) {
                continue;
            }

            $stringValue = is_bool($value) ? ($value ? '1' : '0') : trim((string) $value);

            if ($stringValue !== '' && strlen($stringValue) > 191) {
                throw new LogicException(sprintf(
                    'Workflow v2 search attribute [%s] must be up to 191 characters when cast to string.',
                    $key,
                ));
            }

            if ($stringValue !== '') {
                $normalized[$key] = $stringValue;
            }
        }

        ksort($normalized);

        return $normalized;
    }

    private static function normalizeTimeout(?int $seconds, string $kind): ?int
    {
        if ($seconds === null) {
            return null;
        }

        if ($seconds < 1) {
            throw new LogicException(sprintf('Workflow v2 %s timeout must be at least 1 second.', $kind));
        }

        return $seconds;
    }
}
