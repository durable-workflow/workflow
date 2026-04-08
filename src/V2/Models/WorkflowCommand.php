<?php

declare(strict_types=1);

namespace Workflow\V2\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\CommandOutcome;
use Workflow\V2\Enums\CommandStatus;
use Workflow\V2\Enums\CommandType;
use Workflow\V2\Support\CommandSequence;

class WorkflowCommand extends Model
{
    use HasUlids;

    public $incrementing = false;

    protected $table = 'workflow_commands';

    protected $guarded = [];

    protected $keyType = 'string';

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $casts = [
        'command_type' => CommandType::class,
        'status' => CommandStatus::class,
        'outcome' => CommandOutcome::class,
        'command_sequence' => 'integer',
        'context' => 'array',
        'accepted_at' => 'datetime',
        'applied_at' => 'datetime',
        'rejected_at' => 'datetime',
    ];

    /**
     * @param array<string, mixed> $attributes
     */
    public static function record(WorkflowInstance $instance, ?WorkflowRun $run, array $attributes): self
    {
        if ($run !== null) {
            $attributes['command_sequence'] ??= CommandSequence::reserveNext($run);
        }

        $attributes['workflow_instance_id'] ??= $instance->id;
        $attributes['workflow_run_id'] ??= $run?->id;
        $attributes['workflow_class'] ??= $run?->workflow_class ?? $instance->workflow_class;
        $attributes['workflow_type'] ??= $run?->workflow_type ?? $instance->workflow_type;

        /** @var self $command */
        $command = static::query()->create($attributes);

        return $command;
    }

    public function instance(): BelongsTo
    {
        return $this->belongsTo(WorkflowInstance::class, 'workflow_instance_id');
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(WorkflowRun::class, 'workflow_run_id');
    }

    public function historyEvents(): HasMany
    {
        return $this->hasMany(WorkflowHistoryEvent::class, 'workflow_command_id');
    }

    /**
     * @return array<int, mixed>
     */
    public function payloadArguments(): array
    {
        if ($this->payload === null) {
            return [];
        }

        $payload = Serializer::unserialize($this->payload);

        if (! is_array($payload)) {
            return [];
        }

        if (array_key_exists('arguments', $payload) && is_array($payload['arguments'])) {
            /** @var array<int, mixed> $arguments */
            $arguments = $payload['arguments'];

            return $arguments;
        }

        /** @var array<int, mixed> $arguments */
        $arguments = $payload;

        return $arguments;
    }

    public function targetName(): ?string
    {
        if ($this->payload === null) {
            return null;
        }

        $payload = Serializer::unserialize($this->payload);

        if (! is_array($payload)) {
            return null;
        }

        $targetName = $payload['name'] ?? null;

        return is_string($targetName) && $targetName !== ''
            ? $targetName
            : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function commandContext(): array
    {
        return is_array($this->context)
            ? $this->context
            : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function publicContext(): array
    {
        $workflow = $this->commandContext()['workflow'] ?? null;

        if (! is_array($workflow)) {
            return [];
        }

        $publicWorkflow = array_filter([
            'parent_instance_id' => is_string($workflow['parent_instance_id'] ?? null)
                ? $workflow['parent_instance_id']
                : null,
            'parent_run_id' => is_string($workflow['parent_run_id'] ?? null)
                ? $workflow['parent_run_id']
                : null,
            'sequence' => is_int($workflow['sequence'] ?? null)
                ? $workflow['sequence']
                : null,
        ], static fn (mixed $value): bool => $value !== null);

        return $publicWorkflow === []
            ? []
            : ['workflow' => $publicWorkflow];
    }

    public function callerLabel(): ?string
    {
        $caller = $this->commandContext()['caller'] ?? null;

        return is_array($caller) && is_string($caller['label'] ?? null)
            ? $caller['label']
            : null;
    }

    public function authStatus(): ?string
    {
        $auth = $this->commandContext()['auth'] ?? null;

        return is_array($auth) && is_string($auth['status'] ?? null)
            ? $auth['status']
            : null;
    }

    public function authMethod(): ?string
    {
        $auth = $this->commandContext()['auth'] ?? null;

        return is_array($auth) && is_string($auth['method'] ?? null)
            ? $auth['method']
            : null;
    }

    public function requestMethod(): ?string
    {
        $request = $this->commandContext()['request'] ?? null;

        return is_array($request) && is_string($request['method'] ?? null)
            ? $request['method']
            : null;
    }

    public function requestPath(): ?string
    {
        $request = $this->commandContext()['request'] ?? null;

        return is_array($request) && is_string($request['path'] ?? null)
            ? $request['path']
            : null;
    }

    public function requestRouteName(): ?string
    {
        $request = $this->commandContext()['request'] ?? null;

        return is_array($request) && is_string($request['route_name'] ?? null)
            ? $request['route_name']
            : null;
    }

    public function requestFingerprint(): ?string
    {
        $request = $this->commandContext()['request'] ?? null;

        return is_array($request) && is_string($request['fingerprint'] ?? null)
            ? $request['fingerprint']
            : null;
    }

    public function requestId(): ?string
    {
        $request = $this->commandContext()['request'] ?? null;

        return is_array($request) && is_string($request['request_id'] ?? null)
            ? $request['request_id']
            : null;
    }

    public function correlationId(): ?string
    {
        $request = $this->commandContext()['request'] ?? null;

        return is_array($request) && is_string($request['correlation_id'] ?? null)
            ? $request['correlation_id']
            : null;
    }
}
