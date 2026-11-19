<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Database\Eloquent\Casts\Json;
use Workflow\V2\Models\WorkflowHistoryEvent;

/**
 * Cheap checks against a history event's stored JSON text (#1231).
 *
 * Projections scan a run's whole history for the few events that matter.
 * Decoding every payload made each scan cost the history's full payload size,
 * and a fan-out repeats the scan once per member. Text of letters, digits,
 * '-', '_' and '"' is stored verbatim by JSON, so a stored payload that lacks
 * it cannot contain it as a key or string value. Configured models, custom
 * casts and custom decoders always report a possible match.
 *
 * @internal
 */
final class StoredHistoryPayload
{
    public static function mayContain(WorkflowHistoryEvent $event, string $text): bool
    {
        if ($event::class !== WorkflowHistoryEvent::class
            || ($event->getCasts()['payload'] ?? null) !== 'array'
            || (class_exists(Json::class) && ! HistoryPayloadJson::usesDefaultDecoder())
            || preg_match('/^[A-Za-z0-9_"-]+$/', $text) !== 1) {
            return true;
        }

        $stored = $event->getAttributes()['payload'] ?? null;

        return ! is_string($stored) || str_contains($stored, $text);
    }
}
