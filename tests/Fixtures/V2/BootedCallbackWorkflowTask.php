<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\V2\Models\WorkflowTask;

/**
 * A configured task model with its own ordinary booted() callback.
 */
final class BootedCallbackWorkflowTask extends WorkflowTask
{
    public static int $creatingCallbacks = 0;

    protected static function booted(): void
    {
        static::creating(static function (): void {
            self::$creatingCallbacks++;
        });
    }
}
