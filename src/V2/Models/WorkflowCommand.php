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
        'accepted_at' => 'datetime',
        'applied_at' => 'datetime',
        'rejected_at' => 'datetime',
    ];

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
}
