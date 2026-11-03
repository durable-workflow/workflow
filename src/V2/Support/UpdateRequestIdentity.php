<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\V2\Enums\CommandType;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowInstance;

/**
 * Update receipt identity, kept as the update-specific view of
 * {@see CommandRequestIdentity} for embedded and Server admission.
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
        return CommandRequestIdentity::fromContext($context, CommandType::Update);
    }

    public static function fromCommand(WorkflowCommand $command): ?string
    {
        return CommandRequestIdentity::fromCommand($command);
    }

    /**
     * Called while the owning instance is locked.
     *
     * @param array<string, mixed> $context
     */
    public static function find(WorkflowInstance $instance, array $context): ?WorkflowCommand
    {
        return CommandRequestIdentity::find($instance, CommandType::Update, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function storageToken(WorkflowInstance $instance, array $context): string
    {
        return CommandRequestIdentity::storageToken($instance, CommandType::Update, $context);
    }
}
