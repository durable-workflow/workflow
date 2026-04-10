<?php

declare(strict_types=1);

namespace Workflow\Commands;

use Illuminate\Console\Command;
use JsonException;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;

#[AsCommand(name: 'workflow:v2:backfill-failure-types')]
class V2BackfillFailureTypesCommand extends Command
{
    private const FAILURE_EVENT_TYPES = [
        HistoryEventType::ActivityFailed,
        HistoryEventType::ChildRunFailed,
        HistoryEventType::WorkflowFailed,
        HistoryEventType::UpdateCompleted,
    ];

    protected $signature = 'workflow:v2:backfill-failure-types
        {--run-id=* : Backfill one or more selected workflow run ids}
        {--instance-id= : Backfill every run for one workflow instance id}
        {--dry-run : Report the affected rows without changing history events}
        {--strict : Return a failure exit code when any legacy failure event cannot be mapped}
        {--json : Output the backfill report as JSON}';

    protected $description = 'Backfill durable exception type aliases onto older Workflow v2 failure history events';

    public function handle(): int
    {
        $runIds = $this->runIds();
        $instanceId = $this->stringOption('instance-id');
        $dryRun = (bool) $this->option('dry-run');
        $strict = (bool) $this->option('strict');

        $query = $this->eventQuery($runIds, $instanceId);

        $report = [
            'dry_run' => $dryRun,
            'strict' => $strict,
            'failure_events_matched' => (clone $query)->count(),
            'failure_events_scanned' => 0,
            'failure_events_skipped' => 0,
            'failure_events_already_typed' => 0,
            'failure_events_updated' => 0,
            'failure_events_would_update' => 0,
            'unresolved' => [],
            'failures' => [],
        ];

        $query->chunkById(100, function ($events) use (&$report, $dryRun): void {
            foreach ($events as $event) {
                if (! $event instanceof WorkflowHistoryEvent) {
                    continue;
                }

                $report['failure_events_scanned']++;

                try {
                    $result = $this->normalizePayload($event);

                    if ($result['status'] === 'not_failure') {
                        $report['failure_events_skipped']++;

                        continue;
                    }

                    if ($result['status'] === 'already_typed') {
                        $report['failure_events_already_typed']++;

                        continue;
                    }

                    if ($result['status'] === 'unresolved') {
                        $report['unresolved'][] = $this->unresolvedReport($event, $result);

                        continue;
                    }

                    if ($dryRun) {
                        $report['failure_events_would_update']++;

                        continue;
                    }

                    $event->forceFill([
                        'payload' => $result['payload'],
                    ])->save();

                    $report['failure_events_updated']++;
                } catch (Throwable $exception) {
                    $report['failures'][] = [
                        'event_id' => $event->id,
                        'run_id' => $event->workflow_run_id,
                        'sequence' => $event->sequence,
                        'message' => $exception->getMessage(),
                    ];
                }
            }
        });

        $this->renderReport($report);

        if ($report['failures'] !== []) {
            return self::FAILURE;
        }

        if ($strict && $report['unresolved'] !== []) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param list<string> $runIds
     */
    private function eventQuery(array $runIds, ?string $instanceId)
    {
        $historyModel = $this->historyEventModel();
        $runModel = $this->runModel();
        $eventTypes = array_map(
            static fn (HistoryEventType $eventType): string => $eventType->value,
            self::FAILURE_EVENT_TYPES,
        );

        $query = $historyModel::query()
            ->whereIn('event_type', $eventTypes)
            ->orderBy('id');

        if ($runIds !== []) {
            $query->whereIn('workflow_run_id', $runIds);
        }

        if ($instanceId !== null) {
            $query->whereIn(
                'workflow_run_id',
                $runModel::query()
                    ->select('id')
                    ->where('workflow_instance_id', $instanceId),
            );
        }

        return $query;
    }

    /**
     * @return class-string<WorkflowHistoryEvent>
     */
    private function historyEventModel(): string
    {
        /** @var class-string<WorkflowHistoryEvent> $model */
        $model = config('workflows.v2.history_event_model', WorkflowHistoryEvent::class);

        return $model;
    }

    /**
     * @return class-string<WorkflowRun>
     */
    private function runModel(): string
    {
        /** @var class-string<WorkflowRun> $model */
        $model = config('workflows.v2.run_model', WorkflowRun::class);

        return $model;
    }

    /**
     * @return list<string>
     */
    private function runIds(): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $value): string => is_scalar($value) ? trim((string) $value) : '',
            (array) $this->option('run-id'),
        ))));
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * @return array{status: 'already_typed'|'not_failure'|'updated'|'unresolved', payload?: array<string, mixed>, exception_class?: string|null, exception_type?: string|null, reason?: string}
     */
    private function normalizePayload(WorkflowHistoryEvent $event): array
    {
        $payload = is_array($event->payload) ? $event->payload : [];
        $exception = is_array($payload['exception'] ?? null) ? $payload['exception'] : null;
        $existingType = $this->nonEmptyString($payload['exception_type'] ?? null)
            ?? $this->nonEmptyString($exception['type'] ?? null);
        $recordedClass = $this->exceptionClass($payload);

        if (
            $event->event_type === HistoryEventType::UpdateCompleted
            && $this->nonEmptyString($payload['failure_id'] ?? null) === null
            && $recordedClass === null
            && $existingType === null
        ) {
            return [
                'status' => 'not_failure',
            ];
        }

        if ($existingType !== null) {
            $updatedPayload = $this->applyExceptionType($payload, $existingType);

            if ($updatedPayload === $payload) {
                return [
                    'status' => 'already_typed',
                    'exception_class' => $this->exceptionClass($payload),
                    'exception_type' => $existingType,
                ];
            }

            return [
                'status' => 'updated',
                'payload' => $updatedPayload,
                'exception_class' => $this->exceptionClass($payload),
                'exception_type' => $existingType,
            ];
        }

        if ($recordedClass === null) {
            return [
                'status' => 'unresolved',
                'exception_class' => null,
                'exception_type' => null,
                'reason' => 'missing_exception_class',
            ];
        }

        $classResolution = $this->resolveRecordedClass($recordedClass);

        if ($classResolution['class'] === null) {
            return [
                'status' => 'unresolved',
                'exception_class' => $recordedClass,
                'exception_type' => null,
                'reason' => $classResolution['reason'],
            ];
        }

        $typeResolution = $this->durableTypeForThrowableClass($classResolution['class']);

        if ($typeResolution['type'] === null) {
            return [
                'status' => 'unresolved',
                'exception_class' => $recordedClass,
                'exception_type' => null,
                'reason' => $typeResolution['reason'],
            ];
        }

        return [
            'status' => 'updated',
            'payload' => $this->applyExceptionType($payload, $typeResolution['type']),
            'exception_class' => $recordedClass,
            'exception_type' => $typeResolution['type'],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function exceptionClass(array $payload): ?string
    {
        $exception = is_array($payload['exception'] ?? null) ? $payload['exception'] : [];

        return $this->nonEmptyString($exception['class'] ?? null)
            ?? $this->nonEmptyString($payload['exception_class'] ?? null);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function applyExceptionType(array $payload, string $exceptionType): array
    {
        $payload['exception_type'] = $exceptionType;

        if (is_array($payload['exception'] ?? null)) {
            $payload['exception']['type'] = $exceptionType;
        }

        return $payload;
    }

    /**
     * @return array{class: class-string<Throwable>|null, reason: string|null}
     */
    private function resolveRecordedClass(string $recordedClass): array
    {
        $aliases = config('workflows.v2.types.exception_class_aliases');

        if (is_array($aliases) && array_key_exists($recordedClass, $aliases)) {
            $mappedClass = $aliases[$recordedClass];

            if (! is_string($mappedClass) || $mappedClass === '') {
                return [
                    'class' => null,
                    'reason' => 'misconfigured_exception_class_alias',
                ];
            }

            if (! $this->isThrowableClass($mappedClass)) {
                return [
                    'class' => null,
                    'reason' => 'misconfigured_exception_class_alias',
                ];
            }

            return [
                'class' => $mappedClass,
                'reason' => null,
            ];
        }

        if ($this->isThrowableClass($recordedClass)) {
            return [
                'class' => $recordedClass,
                'reason' => null,
            ];
        }

        return [
            'class' => null,
            'reason' => 'missing_exception_class_alias',
        ];
    }

    /**
     * @param class-string<Throwable> $class
     *
     * @return array{type: string|null, reason: string|null}
     */
    private function durableTypeForThrowableClass(string $class): array
    {
        $types = config('workflows.v2.types.exceptions');

        if (! is_array($types)) {
            return [
                'type' => null,
                'reason' => 'missing_exception_type',
            ];
        }

        $matches = [];

        foreach ($types as $type => $mappedClass) {
            if (! is_string($type) || $type === '' || $mappedClass !== $class) {
                continue;
            }

            $matches[] = $type;
        }

        if (count($matches) === 1) {
            return [
                'type' => $matches[0],
                'reason' => null,
            ];
        }

        return [
            'type' => null,
            'reason' => $matches === [] ? 'missing_exception_type' : 'ambiguous_exception_type',
        ];
    }

    private function isThrowableClass(string $class): bool
    {
        return class_exists($class) && is_subclass_of($class, Throwable::class);
    }

    private function nonEmptyString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * @param array{exception_class?: string|null, exception_type?: string|null, reason?: string} $result
     *
     * @return array{event_id: string, run_id: string, sequence: int, event_type: string, exception_class: string|null, reason: string}
     */
    private function unresolvedReport(WorkflowHistoryEvent $event, array $result): array
    {
        return [
            'event_id' => $event->id,
            'run_id' => $event->workflow_run_id,
            'sequence' => $event->sequence,
            'event_type' => $event->event_type instanceof HistoryEventType
                ? $event->event_type->value
                : (string) $event->event_type,
            'exception_class' => $this->nonEmptyString($result['exception_class'] ?? null),
            'reason' => $this->nonEmptyString($result['reason'] ?? null) ?? 'unknown',
        ];
    }

    /**
     * @param array{
     *     dry_run: bool,
     *     strict: bool,
     *     failure_events_matched: int,
     *     failure_events_scanned: int,
     *     failure_events_skipped: int,
     *     failure_events_already_typed: int,
     *     failure_events_updated: int,
     *     failure_events_would_update: int,
     *     unresolved: list<array{event_id: string, run_id: string, sequence: int, event_type: string, exception_class: string|null, reason: string}>,
     *     failures: list<array{event_id: string, run_id: string, sequence: int, message: string}>
     * } $report
     */
    private function renderReport(array $report): void
    {
        if ((bool) $this->option('json')) {
            try {
                $this->line(json_encode($report, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            } catch (JsonException $exception) {
                $this->error($exception->getMessage());
            }

            return;
        }

        if ($report['dry_run']) {
            $this->info(sprintf(
                'Would backfill %d failure history event(s).',
                $report['failure_events_would_update'],
            ));
        } else {
            $this->info(sprintf('Backfilled %d failure history event(s).', $report['failure_events_updated']));
        }

        if ($report['failure_events_already_typed'] > 0) {
            $this->info(sprintf(
                'Skipped %d already-typed failure history event(s).',
                $report['failure_events_already_typed'],
            ));
        }

        if ($report['unresolved'] !== []) {
            $this->warn(sprintf('Unresolved %d failure history event(s).', count($report['unresolved'])));
        }

        foreach ($report['unresolved'] as $unresolved) {
            $this->warn(sprintf(
                'Unable to backfill event [%s] on run [%s] sequence [%d]: %s.',
                $unresolved['event_id'],
                $unresolved['run_id'],
                $unresolved['sequence'],
                $unresolved['reason'],
            ));
        }

        foreach ($report['failures'] as $failure) {
            $this->error(sprintf(
                'Failed to backfill event [%s] on run [%s] sequence [%d]: %s',
                $failure['event_id'],
                $failure['run_id'],
                $failure['sequence'],
                $failure['message'],
            ));
        }
    }
}
