<?php

declare(strict_types=1);

namespace Workflow\V2\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\CommandOutcome;
use Workflow\V2\Enums\CommandStatus;
use Workflow\V2\Enums\CommandType;
use Workflow\V2\Support\CommandSequence;
use Workflow\V2\Support\ConfiguredV2Models;

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

        $targetScope = is_string($attributes['target_scope'] ?? null)
            ? $attributes['target_scope']
            : 'instance';

        if ($targetScope === 'run' && $run !== null) {
            $attributes['requested_workflow_run_id'] ??= $run->id;
        }

        if ($run !== null) {
            $attributes['resolved_workflow_run_id'] ??= $run->id;
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
        return $this->belongsTo(
            ConfiguredV2Models::resolve('instance_model', WorkflowInstance::class),
            'workflow_instance_id',
        );
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(ConfiguredV2Models::resolve('run_model', WorkflowRun::class), 'workflow_run_id');
    }

    public function historyEvents(): HasMany
    {
        return $this->hasMany(
            ConfiguredV2Models::resolve('history_event_model', WorkflowHistoryEvent::class),
            'workflow_command_id',
        );
    }

    public function updateRecord(): HasOne
    {
        return $this->hasOne(
            ConfiguredV2Models::resolve('update_model', WorkflowUpdate::class),
            'workflow_command_id',
        );
    }

    public function signalRecord(): HasOne
    {
        return $this->hasOne(
            ConfiguredV2Models::resolve('signal_model', WorkflowSignal::class),
            'workflow_command_id',
        );
    }

    /**
     * @return array<int, mixed>
     */
    public function payloadArguments(): array
    {
        $payload = $this->payloadData();

        if (! is_array($payload)) {
            return [];
        }

        if (array_key_exists('arguments', $payload) && is_array($payload['arguments'])) {
            /** @var array<int|string, mixed> $arguments */
            $arguments = $payload['arguments'];

            return array_is_list($arguments)
                ? $arguments
                : array_values($arguments);
        }

        /** @var array<int, mixed> $arguments */
        $arguments = $payload;

        return $arguments;
    }

    public function targetName(): ?string
    {
        $payload = $this->payloadData();

        if (! is_array($payload)) {
            return null;
        }

        $targetName = $payload['name'] ?? null;

        return is_string($targetName) && $targetName !== ''
            ? $targetName
            : null;
    }

    /**
     * @return array<string, list<string>>
     */
    public function validationErrors(): array
    {
        $payload = $this->payloadData();
        $errors = is_array($payload) ? ($payload['validation_errors'] ?? null) : null;

        if (! is_array($errors)) {
            return [];
        }

        $normalized = [];

        foreach ($errors as $field => $messages) {
            if (! is_string($field) || ! is_array($messages)) {
                continue;
            }

            $normalizedMessages = array_values(array_filter(
                $messages,
                static fn (mixed $message): bool => is_string($message) && $message !== '',
            ));

            if ($normalizedMessages === []) {
                continue;
            }

            $normalized[$field] = $normalizedMessages;
        }

        return $normalized;
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
        $intake = $this->commandContext()['intake'] ?? null;

        $publicWorkflow = is_array($workflow)
            ? array_filter([
                'parent_instance_id' => is_string($workflow['parent_instance_id'] ?? null)
                    ? $workflow['parent_instance_id']
                    : null,
                'parent_run_id' => is_string($workflow['parent_run_id'] ?? null)
                    ? $workflow['parent_run_id']
                    : null,
                'sequence' => is_int($workflow['sequence'] ?? null)
                    ? $workflow['sequence']
                    : null,
                'child_call_id' => is_string($workflow['child_call_id'] ?? null)
                    ? $workflow['child_call_id']
                    : null,
            ], static fn (mixed $value): bool => $value !== null)
            : [];

        $publicIntake = is_array($intake)
            ? array_filter([
                'mode' => is_string($intake['mode'] ?? null)
                    ? $intake['mode']
                    : null,
                'group_id' => is_string($intake['group_id'] ?? null)
                    ? $intake['group_id']
                    : null,
            ], static fn (mixed $value): bool => $value !== null && $value !== '')
            : [];

        return array_filter([
            'workflow' => $publicWorkflow === [] ? null : $publicWorkflow,
            'intake' => $publicIntake === [] ? null : $publicIntake,
        ], static fn (mixed $value): bool => $value !== null);
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

    public function requestedRunId(): ?string
    {
        if (is_string($this->requested_workflow_run_id ?? null) && $this->requested_workflow_run_id !== '') {
            return $this->requested_workflow_run_id;
        }

        if ($this->target_scope !== 'run') {
            return null;
        }

        return is_string($this->workflow_run_id ?? null) && $this->workflow_run_id !== ''
            ? $this->workflow_run_id
            : null;
    }

    public function resolvedRunId(): ?string
    {
        if (is_string($this->resolved_workflow_run_id ?? null) && $this->resolved_workflow_run_id !== '') {
            return $this->resolved_workflow_run_id;
        }

        if ($this->target_scope === 'run' && $this->rejection_reason === 'selected_run_not_current') {
            return null;
        }

        return is_string($this->workflow_run_id ?? null) && $this->workflow_run_id !== ''
            ? $this->workflow_run_id
            : null;
    }

    public function correlationId(): ?string
    {
        $request = $this->commandContext()['request'] ?? null;

        return is_array($request) && is_string($request['correlation_id'] ?? null)
            ? $request['correlation_id']
            : null;
    }

    public function intakeMode(): ?string
    {
        $intake = $this->commandContext()['intake'] ?? null;

        return is_array($intake) && is_string($intake['mode'] ?? null)
            ? $intake['mode']
            : null;
    }

    public function intakeGroupId(): ?string
    {
        $intake = $this->commandContext()['intake'] ?? null;

        return is_array($intake) && is_string($intake['group_id'] ?? null)
            ? $intake['group_id']
            : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function payloadData(): ?array
    {
        if ($this->payload === null) {
            return null;
        }

        $payload = Serializer::unserialize($this->payload);

        return is_array($payload)
            ? $payload
            : null;
    }
}
