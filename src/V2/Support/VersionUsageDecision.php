<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

/** @internal Reduces retained marker data without replay or application payload decoding. */
final class VersionUsageDecision
{
    private ?int $version = null;

    private ?string $reason;

    private array $sequences = [];

    public function __construct(
        private string $changeId,
        private int $minVersion,
        bool $historyAvailable
    ) {
        $this->reason = $historyAvailable ? 'marker_absent' : 'history_unavailable';
    }

    public function observe(mixed $payload): void
    {
        if (in_array($this->reason, ['history_invalid', 'history_unavailable'], true)) {
            return;
        }
        if (count($this->sequences) >= 1000) {
            $this->reason = 'history_unavailable';
            return;
        }
        if (! is_array($payload)
            || ! is_string($payload['change_id'] ?? null) || $payload['change_id'] === ''
            || ! is_int($payload['sequence'] ?? null) || $payload['sequence'] < 1
            || isset($this->sequences[$payload['sequence']])
            || ! self::integer($payload['version'] ?? null)
            || ! self::integer($payload['min_supported'] ?? null)
            || ! self::integer($payload['max_supported'] ?? null)
            || $payload['min_supported'] > $payload['max_supported']
            || $payload['version'] < $payload['min_supported']
            || $payload['version'] > $payload['max_supported']) {
            $this->reason = 'history_invalid';
            return;
        }
        if (array_diff(array_keys($payload), [
            'sequence', 'change_id', 'version', 'min_supported', 'max_supported',
            'reused_from_run_id', 'reused_recorded_at',
        ]) !== []) {
            $this->reason = 'history_unavailable';
            return;
        }
        $this->sequences[$payload['sequence']] = true;
        if ($payload['change_id'] !== $this->changeId) {
            return;
        }
        if ($this->version !== null && $this->version !== $payload['version']) {
            $this->reason = 'history_invalid';
            return;
        }
        $this->version = $payload['version'];
        $this->reason = null;
    }

    public function unavailable(): void
    {
        $this->reason = 'history_unavailable';
    }

    public function result(): array
    {
        return [
            'state' => $this->reason !== null ? 'unknown' : ($this->version < $this->minVersion ? 'legacy' : 'current'),
            'recorded_version' => $this->reason === null ? $this->version : null,
            'unknown_reason' => $this->reason,
        ];
    }

    public static function integer(mixed $value): bool
    {
        return is_int($value) && $value >= -2147483648 && $value <= 2147483647;
    }
}
