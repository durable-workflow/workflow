<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\V2\Models\WorkflowServiceCall;

final class ServiceCallResultEnvelope
{
    /**
     * @return array<string, mixed>|null
     */
    public static function fromCall(WorkflowServiceCall $call): ?array
    {
        $result = $call->metadata['nexus_result'] ?? null;
        if (! is_array($result)) {
            return null;
        }
        if (is_string($result['blob'] ?? null)) {
            return $result;
        }
        if (is_array($result['external_reference'] ?? null) && is_string($call->output_payload_reference)) {
            return [
                'codec' => $result['codec'],
                'external_storage' => $result['external_reference'] + [
                    'uri' => $call->output_payload_reference,
                ],
            ];
        }

        return null;
    }
}
