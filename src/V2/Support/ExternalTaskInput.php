<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

final class ExternalTaskInput
{
    public const SCHEMA = 'durable-workflow.v2.external-task-input';

    public const VERSION = 1;

    public const KIND_ACTIVITY_TASK = 'activity_task';

    public const KIND_WORKFLOW_TASK = 'workflow_task';

    /**
     * @param  array<string, mixed>  $task
     * @param  array<string, mixed>  $workflow
     * @param  array<string, mixed>  $lease
     * @param  array<string, mixed>  $payloads
     * @param  array<string, mixed>  $headers
     * @param  array<string, mixed>|null  $deadlines
     * @param  array<string, mixed>|null  $history
     */
    private function __construct(
        public readonly string $kind,
        public readonly array $task,
        public readonly array $workflow,
        public readonly array $lease,
        public readonly array $payloads,
        public readonly array $headers,
        public readonly ?array $deadlines = null,
        public readonly ?array $history = null,
    ) {
    }

    /**
     * @param  array<string, mixed>  $envelope
     */
    public static function parse(array $envelope): self
    {
        self::requireValue($envelope, 'schema', self::SCHEMA);
        self::requireValue($envelope, 'version', self::VERSION);

        $taskFields = self::requireMap($envelope, 'task');
        $kind = self::requireTaskKind($taskFields, 'kind');
        $task = self::parseTask($taskFields, $kind);
        $workflow = self::parseWorkflow(self::requireMap($envelope, 'workflow'), $kind);
        $lease = self::parseLease(self::requireMap($envelope, 'lease'));
        $payloads = self::parsePayloads(self::requireMap($envelope, 'payloads'));
        $headers = self::requireMap($envelope, 'headers');

        if ($kind === self::KIND_ACTIVITY_TASK) {
            return new self(
                kind: $kind,
                task: $task,
                workflow: $workflow,
                lease: $lease,
                payloads: $payloads,
                headers: $headers,
                deadlines: self::parseDeadlines(self::requireMap($envelope, 'deadlines')),
            );
        }

        return new self(
            kind: $kind,
            task: $task,
            workflow: $workflow,
            lease: $lease,
            payloads: $payloads,
            headers: $headers,
            history: self::parseHistory(self::requireMap($envelope, 'history')),
        );
    }

    public function isActivityTask(): bool
    {
        return $this->kind === self::KIND_ACTIVITY_TASK;
    }

    public function isWorkflowTask(): bool
    {
        return $this->kind === self::KIND_WORKFLOW_TASK;
    }

    public function taskId(): string
    {
        return (string) $this->task['id'];
    }

    public function attempt(): int
    {
        return (int) $this->task['attempt'];
    }

    public function handler(): ?string
    {
        $handler = $this->task['handler'] ?? null;

        return is_string($handler) ? $handler : null;
    }

    public function idempotencyKey(): string
    {
        return (string) $this->task['idempotency_key'];
    }

    public function leaseExpiresAt(): string
    {
        return (string) $this->lease['expires_at'];
    }

    public function argumentsPayload(): ?array
    {
        $arguments = $this->payloads['arguments'] ?? null;

        return is_array($arguments) ? $arguments : null;
    }

    /**
     * @return array<string, string>
     */
    public function deadlineCandidates(): array
    {
        $candidates = [
            'lease.expires_at' => $this->leaseExpiresAt(),
        ];

        foreach ($this->deadlines ?? [] as $name => $expiresAt) {
            if (is_string($name) && is_string($expiresAt)) {
                $candidates['deadlines.' . $name] = $expiresAt;
            }
        }

        return $candidates;
    }

    /**
     * @param  array<string, mixed>  $task
     * @return array<string, mixed>
     */
    private static function parseTask(array $task, string $kind): array
    {
        $attempt = self::requireInt($task, 'attempt');
        if ($attempt < 1) {
            throw new ExternalTaskInputException('External task input task.attempt must be >= 1.');
        }

        $parsed = [
            'id' => self::requireString($task, 'id'),
            'kind' => $kind,
            'attempt' => $attempt,
            'task_queue' => self::requireString($task, 'task_queue'),
            'connection' => self::requireOptionalString($task, 'connection'),
            'idempotency_key' => self::requireString($task, 'idempotency_key'),
        ];

        if ($kind === self::KIND_ACTIVITY_TASK) {
            $parsed['activity_attempt_id'] = self::requireString($task, 'activity_attempt_id');
            $parsed['handler'] = self::requireString($task, 'handler');

            return $parsed;
        }

        $parsed['handler'] = self::requireOptionalString($task, 'handler');
        $parsed['compatibility'] = self::requireOptionalString($task, 'compatibility');

        return $parsed;
    }

    /**
     * @param  array<string, mixed>  $workflow
     * @return array<string, mixed>
     */
    private static function parseWorkflow(array $workflow, string $kind): array
    {
        $parsed = [
            'id' => self::requireString($workflow, 'id'),
            'run_id' => self::requireString($workflow, 'run_id'),
        ];

        if ($kind === self::KIND_WORKFLOW_TASK) {
            $parsed['status'] = self::requireOptionalString($workflow, 'status');
            $parsed['resume'] = self::requireMap($workflow, 'resume');
        }

        return $parsed;
    }

    /**
     * @param  array<string, mixed>  $lease
     * @return array<string, string>
     */
    private static function parseLease(array $lease): array
    {
        return [
            'owner' => self::requireString($lease, 'owner'),
            'expires_at' => self::requireString($lease, 'expires_at'),
            'heartbeat_endpoint' => self::requireString($lease, 'heartbeat_endpoint'),
        ];
    }

    /**
     * @param  array<string, mixed>  $payloads
     * @return array<string, mixed>
     */
    private static function parsePayloads(array $payloads): array
    {
        self::requireNullableMap($payloads, 'arguments');

        return $payloads;
    }

    /**
     * @param  array<string, mixed>  $deadlines
     * @return array<string, mixed>
     */
    private static function parseDeadlines(array $deadlines): array
    {
        foreach (['schedule_to_start', 'start_to_close', 'schedule_to_close', 'heartbeat'] as $key) {
            self::requireOptionalString($deadlines, $key);
        }

        return $deadlines;
    }

    /**
     * @param  array<string, mixed>  $history
     * @return array<string, mixed>
     */
    private static function parseHistory(array $history): array
    {
        self::requireList($history, 'events');
        self::requireInt($history, 'last_sequence');
        self::requireOptionalString($history, 'next_page_token');
        self::requireOptionalString($history, 'encoding');

        return $history;
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>
     */
    private static function requireMap(array $value, string $key): array
    {
        if (! array_key_exists($key, $value)) {
            throw new ExternalTaskInputException(sprintf('External task input is missing required field [%s].', $key));
        }

        $item = $value[$key];
        if (! is_array($item) || array_is_list($item)) {
            throw new ExternalTaskInputException(sprintf('External task input field [%s] must be an object.', $key));
        }

        return $item;
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private static function requireNullableMap(array $value, string $key): ?array
    {
        if (! array_key_exists($key, $value)) {
            throw new ExternalTaskInputException(sprintf('External task input is missing required field [%s].', $key));
        }

        if ($value[$key] === null) {
            return null;
        }

        $item = $value[$key];
        if (! is_array($item) || array_is_list($item)) {
            throw new ExternalTaskInputException(sprintf(
                'External task input field [%s] must be an object or null.',
                $key
            ));
        }

        return $item;
    }

    /**
     * @param  array<string, mixed>  $value
     * @return list<mixed>
     */
    private static function requireList(array $value, string $key): array
    {
        if (! array_key_exists($key, $value)) {
            throw new ExternalTaskInputException(sprintf('External task input is missing required field [%s].', $key));
        }

        $item = $value[$key];
        if (! is_array($item) || ! array_is_list($item)) {
            throw new ExternalTaskInputException(sprintf('External task input field [%s] must be an array.', $key));
        }

        return $item;
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private static function requireTaskKind(array $value, string $key): string
    {
        $kind = self::requireString($value, $key);
        if ($kind === self::KIND_ACTIVITY_TASK || $kind === self::KIND_WORKFLOW_TASK) {
            return $kind;
        }

        throw new ExternalTaskInputException(sprintf('Unsupported external task input kind [%s].', $kind));
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private static function requireString(array $value, string $key): string
    {
        if (! array_key_exists($key, $value)) {
            throw new ExternalTaskInputException(sprintf('External task input is missing required field [%s].', $key));
        }

        $item = $value[$key];
        if (! is_string($item)) {
            throw new ExternalTaskInputException(sprintf('External task input field [%s] must be a string.', $key));
        }

        return $item;
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private static function requireOptionalString(array $value, string $key): ?string
    {
        if (! array_key_exists($key, $value)) {
            throw new ExternalTaskInputException(sprintf('External task input is missing required field [%s].', $key));
        }

        $item = $value[$key];
        if ($item === null) {
            return null;
        }

        if (! is_string($item)) {
            throw new ExternalTaskInputException(sprintf(
                'External task input field [%s] must be a string or null.',
                $key
            ));
        }

        return $item;
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private static function requireInt(array $value, string $key): int
    {
        if (! array_key_exists($key, $value)) {
            throw new ExternalTaskInputException(sprintf('External task input is missing required field [%s].', $key));
        }

        $item = $value[$key];
        if (! is_int($item)) {
            throw new ExternalTaskInputException(sprintf('External task input field [%s] must be an integer.', $key));
        }

        return $item;
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private static function requireValue(array $value, string $key, mixed $expected): void
    {
        if (! array_key_exists($key, $value)) {
            throw new ExternalTaskInputException(sprintf('External task input is missing required field [%s].', $key));
        }

        if ($value[$key] !== $expected) {
            throw new ExternalTaskInputException(sprintf(
                'External task input field [%s] must be %s; got %s.',
                $key,
                var_export($expected, true),
                var_export($value[$key], true),
            ));
        }
    }
}
