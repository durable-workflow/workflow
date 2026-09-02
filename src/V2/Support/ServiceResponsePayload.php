<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\Serializers\Serializer;

/**
 * Shared replay contract for workflow-visible service response payloads.
 *
 * @internal
 */
final class ServiceResponsePayload
{
    public static function decode(
        mixed $payload,
        string $fallbackCodec,
        ?string $eventCodec = null,
        ?string $namespace = null,
    ): mixed {
        if (! is_string($payload) && ! self::isEnvelope($payload)) {
            return $payload;
        }

        $codec = self::codec($payload, $eventCodec, $fallbackCodec);
        $serialized = ExternalPayloads::payloadBlob($payload, $codec, $namespace);

        if ($serialized === null) {
            return null;
        }

        return Serializer::unserializeWithCodec($codec, $serialized);
    }

    private static function isEnvelope(mixed $payload): bool
    {
        return is_array($payload)
            && (
                (isset($payload['blob']) && is_string($payload['blob']))
                || (isset($payload['external_storage']) && is_array($payload['external_storage']))
            );
    }

    private static function codec(mixed $payload, ?string $eventCodec, string $fallbackCodec): string
    {
        if ($eventCodec !== null) {
            return $eventCodec;
        }

        if (is_array($payload) && is_string($payload['codec'] ?? null) && $payload['codec'] !== '') {
            return $payload['codec'];
        }

        if (is_string($payload) && ExternalPayloads::isStoredReference($payload)) {
            $envelope = ExternalPayloads::storedEnvelope($payload);

            if (is_string($envelope['codec'] ?? null) && $envelope['codec'] !== '') {
                return $envelope['codec'];
            }
        }

        return $fallbackCodec;
    }
}
