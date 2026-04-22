<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Throwable;
use TypeError;
use ValueError;
use Workflow\Exceptions\NonRetryableExceptionContract;
use Workflow\Serializers\CodecRegistry;
use Workflow\Serializers\Serializer;
use Workflow\V2\Contracts\ExternalPayloadStorageDriver;

final class InvocableActivityHandler
{
    public const RESULT_SCHEMA = 'durable-workflow.v2.external-task-result';

    public const RESULT_VERSION = 1;

    public const RESULT_CONTENT_TYPE = 'application/vnd.durable-workflow.result+json';

    /**
     * @param  array<string, callable>  $handlers
     */
    public function __construct(
        private readonly array $handlers,
        private readonly string $carrier = 'php-invocable',
        private readonly string $resultCodec = 'avro',
        private readonly ?ExternalPayloadStorageDriver $externalStorage = null,
    ) {
        try {
            CodecRegistry::canonicalize($resultCodec);
        } catch (InvalidArgumentException $e) {
            throw new InvalidArgumentException(sprintf(
                'unsupported invocable result codec %s',
                var_export($resultCodec, true)
            ), previous: $e);
        }
    }

    /**
     * @param  array<string, mixed>  $envelope
     * @return array<string, mixed>
     */
    public function handle(array $envelope): array
    {
        $started = microtime(true);
        $taskInput = ExternalTaskInput::parse($envelope);

        if (! $taskInput->isActivityTask()) {
            return $this->failure(
                $taskInput,
                $started,
                kind: 'application',
                classification: 'application_error',
                message: 'invocable activity handlers only accept activity_task inputs',
                type: 'UnsupportedExternalTaskKind',
                retryable: false,
            );
        }

        $deadlineFailure = $this->expiredDeadlineFailure($taskInput, $started);
        if ($deadlineFailure !== null) {
            return $deadlineFailure;
        }

        $handlerName = $taskInput->handler() ?? '';
        $handler = $this->handlers[$handlerName] ?? null;

        if (! is_callable($handler)) {
            return $this->failure(
                $taskInput,
                $started,
                kind: 'application',
                classification: 'application_error',
                message: sprintf(
                    'no invocable activity handler registered for %s',
                    var_export($taskInput->handler(), true)
                ),
                type: 'UnknownActivityHandler',
                retryable: false,
            );
        }

        try {
            $arguments = $this->decodeArguments($taskInput);
        } catch (Throwable $e) {
            return $this->failure(
                $taskInput,
                $started,
                kind: 'decode_failure',
                classification: 'decode_failure',
                message: 'Carrier could not decode or encode the activity payload: ' . $e->getMessage(),
                type: $e::class,
                retryable: false,
                stackTrace: $e->getTraceAsString(),
                details: [
                    'codec' => $this->inputCodec($taskInput),
                ],
            );
        }

        try {
            $result = $handler(...$arguments);
        } catch (ExternalTaskInputException|TypeError|ValueError $e) {
            return $this->failure(
                $taskInput,
                $started,
                kind: 'decode_failure',
                classification: 'decode_failure',
                message: 'Carrier could not decode or encode the activity payload: ' . $e->getMessage(),
                type: $e::class,
                retryable: false,
                stackTrace: $e->getTraceAsString(),
                details: [
                    'codec' => $this->inputCodec($taskInput),
                ],
            );
        } catch (Throwable $e) {
            return $this->failure(
                $taskInput,
                $started,
                kind: 'application',
                classification: 'application_error',
                message: $e->getMessage(),
                type: $e::class,
                retryable: ! $e instanceof NonRetryableExceptionContract,
                stackTrace: $e->getTraceAsString(),
            );
        }

        $deadlineFailure = $this->expiredDeadlineFailure($taskInput, $started, completed: true);
        if ($deadlineFailure !== null) {
            return $deadlineFailure;
        }

        try {
            return $this->success($taskInput, $started, $result);
        } catch (Throwable $e) {
            return $this->failure(
                $taskInput,
                $started,
                kind: 'decode_failure',
                classification: 'decode_failure',
                message: 'Carrier could not decode or encode the activity payload: ' . $e->getMessage(),
                type: $e::class,
                retryable: false,
                stackTrace: $e->getTraceAsString(),
                details: [
                    'codec' => $this->resultCodec,
                ],
            );
        }
    }

    /**
     * @return list<mixed>
     */
    private function decodeArguments(ExternalTaskInput $taskInput): array
    {
        $rawArguments = $taskInput->argumentsPayload();

        if ($rawArguments === null) {
            return [];
        }

        $decoded = PayloadEnvelopeResolver::resolveToArray(
            $rawArguments,
            field: 'payloads.arguments',
            externalStorage: $this->externalStorage,
        );

        return array_values($decoded);
    }

    private function inputCodec(ExternalTaskInput $taskInput): string
    {
        $rawArguments = $taskInput->argumentsPayload();
        $codec = $rawArguments['codec'] ?? null;

        return is_string($codec) && $codec !== '' ? $codec : CodecRegistry::defaultCodec();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function expiredDeadlineFailure(
        ExternalTaskInput $taskInput,
        float $started,
        bool $completed = false,
    ): ?array {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        foreach ($taskInput->deadlineCandidates() as $name => $expiresAt) {
            try {
                $deadline = $this->parseDeadline($expiresAt);
            } catch (Throwable $e) {
                return $this->failure(
                    $taskInput,
                    $started,
                    kind: 'decode_failure',
                    classification: 'decode_failure',
                    message: sprintf('Carrier could not parse activity deadline %s: %s', $name, $e->getMessage()),
                    type: $e::class,
                    retryable: false,
                    stackTrace: $e->getTraceAsString(),
                    details: [
                        'deadline' => $name,
                        'expires_at' => $expiresAt,
                    ],
                );
            }

            if ($deadline <= $now) {
                $suffix = $completed ? 'completed after' : 'received after';

                return $this->failure(
                    $taskInput,
                    $started,
                    kind: 'timeout',
                    classification: 'deadline_exceeded',
                    message: sprintf('Invocable activity task %s %s.', $suffix, $name),
                    type: 'ExternalTaskDeadlineExceeded',
                    retryable: true,
                    timeoutType: 'deadline_exceeded',
                    details: [
                        'deadline' => $name,
                        'expires_at' => $expiresAt,
                    ],
                );
            }
        }

        return null;
    }

    private function parseDeadline(string $value): DateTimeImmutable
    {
        $parsed = new DateTimeImmutable($value);

        return $parsed->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * @return array<string, mixed>
     */
    private function success(ExternalTaskInput $taskInput, float $started, mixed $result): array
    {
        return [
            'schema' => self::RESULT_SCHEMA,
            'version' => self::RESULT_VERSION,
            'outcome' => [
                'status' => 'succeeded',
                'recorded' => true,
            ],
            'task' => $this->taskIdentity($taskInput),
            'result' => [
                'payload' => $this->payloadEnvelope($result),
                'metadata' => [
                    'content_type' => self::RESULT_CONTENT_TYPE,
                ],
            ],
            'metadata' => $this->metadata($taskInput, $started),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $details
     * @return array<string, mixed>
     */
    private function failure(
        ExternalTaskInput $taskInput,
        float $started,
        string $kind,
        string $classification,
        string $message,
        string $type,
        bool $retryable,
        ?string $stackTrace = null,
        ?string $timeoutType = null,
        bool $cancelled = false,
        ?array $details = null,
    ): array {
        return [
            'schema' => self::RESULT_SCHEMA,
            'version' => self::RESULT_VERSION,
            'outcome' => [
                'status' => 'failed',
                'retryable' => $retryable,
                'recorded' => true,
            ],
            'task' => $this->taskIdentity($taskInput),
            'failure' => [
                'kind' => $kind,
                'classification' => $classification,
                'message' => $message,
                'type' => $type,
                'stack_trace' => $stackTrace,
                'timeout_type' => $timeoutType,
                'cancelled' => $cancelled,
                'details' => $details,
            ],
            'metadata' => $this->metadata($taskInput, $started),
        ];
    }

    /**
     * @return array{id: string, kind: string, attempt: int, idempotency_key: string}
     */
    private function taskIdentity(ExternalTaskInput $taskInput): array
    {
        return [
            'id' => $taskInput->taskId(),
            'kind' => $taskInput->kind,
            'attempt' => $taskInput->attempt(),
            'idempotency_key' => $taskInput->idempotencyKey(),
        ];
    }

    /**
     * @return array{handler: string|null, carrier: string, duration_ms: int}
     */
    private function metadata(ExternalTaskInput $taskInput, float $started): array
    {
        return [
            'handler' => $taskInput->handler(),
            'carrier' => $this->carrier,
            'duration_ms' => max(0, (int) floor((microtime(true) - $started) * 1000)),
        ];
    }

    /**
     * @return array{codec: string, blob: string}
     */
    private function payloadEnvelope(mixed $value): array
    {
        $codec = CodecRegistry::canonicalize($this->resultCodec);

        return [
            'codec' => $codec,
            'blob' => Serializer::serializeWithCodec($codec, $value),
        ];
    }
}
