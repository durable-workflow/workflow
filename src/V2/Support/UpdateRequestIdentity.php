<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Workflow\V2\Enums\CommandType;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowInstance;

/**
 * Shared update receipt identity for embedded and Server admission.
 *
 * @api
 */
final class UpdateRequestIdentity
{
    /**
     * @param array<string, mixed> $context
     */
    public static function fromContext(array $context): ?string
    {
        // Server has always retained the caller's body key separately from
        // proxy correlation. Its omission means an independent operation.
        $server = $context['server'] ?? null;
        if (is_array($server) && ($server['command'] ?? null) === 'update') {
            $metadata = is_array($server['metadata'] ?? null) ? $server['metadata'] : [];
            return self::nonEmptyString($metadata['request_id'] ?? null);
        }

        $request = is_array($context['request'] ?? null) ? $context['request'] : [];
        return self::nonEmptyString($request['request_id'] ?? null);
    }

    public static function fromCommand(WorkflowCommand $command): ?string
    {
        $context = is_array($command->context) ? $command->context : [];
        $request = is_array($context['request'] ?? null) ? $context['request'] : [];
        if (($context['server']['command'] ?? null) === 'update' || array_key_exists('request_id', $request)) {
            return self::fromContext($context);
        }

        return self::nonEmptyString($command->request_id);
    }

    /**
     * Called while the owning instance is locked. Legacy context lookup must
     * not depend on a column that may contain an unrelated proxy header.
     *
     * @param array<string, mixed> $context
     */
    public static function find(WorkflowInstance $instance, array $context): ?WorkflowCommand
    {
        $key = self::fromContext($context);
        if ($key === null) {
            return null;
        }

        $token = self::keyToken($key);
        $commands = self::query($instance)->where(static function (Builder $query) use ($key, $token): void {
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
            if (self::fromCommand($command) === $key) {
                return $command;
            }
        }

        return null;
    }

    /**
     * The existing indexed column holds a bounded token, not caller text.
     * Preserve every old row, including a legacy key occupying that token.
     * The caller already holds the instance lock used by update admission.
     *
     * @param array<string, mixed> $context
     */
    public static function storageToken(WorkflowInstance $instance, array $context): string
    {
        $key = self::fromContext($context);
        $base = $key === null ? 'update-v1:' . Str::ulid() : self::keyToken($key);
        $candidate = $base;
        $suffix = 0;
        while (self::query($instance)->where('request_id', $candidate)->exists()) {
            $candidate = $base . ':' . ++$suffix;
        }
        return $candidate;
    }

    private static function keyToken(string $key): string
    {
        return 'update-v1:' . hash('sha256', $key);
    }

    private static function nonEmptyString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @return Builder<WorkflowCommand>
     */
    private static function query(WorkflowInstance $instance): Builder
    {
        return ConfiguredV2Models::query('command_model', WorkflowCommand::class)
            ->where('workflow_instance_id', $instance->id)
            ->where('command_type', CommandType::Update->value);
    }
}
