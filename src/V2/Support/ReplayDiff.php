<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Throwable;
use Workflow\V2\Exceptions\HistoryEventShapeMismatchException;
use Workflow\V2\Models\WorkflowRun;

/**
 * Compare a stored history bundle to what the current workflow code would
 * produce when replayed against it.
 *
 * The diagnostic output is the operator/CI-facing artifact for replay
 * verification: it pinpoints the workflow sequence at which new code
 * diverges from the recorded history, names the recorded event types,
 * names the workflow step shape the new code yielded, and surfaces the
 * resulting failure message verbatim. The same shape is consumed by the
 * `workflow:v2:replay-verify` command and by sdk-python's offline replay
 * driver via the published JSON contract.
 *
 * This class never mutates the bundle and never touches the database;
 * pair it with {@see WorkflowReplayer::replayExport()} for the offline
 * replay path.
 *
 * @api Stable v2 contract surface for replay-diff diagnostics.
 */
final class ReplayDiff
{
    public const REPORT_SCHEMA = 'durable-workflow.v2.replay-diff';

    public const REPORT_SCHEMA_VERSION = 1;

    public const STATUS_REPLAYED = 'replayed';

    public const STATUS_DRIFTED = 'drifted';

    public const STATUS_FAILED = 'failed';

    public const REASON_NONE = 'none';

    public const REASON_SHAPE_MISMATCH = 'shape_mismatch';

    public const REASON_REPLAY_ERROR = 'replay_error';

    public const REASON_BUNDLE_INVALID = 'bundle_invalid';

    public function __construct(
        private readonly WorkflowReplayer $replayer = new WorkflowReplayer(),
    ) {
    }

    /**
     * Replay the supplied bundle against the workflow class registered for
     * its `workflow_class` field and produce a structured diff report.
     *
     * @param array<string, mixed> $bundle
     *
     * @return array<string, mixed>
     */
    public function diffExport(array $bundle): array
    {
        $context = self::summarizeBundle($bundle);

        try {
            $run = $this->replayer->runFromHistoryExport($bundle);
        } catch (Throwable $exception) {
            return $this->report(
                status: self::STATUS_FAILED,
                reason: self::REASON_BUNDLE_INVALID,
                context: $context,
                error: self::describeException($exception),
            );
        }

        return $this->diffRun($run, $context);
    }

    /**
     * Replay an already-hydrated WorkflowRun and produce a diff report.
     * Useful inside server-side observers that already hold the run.
     *
     * @param array<string, mixed>|null $context Optional bundle context to embed in the report.
     *
     * @return array<string, mixed>
     */
    public function diffRun(WorkflowRun $run, ?array $context = null): array
    {
        $context ??= self::summarizeRun($run);

        try {
            $state = $this->replayer->replay($run);
        } catch (HistoryEventShapeMismatchException $mismatch) {
            return $this->report(
                status: self::STATUS_DRIFTED,
                reason: self::REASON_SHAPE_MISMATCH,
                context: $context,
                divergence: [
                    'workflow_sequence' => $mismatch->workflowSequence,
                    'expected_shape' => $mismatch->expectedHistoryShape,
                    'recorded_event_types' => $mismatch->recordedEventTypes,
                    'message' => $mismatch->getMessage(),
                ],
            );
        } catch (Throwable $exception) {
            return $this->report(
                status: self::STATUS_FAILED,
                reason: self::REASON_REPLAY_ERROR,
                context: $context,
                error: self::describeException($exception),
            );
        }

        return $this->report(
            status: self::STATUS_REPLAYED,
            reason: self::REASON_NONE,
            context: $context,
            replay: [
                'sequence' => $state->sequence,
                'current_call' => $state->current === null ? null : $state->current::class,
            ],
        );
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed>|null $divergence
     * @param array<string, mixed>|null $replay
     * @param array<string, mixed>|null $error
     *
     * @return array<string, mixed>
     */
    private function report(
        string $status,
        string $reason,
        array $context,
        ?array $divergence = null,
        ?array $replay = null,
        ?array $error = null,
    ): array {
        return [
            'schema' => self::REPORT_SCHEMA,
            'schema_version' => self::REPORT_SCHEMA_VERSION,
            'status' => $status,
            'reason' => $reason,
            'workflow' => $context,
            'divergence' => $divergence,
            'replay' => $replay,
            'error' => $error,
        ];
    }

    /**
     * @param array<string, mixed> $bundle
     *
     * @return array<string, mixed>
     */
    private static function summarizeBundle(array $bundle): array
    {
        $workflow = is_array($bundle['workflow'] ?? null) ? $bundle['workflow'] : [];
        $events = is_array($bundle['history_events'] ?? null) ? $bundle['history_events'] : [];

        return [
            'workflow_run_id' => self::stringValue($workflow['run_id'] ?? null),
            'workflow_instance_id' => self::stringValue($workflow['instance_id'] ?? null),
            'workflow_type' => self::stringValue($workflow['workflow_type'] ?? null),
            'workflow_class' => self::stringValue($workflow['workflow_class'] ?? null),
            'compatibility' => self::stringValue($workflow['compatibility'] ?? null),
            'history_event_count' => count($events),
            'last_history_sequence' => self::intValue($workflow['last_history_sequence'] ?? null),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function summarizeRun(WorkflowRun $run): array
    {
        return [
            'workflow_run_id' => $run->id,
            'workflow_instance_id' => $run->workflow_instance_id,
            'workflow_type' => $run->workflow_type,
            'workflow_class' => $run->workflow_class,
            'compatibility' => $run->compatibility,
            'history_event_count' => $run->relationLoaded('historyEvents') ? $run->historyEvents->count() : null,
            'last_history_sequence' => $run->last_history_sequence,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function describeException(Throwable $exception): array
    {
        return [
            'class' => $exception::class,
            'message' => $exception->getMessage(),
        ];
    }

    private static function stringValue(mixed $value): ?string
    {
        return is_string($value) && $value !== ''
            ? $value
            : null;
    }

    private static function intValue(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && is_numeric($value) && (string) (int) $value === $value) {
            return (int) $value;
        }

        return null;
    }
}
