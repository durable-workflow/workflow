<?php

declare(strict_types=1);

namespace Workflow\V2\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\CommandOutcome;
use Workflow\V2\Enums\SignalStatus;
use Workflow\V2\Support\ConfiguredV2Models;

class WorkflowSignal extends Model
{
    use HasUlids;

    public $incrementing = false;

    protected $table = 'workflow_signal_records';

    protected $guarded = [];

    protected $keyType = 'string';

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $casts = [
        'status' => SignalStatus::class,
        'outcome' => CommandOutcome::class,
        'command_sequence' => 'integer',
        'workflow_sequence' => 'integer',
        'validation_errors' => 'array',
        'received_at' => 'datetime',
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

    /**
     * @return array<int, mixed>
     */
    public function signalArguments(): array
    {
        if (! is_string($this->arguments) || $this->arguments === '') {
            return [];
        }

        $arguments = is_string($this->payload_codec) && $this->payload_codec !== ''
            ? Serializer::unserializeWithCodec($this->payload_codec, $this->arguments)
            : Serializer::unserialize($this->arguments);

        return is_array($arguments)
            ? array_values($arguments)
            : [];
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
