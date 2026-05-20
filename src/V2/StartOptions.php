<?php

declare(strict_types=1);

namespace Workflow\V2;

use LogicException;
use Workflow\V2\Enums\DuplicateStartPolicy;
use Workflow\V2\Support\TaskFairnessKey;
use Workflow\V2\Support\TaskPriority;
use Workflow\V2\Support\WorkflowInstanceId;

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
     * @var array<string, scalar|list<string>|null>
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
     * Dispatch priority for the workflow's tasks. Lower numbers run first
     * when workers on a shared task queue are saturated.
     */
    public readonly int $priority;

    /**
     * Workload-class identifier used to rebalance dispatch across distinct
     * classes under contention. Tasks without a fairness key share one class.
     */
    public readonly ?string $fairnessKey;

    /**
     * Relative weight of this workload class. Higher weights receive a
     * proportionally larger share of dispatch attention versus other classes
     * with smaller weights.
     */
    public readonly int $fairnessWeight;

    /**
     * @param array<string, scalar|null> $labels
     * @param array<string, mixed> $memo
     * @param array<string, scalar|list<string>|null> $searchAttributes
     */
    public function __construct(
        DuplicateStartPolicy $duplicateStartPolicy = DuplicateStartPolicy::RejectDuplicate,
        ?string $businessKey = null,
        array $labels = [],
        array $memo = [],
        array $searchAttributes = [],
        ?int $executionTimeoutSeconds = null,
        ?int $runTimeoutSeconds = null,
        ?int $priority = null,
        ?string $fairnessKey = null,
        ?int $fairnessWeight = null,
    ) {
        $this->duplicateStartPolicy = $duplicateStartPolicy;
        $this->businessKey = self::normalizeBusinessKey($businessKey);
        $this->labels = self::normalizeLabels($labels);
        $this->memo = self::normalizeMemo($memo);
        $this->searchAttributes = self::normalizeSearchAttributes($searchAttributes);
        $this->executionTimeoutSeconds = self::normalizeTimeout($executionTimeoutSeconds, 'execution');
        $this->runTimeoutSeconds = self::normalizeTimeout($runTimeoutSeconds, 'run');
        $this->priority = TaskPriority::normalize($priority);
        $this->fairnessKey = TaskFairnessKey::normalize($fairnessKey);
        $this->fairnessWeight = TaskFairnessKey::normalizeWeight($fairnessWeight);
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
        return $this->cloneWith(['businessKey' => $businessKey]);
    }

    /**
     * @param array<string, scalar|null> $labels
     */
    public function withLabels(array $labels): self
    {
        return $this->cloneWith(['labels' => $labels]);
    }

    /**
     * @param array<string, mixed> $memo
     */
    public function withMemo(array $memo): self
    {
        return $this->cloneWith(['memo' => $memo]);
    }

    /**
     * @param array<string, scalar|list<string>|null> $searchAttributes
     */
    public function withSearchAttributes(array $searchAttributes): self
    {
        return $this->cloneWith(['searchAttributes' => $searchAttributes]);
    }

    public function withExecutionTimeout(?int $seconds): self
    {
        return $this->cloneWith(['executionTimeoutSeconds' => $seconds]);
    }

    public function withRunTimeout(?int $seconds): self
    {
        return $this->cloneWith(['runTimeoutSeconds' => $seconds]);
    }

    public function withPriority(?int $priority): self
    {
        return $this->cloneWith(['priority' => $priority]);
    }

    public function withFairness(?string $fairnessKey, ?int $fairnessWeight = null): self
    {
        return $this->cloneWith([
            'fairnessKey' => $fairnessKey,
            'fairnessWeight' => $fairnessWeight,
        ]);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function cloneWith(array $overrides): self
    {
        return new self(
            $overrides['duplicateStartPolicy'] ?? $this->duplicateStartPolicy,
            array_key_exists('businessKey', $overrides) ? $overrides['businessKey'] : $this->businessKey,
            array_key_exists('labels', $overrides) ? $overrides['labels'] : $this->labels,
            array_key_exists('memo', $overrides) ? $overrides['memo'] : $this->memo,
            array_key_exists('searchAttributes', $overrides) ? $overrides['searchAttributes'] : $this->searchAttributes,
            array_key_exists('executionTimeoutSeconds', $overrides) ? $overrides['executionTimeoutSeconds'] : $this->executionTimeoutSeconds,
            array_key_exists('runTimeoutSeconds', $overrides) ? $overrides['runTimeoutSeconds'] : $this->runTimeoutSeconds,
            array_key_exists('priority', $overrides) ? $overrides['priority'] : $this->priority,
            array_key_exists('fairnessKey', $overrides) ? $overrides['fairnessKey'] : $this->fairnessKey,
            array_key_exists('fairnessWeight', $overrides) ? $overrides['fairnessWeight'] : $this->fairnessWeight,
        );
    }

    private static function normalizeBusinessKey(?string $businessKey): ?string
    {
        if ($businessKey === null) {
            return null;
        }

        $businessKey = trim($businessKey);

        if ($businessKey === '' || strlen($businessKey) > WorkflowInstanceId::MAX_LENGTH) {
            throw new LogicException(sprintf(
                'Workflow v2 business keys must be non-empty strings up to %d characters.',
                WorkflowInstanceId::MAX_LENGTH,
            ));
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

            if ($stringValue === '' || strlen($stringValue) > WorkflowInstanceId::MAX_LENGTH) {
                throw new LogicException(sprintf(
                    'Workflow v2 visibility label [%s] must be a non-empty string up to %d characters.',
                    $key,
                    WorkflowInstanceId::MAX_LENGTH,
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
     * @param array<string, scalar|list<string>|null> $searchAttributes
     * @return array<string, scalar|list<string>>
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

            if ($value !== null && ! is_scalar($value) && ! is_array($value)) {
                throw new LogicException(sprintf(
                    'Workflow v2 search attribute [%s] must be a scalar value, string list, or null.',
                    $key,
                ));
            }

            if ($value === null) {
                continue;
            }

            if (is_array($value)) {
                if (! array_is_list($value)) {
                    throw new LogicException(sprintf(
                        'Workflow v2 search attribute [%s] list value must be a JSON array.',
                        $key,
                    ));
                }

                $list = [];

                foreach ($value as $entry) {
                    if (! is_string($entry)) {
                        throw new LogicException(sprintf(
                            'Workflow v2 search attribute [%s] list values must contain only strings.',
                            $key,
                        ));
                    }

                    $trimmed = trim($entry);

                    if ($trimmed !== '' && strlen($trimmed) > WorkflowInstanceId::MAX_LENGTH) {
                        throw new LogicException(sprintf(
                            'Workflow v2 search attribute [%s] list values must be up to %d characters.',
                            $key,
                            WorkflowInstanceId::MAX_LENGTH,
                        ));
                    }

                    $list[] = $trimmed;
                }

                $normalized[$key] = $list;

                continue;
            }

            $stringValue = is_bool($value) ? ($value ? '1' : '0') : (is_string($value) ? trim($value) : (string) $value);

            if ($stringValue !== '' && strlen($stringValue) > WorkflowInstanceId::MAX_LENGTH) {
                throw new LogicException(sprintf(
                    'Workflow v2 search attribute [%s] must be up to %d characters when cast to string.',
                    $key,
                    WorkflowInstanceId::MAX_LENGTH,
                ));
            }

            if ($stringValue !== '') {
                $normalized[$key] = is_string($value) ? $stringValue : $value;
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
