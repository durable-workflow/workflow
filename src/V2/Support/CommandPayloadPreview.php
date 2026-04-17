<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Throwable;
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
     * When the persisted `payload_codec` is known, prefer
     * {@see self::previewWithCodec()} so binary codecs (Avro) decode
     * correctly. The no-codec overload falls back to the legacy blob-sniff
     * behavior and is retained for call sites that do not track codec.
     */
    public static function preview(mixed $payload): mixed
    {
        if (! self::available($payload)) {
            return null;
        }

        try {
            return Serializer::unserialize($payload);
        } catch (Throwable) {
            return $payload;
        }
    }

    /**
     * Decode a payload blob using an explicit codec for display.
     *
     * When $codec is null or empty, falls through to the sniff-based
     * {@see self::preview()}. When a codec is named, the blob is decoded
     * through {@see Serializer::unserializeWithCodec()} so binary codecs
     * like Avro (which sniffing cannot detect) render readably in the
     * run-detail view, history timeline, and update view.
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

        if ($codec === null || $codec === '') {
            return self::preview($payload);
        }

        try {
            return Serializer::unserializeWithCodec($codec, $payload);
        } catch (Throwable) {
            return $payload;
        }
    }
}
