<?php

declare(strict_types=1);

namespace Workflow\V2\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Workflow\Serializers\CodecRegistry;
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\CommandOutcome;
use Workflow\V2\Enums\UpdateStatus;
use Workflow\V2\Support\ConfiguredV2Models;
use Workflow\V2\Support\ExternalPayloads;

class WorkflowUpdate extends Model
{
    use HasUlids;

    public $incrementing = false;

    protected $table = 'workflow_updates';

    protected $guarded = [];

    protected $keyType = 'string';

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $casts = [
        'status' => UpdateStatus::class,
        'outcome' => CommandOutcome::class,
        'command_sequence' => 'integer',
        'workflow_sequence' => 'integer',
        'validation_errors' => 'array',
        'accepted_at' => 'datetime',
        'applied_at' => 'datetime',
        'rejected_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

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

    public function command(): BelongsTo
    {
        return $this->belongsTo(
            ConfiguredV2Models::resolve('command_model', WorkflowCommand::class),
            'workflow_command_id',
        );
    }

    public function failure(): BelongsTo
    {
        return $this->belongsTo(
            ConfiguredV2Models::resolve('failure_model', WorkflowFailure::class),
            'failure_id',
        );
    }

    public function serviceCalls(): HasMany
    {
        return $this->hasMany(
            ConfiguredV2Models::resolve('service_call_model', WorkflowServiceCall::class),
            'linked_workflow_update_id',
        )
            ->oldest('accepted_at')
            ->oldest('created_at')
            ->oldest('id');
    }

    /**
     * @return array<int, mixed>
     */
    public function updateArguments(): array
    {
        if (! is_string($this->arguments) || $this->arguments === '') {
            return [];
        }

        $namespace = ConfiguredV2Models::query('run_model', WorkflowRun::class)
            ->whereKey($this->workflow_run_id)
            ->value('namespace');
        $blob = ExternalPayloads::resolveStoredPayload(
            $this->arguments,
            is_string($this->payload_codec) ? $this->payload_codec : null,
            is_string($namespace) ? $namespace : null,
        );

        $arguments = is_string($this->payload_codec) && $this->payload_codec !== ''
            ? Serializer::unserializeWithCodec($this->payload_codec, $blob)
            : Serializer::unserialize($blob);

        return is_array($arguments)
            ? array_values($arguments)
            : [];
    }

    public function updateResult(): mixed
    {
        if (! is_string($this->result) || $this->result === '') {
            return null;
        }

        $namespace = ConfiguredV2Models::query('run_model', WorkflowRun::class)
            ->whereKey($this->workflow_run_id)
            ->value('namespace');
        $blob = ExternalPayloads::resolveStoredPayload(
            $this->result,
            is_string($this->payload_codec) ? $this->payload_codec : null,
            is_string($namespace) ? $namespace : null,
        );

        return is_string($this->payload_codec) && $this->payload_codec !== ''
            ? Serializer::unserializeWithCodec($this->payload_codec, $blob)
            : Serializer::unserialize($blob);
    }

    /**
     * @return array{codec: string, blob: string}|array{codec: string, external_storage: array<string, mixed>}|null
     */
    public function resultEnvelope(): ?array
    {
        if (! is_string($this->result) || $this->result === '') {
            return null;
        }

        $run = $this->run;

        return ExternalPayloads::wireEnvelope(
            $this->result,
            $run?->payload_codec ?? CodecRegistry::defaultCodec(),
            is_string($run?->namespace) ? $run->namespace : null,
        );
    }

    /**
     * @return array<string, list<string>>
     */
    public function normalizedValidationErrors(): array
    {
        if (! is_array($this->validation_errors)) {
            return [];
        }

        $normalized = [];

        foreach ($this->validation_errors as $field => $messages) {
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
}
