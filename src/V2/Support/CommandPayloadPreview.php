<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Throwable;
use Workflow\Serializers\AvroBinaryValue;
use Workflow\Serializers\Serializer;

final class CommandPayloadPreview
{
    public static function available(mixed $payload): bool
    {
        return is_string($payload) && $payload !== '';
    }

    /**
     * Decode a payload blob for display.
     *
     * The no-codec overload treats the payload as Avro. Durable v2 payloads
     * never infer a codec from their bytes.
     */
    public static function preview(mixed $payload): mixed
    {
        if (! self::available($payload)) {
            return null;
        }

        if (is_string($payload) && ExternalPayloads::isStoredReference($payload)) {
            return ExternalPayloads::storedEnvelope($payload);
        }

        try {
            return self::displayValue(Serializer::unserializeWithCodec('avro', $payload));
        } catch (Throwable) {
            return $payload;
        }
    }

    /**
     * Decode a payload blob using an explicit codec for display.
     *
     * When $codec is null or empty, falls through to the Avro-only
     * {@see self::preview()}. When a codec is named, the blob is decoded
     * through {@see Serializer::unserializeWithCodec()} so binary codecs
     * like Avro render readably in the
     * run-detail view, history timeline, and update view. Unsupported codec
     * names are never used to decode a payload.
     *
     * Decode failures return the raw blob instead of propagating — this is
     * a display helper, not a strict decoder. Mixed-codec errors (Avro
     * bytes tagged as JSON, etc.) surface at ingress elsewhere.
     */
    public static function previewWithCodec(mixed $payload, ?string $codec): mixed
    {
        if (! self::available($payload)) {
            return null;
        }

        if (is_string($payload) && ExternalPayloads::isStoredReference($payload)) {
            return ExternalPayloads::storedEnvelope($payload);
        }

        if ($codec === null || $codec === '') {
            return self::preview($payload);
        }

        try {
            return self::displayValue(Serializer::unserializeWithCodec($codec, $payload));
        } catch (Throwable) {
            return $payload;
        }
    }

    private static function displayValue(mixed $value): mixed
    {
        if ($value instanceof AvroBinaryValue) {
            return [
                '$type' => 'bytes',
                'base64' => base64_encode($value->bytes),
            ];
        }
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = self::displayValue($item);
            }
        }

        return $value;
    }
}
