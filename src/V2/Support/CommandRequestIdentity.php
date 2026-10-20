<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Throwable;
use Workflow\V2\Enums\CommandStatus;
use Workflow\V2\Enums\CommandType;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowSignal;
use Workflow\V2\Models\WorkflowUpdate;

/**
 * Shared caller-key receipt identity for signal and update admission.
 *
 * The caller's command key is scoped to one workflow instance and one command
 * type. The indexed column stores a bounded token derived from the key; the
 * literal key lives in the command context. A retained receipt is compared by
 * intent ({@see CommandIntent}) so a retry with the same key and the same
 * command returns the original decision while key reuse with a different
 * command is refused.
 *
 * @api
 */
final class CommandRequestIdentity
{
    public const CONFLICT_REASON = 'request_id_conflict';

    public const INTENT_SAME = 'same';

    public const INTENT_DIFFERENT = 'different';

    public const INTENT_UNVERIFIED = 'unverified';

    public static function supports(CommandType|string|null $type): bool
    {
        $value = $type instanceof CommandType ? $type->value : $type;

        return $value === CommandType::Signal->value || $value === CommandType::Update->value;
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function fromContext(array $context, CommandType $type): ?string
    {
        // Server has always retained the caller's body key separately from
        // proxy correlation. Its omission means an independent operation.
        $server = $context['server'] ?? null;
        if (is_array($server) && ($server['command'] ?? null) === $type->value) {
            $metadata = is_array($server['metadata'] ?? null) ? $server['metadata'] : [];
            return self::nonEmptyString($metadata['request_id'] ?? null);
        }

        $request = is_array($context['request'] ?? null) ? $context['request'] : [];
        return self::nonEmptyString($request['request_id'] ?? null);
    }

    public static function fromCommand(WorkflowCommand $command): ?string
    {
        $type = $command->command_type instanceof CommandType
            ? $command->command_type
            : CommandType::tryFrom((string) $command->command_type);
        if (! $type instanceof CommandType || ! self::supports($type)) {
            return null;
        }

        $context = is_array($command->context) ? $command->context : [];
        $request = is_array($context['request'] ?? null) ? $context['request'] : [];
        if (($context['server']['command'] ?? null) === $type->value || array_key_exists('request_id', $request)) {
            return self::fromContext($context, $type);
        }

        $column = self::nonEmptyString($command->request_id);
        if ($column === null || self::isToken($column)) {
            return null;
        }

        return $column;
    }

    /**
     * Called while the owning instance is locked. Legacy context lookup must
     * not depend on a column that may contain an unrelated proxy header.
     *
     * @param array<string, mixed> $context
     */
    public static function find(WorkflowInstance $instance, CommandType $type, array $context): ?WorkflowCommand
    {
        $key = self::fromContext($context, $type);
        if ($key === null) {
            return null;
        }

        $token = self::keyToken($type, $key);
        $commands = self::query($instance, $type)->where(static function (Builder $query) use ($key, $token): void {
            $query->where('request_id', $token)
                ->orWhere('request_id', 'like', $token . ':%')
                ->orWhere('request_id', $key)
                ->orWhere('context->request->request_id', $key)
                ->orWhere('context->server->metadata->request_id', $key);
        })->orderBy('created_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->cursor();

        foreach ($commands as $command) {
            // SQL collations may compare opaque keys case-insensitively.
            if (self::fromCommand($command) !== $key) {
                continue;
            }

            // A recorded conflict never consumes the key; the receipt it
            // refused against is always earlier in this ordering.
            if ($command->rejection_reason === self::CONFLICT_REASON) {
                continue;
            }

            return $command;
        }

        return null;
    }

    /**
     * The existing indexed column holds a bounded token, not caller text.
     * Preserve every old row, including a legacy key occupying that token.
     * The caller already holds the instance lock used by admission.
     *
     * @param array<string, mixed> $context
     */
    public static function storageToken(WorkflowInstance $instance, CommandType $type, array $context): string
    {
        $key = self::fromContext($context, $type);
        $base = $key === null ? self::tokenPrefix($type) . Str::ulid() : self::keyToken($type, $key);
        $candidate = $base;
        $suffix = 0;
        while (self::query($instance, $type)->where('request_id', $candidate)->exists()) {
            $candidate = $base . ':' . ++$suffix;
        }
        return $candidate;
    }

    /**
     * Compare a retry's intent digest with the retained receipt.
     *
     * Receipts recorded before intent digests exist are recomputed from their
     * retained name and validated arguments when those can still be decoded.
     * Legacy rejections kept no reliable arguments, so they stay unverified
     * rather than claiming equality or refusing a retry.
     */
    public static function compareIntent(WorkflowCommand $retained, string $candidateDigest): string
    {
        $stored = self::retainedDigest($retained);

        if ($stored === null) {
            return self::INTENT_UNVERIFIED;
        }

        return hash_equals($stored, $candidateDigest) ? self::INTENT_SAME : self::INTENT_DIFFERENT;
    }

    public static function retainedDigest(WorkflowCommand $retained): ?string
    {
        $stored = self::nonEmptyString($retained->intent_digest ?? null);

        if ($stored !== null) {
            return $stored;
        }

        if ($retained->status !== CommandStatus::Accepted) {
            return null;
        }

        try {
            return match ($retained->command_type) {
                CommandType::Update => self::legacyUpdateDigest($retained),
                CommandType::Signal => self::legacySignalDigest($retained),
                default => null,
            };
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Parts of the retained command known to differ from a conflicting retry.
     *
     * @return list<string>
     */
    public static function conflictingParts(
        WorkflowCommand $retained,
        string $name,
        string $targetScope,
        ?string $requestedRunId
    ): array {
        $parts = [];
        $retainedName = self::retainedName($retained);

        if ($retainedName !== null && $retainedName !== $name) {
            $parts[] = 'name';
        }

        $retainedScope = $retained->target_scope === 'run' ? 'run' : 'instance';
        $scope = $targetScope === 'run' ? 'run' : 'instance';
        $retainedRun = $retainedScope === 'run' ? $retained->requestedRunId() : null;
        $requestedRun = $scope === 'run' ? $requestedRunId : null;

        if ($retainedScope !== $scope || $retainedRun !== $requestedRun) {
            $parts[] = 'target';
        }

        if ($parts === []) {
            $parts[] = 'arguments';
        }

        return $parts;
    }

    public static function keyToken(CommandType $type, string $key): string
    {
        return self::tokenPrefix($type) . hash('sha256', $key);
    }

    private static function tokenPrefix(CommandType $type): string
    {
        return $type->value . '-v1:';
    }

    private static function isToken(string $value): bool
    {
        return preg_match('/^(signal|update)-v1:/', $value) === 1;
    }

    private static function retainedName(WorkflowCommand $retained): ?string
    {
        return match ($retained->command_type) {
            CommandType::Update => self::nonEmptyString(
                ConfiguredV2Models::query('update_model', WorkflowUpdate::class)
                    ->where('workflow_command_id', $retained->id)
                    ->value('update_name'),
            ),
            CommandType::Signal => self::nonEmptyString(
                ConfiguredV2Models::query('signal_model', WorkflowSignal::class)
                    ->where('workflow_command_id', $retained->id)
                    ->value('signal_name'),
            ),
            default => null,
        };
    }

    private static function legacyUpdateDigest(WorkflowCommand $retained): ?string
    {
        /** @var WorkflowUpdate|null $update */
        $update = ConfiguredV2Models::query('update_model', WorkflowUpdate::class)
            ->where('workflow_command_id', $retained->id)
            ->first();

        if (! $update instanceof WorkflowUpdate || ! is_string($update->update_name)) {
            return null;
        }

        return CommandIntent::digest(
            CommandType::Update,
            $update->update_name,
            $update->updateArguments(),
            $retained->target_scope,
            $retained->requestedRunId(),
        );
    }

    private static function legacySignalDigest(WorkflowCommand $retained): ?string
    {
        /** @var WorkflowSignal|null $signal */
        $signal = ConfiguredV2Models::query('signal_model', WorkflowSignal::class)
            ->where('workflow_command_id', $retained->id)
            ->first();

        if (! $signal instanceof WorkflowSignal || ! is_string($signal->signal_name)) {
            return null;
        }

        return CommandIntent::digest(
            CommandType::Signal,
            $signal->signal_name,
            $signal->signalArguments(),
            $retained->target_scope,
            $retained->requestedRunId(),
        );
    }

    private static function nonEmptyString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @return Builder<WorkflowCommand>
     */
    private static function query(WorkflowInstance $instance, CommandType $type): Builder
    {
        return ConfiguredV2Models::query('command_model', WorkflowCommand::class)
            ->where('workflow_instance_id', $instance->id)
            ->where('command_type', $type->value);
    }
}
