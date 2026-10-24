<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Throwable;
use Workflow\V2\Contracts\HistoryProjectionMaintenanceRole;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowSearchAttribute;

final class SearchAttributeDatetimeRepair
{
    public function __construct(
        private readonly HistoryProjectionMaintenanceRole $projectionRole,
    ) {
    }

    /**
     * @param list<string> $keys
     * @return array{run_id: string, namespace: ?string, dry_run: bool, examined: int, would_repair: int, repaired: int, already_correct: int}
     */
    public function repair(WorkflowRun $selectedRun, bool $apply = false, array $keys = []): array
    {
        return $selectedRun->getConnection()
            ->transaction(function () use ($selectedRun, $apply, $keys): array {
                $run = $this->selectedRun($selectedRun, $apply);
                $attributes = $this->selectedAttributes($run, $keys, $apply);
                $selectedKeys = array_map('strval', $attributes->pluck('key')->all());

                $latest = $this->latestValues($run, $selectedKeys);
                $plan = [];
                $alreadyCorrect = 0;

                foreach ($attributes as $attribute) {
                    $canonical = $this->canonicalValue($attribute, $latest[$attribute->key] ?? null);

                    if ($this->storedValue($attribute) === $canonical) {
                        $alreadyCorrect++;

                        continue;
                    }

                    $plan[] = [$attribute, $canonical];
                }

                $report = [
                    'run_id' => (string) $run->id,
                    'namespace' => $run->namespace === null ? null : (string) $run->namespace,
                    'dry_run' => ! $apply,
                    'examined' => $attributes->count(),
                    'would_repair' => count($plan),
                    'repaired' => 0,
                    'already_correct' => $alreadyCorrect,
                ];

                if (! $apply || $plan === []) {
                    return $report;
                }

                $this->applyPlan($run, $plan);
                $report['repaired'] = count($plan);

                return $report;
            });
    }

    private function selectedRun(WorkflowRun $selectedRun, bool $lock): WorkflowRun
    {
        $query = $selectedRun->newQuery()
            ->whereKey($selectedRun->id)
            ->where('namespace', $selectedRun->namespace);

        if ($lock) {
            $query->lockForUpdate();
        }

        $run = $query->firstOrFail();

        if ($run->details_pruned_at !== null) {
            throw new DomainException('Retained history is unavailable for this pruned run.');
        }

        return $run;
    }

    /**
     * @param list<string> $keys
     * @return Collection<int, WorkflowSearchAttribute>
     */
    private function selectedAttributes(WorkflowRun $run, array $keys, bool $lock): Collection
    {
        $query = $run->searchAttributes()
            ->where('type', WorkflowSearchAttribute::TYPE_DATETIME);

        if ($keys !== []) {
            $query->whereIn('key', $keys);
        }

        if ($lock) {
            $query->lockForUpdate();
        }

        $attributes = $query->orderBy('key')
            ->get();
        $selectedKeys = array_map('strval', $attributes->pluck('key')->all());

        if (array_diff($keys, $selectedKeys) !== []) {
            throw new DomainException('A selected key is not a retained datetime attribute on this run.');
        }

        return $attributes;
    }

    /**
     * @param list<array{WorkflowSearchAttribute, string}> $plan
     */
    private function applyPlan(WorkflowRun $run, array $plan): void
    {
        foreach ($plan as [$attribute, $canonical]) {
            $changed = $attribute->getConnection()
                ->table($attribute->getTable())
                ->where('id', $attribute->id)
                ->where('workflow_run_id', $run->id)
                ->where('type', WorkflowSearchAttribute::TYPE_DATETIME)
                ->where('upserted_at_sequence', $attribute->upserted_at_sequence)
                ->update([
                    'value_datetime' => $canonical,
                ]);

            if ($changed !== 1) {
                throw new DomainException('The selected datetime projection changed before repair.');
            }
        }

        $this->projectionRole->projectRun($run);
    }

    /**
     * Read newest events first, retaining only selected scalar evidence rather
     * than a run's entire history. Physical event order identifies the latest
     * value; payload.sequence identifies its original workflow command.
     *
     * @param list<string> $keys
     * @return array<string, array{sequence: mixed, type: mixed, value: mixed}>
     */
    private function latestValues(WorkflowRun $run, array $keys): array
    {
        $wanted = array_fill_keys($keys, true);
        $found = [];

        if ($wanted === []) {
            return $found;
        }

        $events = $run->historyEvents()
            ->where('event_type', HistoryEventType::SearchAttributesUpserted->value)
            ->lazyByIdDesc(10, 'sequence');

        foreach ($events as $event) {
            $payload = $event->payload;

            if (! is_array($payload) || ! is_array($payload['attributes'] ?? null)) {
                continue;
            }

            $types = is_array($payload['attribute_types'] ?? null) ? $payload['attribute_types'] : [];

            foreach ($payload['attributes'] as $key => $value) {
                if (! isset($wanted[$key])) {
                    continue;
                }

                $found[$key] = [
                    'sequence' => $payload['sequence'] ?? null,
                    'type' => $types[$key] ?? null,
                    'value' => $this->retainedValue($value),
                ];
                unset($wanted[$key]);
            }

            if ($wanted === []) {
                break;
            }
        }

        return $found;
    }

    private function retainedValue(mixed $value): int|string|null
    {
        if (is_int($value)) {
            return $value;
        }

        if (! is_string($value) || strlen($value) > WorkflowSearchAttribute::MAX_STRING_LENGTH) {
            return null;
        }

        return $value;
    }

    /**
     * @param array{sequence: mixed, type: mixed, value: mixed}|null $evidence
     */
    private function canonicalValue(WorkflowSearchAttribute $attribute, ?array $evidence): string
    {
        if ($evidence === null || ! is_int($evidence['sequence'])
            || $evidence['sequence'] !== $attribute->upserted_at_sequence
            || $evidence['type'] !== WorkflowSearchAttribute::TYPE_DATETIME) {
            throw new DomainException(sprintf(
                'Attribute [%s] has no matching latest typed history evidence.',
                $attribute->key,
            ));
        }

        $original = $this->absoluteDatetime($evidence['value']);

        if ($original === null) {
            throw new DomainException(sprintf(
                'Attribute [%s] lacks a retained absolute value with an unambiguous timezone.',
                $attribute->key,
            ));
        }

        return UtcScheduleTimestamp::databaseValue($original);
    }

    private function absoluteDatetime(mixed $value): ?DateTimeImmutable
    {
        if (is_int($value)) {
            return new DateTimeImmutable('@' . $value);
        }

        if (is_string($value) && $this->hasAbsoluteDateParts($value)) {
            return new DateTimeImmutable($value);
        }

        return null;
    }

    private function hasAbsoluteDateParts(string $value): bool
    {
        $parsed = date_parse($value);

        if ($parsed['warning_count'] !== 0 || $parsed['error_count'] !== 0 || isset($parsed['relative'])) {
            return false;
        }

        foreach (['year', 'month', 'day', 'hour', 'minute', 'second'] as $field) {
            if (! is_int($parsed[$field])) {
                return false;
            }
        }

        $numericOffset = ($parsed['zone_type'] ?? null) === 1;
        $explicitUtc = in_array(strtoupper($parsed['tz_abbr'] ?? $parsed['tz_id'] ?? ''), ['UTC', 'GMT', 'Z'], true);

        return $numericOffset || $explicitUtc;
    }

    private function storedValue(WorkflowSearchAttribute $attribute): ?string
    {
        $raw = $attribute->getRawOriginal('value_datetime');

        if (! is_string($raw) || preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d{1,6})?$/D', $raw) !== 1) {
            return null;
        }

        try {
            $value = new DateTimeImmutable($raw, new DateTimeZone('UTC'));
            $errors = DateTimeImmutable::getLastErrors();

            return $errors !== false && ($errors['warning_count'] !== 0 || $errors['error_count'] !== 0)
                ? null : $value->format('Y-m-d H:i:s.u');
        } catch (Throwable) {
            return null;
        }
    }
}
