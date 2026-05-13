<?php

declare(strict_types=1);

namespace Workflow\V2\Worker;

use Workflow\Serializers\Serializer;
use Workflow\V2\Contracts\YieldedCommand;
use Workflow\V2\Exceptions\UnsupportedWorkflowYieldException;
use Workflow\V2\Support\ActivityCall;
use Workflow\V2\Support\ActivityOptions;
use Workflow\V2\Support\ChildWorkflowCall;
use Workflow\V2\Support\ChildWorkflowOptions;
use Workflow\V2\Support\ContinueAsNewCall;
use Workflow\V2\Support\SideEffectCall;
use Workflow\V2\Support\TimerCall;
use Workflow\V2\Support\UpsertSearchAttributesCall;
use Workflow\V2\Support\VersionCall;
use Workflow\V2\Support\WorkerSessionOptions;

/**
 * Outcome of a single worker-protocol workflow Fiber step.
 *
 * @api Stable v2 worker protocol API.
 */
final class WorkflowStep
{
    /**
     * @param list<array<string, mixed>> $commands
     */
    private function __construct(
        public readonly bool $completed,
        public readonly mixed $result,
        public readonly ?ActivityCall $activity,
        public readonly ?YieldedCommand $yielded,
        public readonly ?array $command,
        public readonly array $commands,
    ) {
    }

    public static function completed(mixed $result, string $payloadCodec = 'avro'): self
    {
        $command = [
            'type' => 'complete_workflow',
            'result' => self::serializePayload($result, $payloadCodec),
            'payload_codec' => $payloadCodec,
        ];

        return new self(true, $result, null, null, $command, [$command]);
    }

    public static function scheduleActivity(ActivityCall $call, string $payloadCodec = 'avro'): self
    {
        return self::yielded($call, $payloadCodec);
    }

    public static function yielded(YieldedCommand $yielded, string $payloadCodec = 'avro'): self
    {
        $commands = [self::commandForYielded($yielded, $payloadCodec)];

        return new self(
            false,
            null,
            $yielded instanceof ActivityCall ? $yielded : null,
            $yielded,
            $commands[0],
            $commands,
        );
    }

    public static function waiting(YieldedCommand $yielded): self
    {
        return new self(
            false,
            null,
            $yielded instanceof ActivityCall ? $yielded : null,
            $yielded,
            null,
            [],
        );
    }

    public static function recordSideEffect(SideEffectCall $call, mixed $result, string $payloadCodec = 'avro'): self
    {
        $command = [
            'type' => 'record_side_effect',
            'result' => self::serializePayload($result, $payloadCodec),
        ];

        return new self(false, null, null, $call, $command, [$command]);
    }

    /**
     * @param list<array<string, mixed>> $commands
     */
    public function withPrependedCommands(array $commands): self
    {
        if ($commands === []) {
            return $this;
        }

        return new self(
            $this->completed,
            $this->result,
            $this->activity,
            $this->yielded,
            $this->command,
            [...$commands, ...$this->commands],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function commandForYielded(YieldedCommand $yielded, string $payloadCodec): array
    {
        return match (true) {
            $yielded instanceof ActivityCall => self::activityCommand($yielded, $payloadCodec),
            $yielded instanceof TimerCall => [
                'type' => 'start_timer',
                'delay_seconds' => $yielded->seconds,
            ],
            $yielded instanceof ChildWorkflowCall => self::childWorkflowCommand($yielded, $payloadCodec),
            $yielded instanceof SideEffectCall => throw new UnsupportedWorkflowYieldException(
                'Worker protocol side effects require WorkflowFiberRunner history resolution before command emission.',
            ),
            $yielded instanceof VersionCall => [
                'type' => 'record_version_marker',
                'change_id' => $yielded->changeId,
                'version' => $yielded->maxSupported,
                'min_supported' => $yielded->minSupported,
                'max_supported' => $yielded->maxSupported,
            ],
            $yielded instanceof UpsertSearchAttributesCall => [
                'type' => 'upsert_search_attributes',
                'attributes' => $yielded->attributes,
            ],
            $yielded instanceof ContinueAsNewCall => [
                'type' => 'continue_as_new',
                'arguments' => self::serializePayload($yielded->arguments, $payloadCodec),
                'payload_codec' => $payloadCodec,
            ],
            default => throw new UnsupportedWorkflowYieldException(sprintf(
                'Worker protocol runner received an unsupported workflow suspension of type %s.',
                get_debug_type($yielded),
            )),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function activityCommand(ActivityCall $call, string $payloadCodec): array
    {
        return array_filter([
            'type' => 'schedule_activity',
            'activity_type' => $call->activity,
            'arguments' => self::serializePayload($call->arguments, $payloadCodec),
            'payload_codec' => $payloadCodec,
            ...self::activityOptions($call->options),
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @return array<string, mixed>
     */
    private static function childWorkflowCommand(ChildWorkflowCall $call, string $payloadCodec): array
    {
        return array_filter([
            'type' => 'start_child_workflow',
            'workflow_type' => $call->workflow,
            'arguments' => self::serializePayload($call->arguments, $payloadCodec),
            'payload_codec' => $payloadCodec,
            ...self::childWorkflowOptions($call->options),
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @return array<string, mixed>
     */
    private static function activityOptions(?ActivityOptions $options): array
    {
        if ($options === null) {
            return [];
        }

        $retryPolicy = self::retryPolicy(
            $options->maxAttempts,
            $options->backoff,
            $options->nonRetryableErrorTypes,
        );

        return array_filter([
            'connection' => $options->connection,
            'queue' => $options->queue,
            'retry_policy' => $retryPolicy === [] ? null : $retryPolicy,
            'start_to_close_timeout' => $options->startToCloseTimeout,
            'schedule_to_start_timeout' => $options->scheduleToStartTimeout,
            'schedule_to_close_timeout' => $options->scheduleToCloseTimeout,
            'heartbeat_timeout' => $options->heartbeatTimeout,
            'worker_session' => self::workerSession($options->workerSession),
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @return array<string, mixed>
     */
    private static function childWorkflowOptions(?ChildWorkflowOptions $options): array
    {
        if ($options === null) {
            return [];
        }

        return array_filter([
            'parent_close_policy' => $options->parentClosePolicy->value,
            'connection' => $options->connection,
            'queue' => $options->queue,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param list<string> $nonRetryableErrorTypes
     * @return array<string, mixed>
     */
    private static function retryPolicy(
        ?int $maxAttempts,
        array|int|null $backoff,
        array $nonRetryableErrorTypes,
    ): array {
        return array_filter([
            'max_attempts' => $maxAttempts,
            'backoff_seconds' => is_int($backoff) ? [$backoff] : $backoff,
            'non_retryable_error_types' => $nonRetryableErrorTypes === [] ? null : $nonRetryableErrorTypes,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function workerSession(?WorkerSessionOptions $options): ?array
    {
        if ($options === null) {
            return null;
        }

        return array_filter($options->toSnapshot(), static fn (mixed $value): bool => $value !== null);
    }

    private static function serializePayload(mixed $payload, string $payloadCodec): string
    {
        return Serializer::serializeWithCodec($payloadCodec, $payload);
    }
}
