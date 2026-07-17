<?php

declare(strict_types=1);

namespace Workflow\Traits;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Optional trait providing webhook and heartbeat support for activities.
 * Use this trait if you need webhook URLs or heartbeat functionality.
 * Activities without this trait will analyze faster in static analysis tools.
 */
trait ActivityWithWebhookSupport
{
    public function webhookUrl(string $signalMethod = ''): string
    {
        $workflow = Str::kebab(class_basename($this->storedWorkflow->class));

        if ($signalMethod === '') {
            return route("workflows.start.{$workflow}");
        }

        $signal = Str::kebab($signalMethod);
        return route("workflows.signal.{$workflow}.{$signal}", [
            'workflowId' => $this->storedWorkflow->id,
        ]);
    }

    public function heartbeat(): void
    {
        pcntl_alarm(max($this->timeout, 0));
        if ($this->timeout) {
            Cache::put($this->key, 1, $this->timeout);
        }
    }
}
