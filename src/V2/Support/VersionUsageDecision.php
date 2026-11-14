<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use DateTimeImmutable;

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
        if (! self::validReuse($payload)) {
            $this->reason = 'history_invalid';
            return;
        }
        if (array_diff(array_keys($payload), [
            'sequence', 'change_id', 'version', 'min_supported', 'max_supported',
            'reused_from_run_id', 'reused_recorded_at', 'task',
        ]) !== [] || (array_key_exists('task', $payload) && (! is_array($payload['task'])
            || array_is_list($payload['task'])))) {
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

    private static function validReuse(array $payload): bool
    {
        $hasSource = array_key_exists('reused_from_run_id', $payload);
        if ($hasSource !== array_key_exists('reused_recorded_at', $payload)) {
            return false;
        }
        if (! $hasSource) {
            return true;
        }
        $source = $payload['reused_from_run_id'];
        $clock = $payload['reused_recorded_at'];
        if (! is_string($source) || preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.:-]{0,190}\z/D', $source) !== 1
            || ! is_string($clock) || strlen($clock) > 64
            || preg_match(
                '/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]{1,6})?(?:Z|[+-](?:[01][0-9]|2[0-3]):[0-5][0-9])\z/D',
                $clock
            ) !== 1) {
            return false;
        }
        $time = DateTimeImmutable::createFromFormat(
            str_contains($clock, '.') ? '!Y-m-d\TH:i:s.uP' : '!Y-m-d\TH:i:sP',
            $clock,
        );
        $errors = DateTimeImmutable::getLastErrors();
        return $time !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0));
    }
}
