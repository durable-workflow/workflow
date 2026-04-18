<?php

declare(strict_types=1);

namespace Workflow\Serializers;

use JsonException;
use RuntimeException;

/**
 * Legacy untagged JSON helper.
 *
 * Final v2 does not register JSON as a named payload codec. This class remains
 * only for the codec-blind {@see Serializer::unserialize()} legacy sniffer.
 */
final class Json implements SerializerInterface
{
    private static ?self $instance = null;

    private function __construct()
    {
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function encode(string $data): string
    {
        return $data;
    }

    public static function decode(string $data): string
    {
        return $data;
    }

    public static function serialize($data): string
    {
        try {
            return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $e) {
            throw new RuntimeException('Failed to JSON-encode payload: ' . $e->getMessage(), 0, $e);
        }
    }

    public static function unserialize(string $data)
    {
        if ($data === '') {
            return null;
        }

        try {
            return json_decode($data, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            if (self::looksLikeBase64Avro($data)) {
                throw new CodecDecodeException(
                    'json',
                    'Payload bytes look like base64-encoded Avro, not JSON: ' . $e->getMessage(),
                    'The blob is valid base64 starting with an Avro framing prefix (0x00 generic wrapper or 0x01 typed schema). Either change the codec tag to "avro", or re-encode the payload as JSON.',
                    $e,
                );
            }

            throw new CodecDecodeException(
                'json',
                'Failed to JSON-decode payload: ' . $e->getMessage(),
                'Re-encode the payload as valid UTF-8 JSON (RFC 8259), or change the codec tag if a different codec produced these bytes.',
                $e,
            );
        }
    }

    /**
     * Heuristic: do these bytes look like base64-encoded Avro?
     *
     * The cheapest reliable check: pure base64 alphabet, base64_decode in
     * strict mode succeeds, and the first decoded byte is 0x00 (generic
     * Avro wrapper) or 0x01 (typed Avro schema). JSON ASCII text never
     * decodes to bytes leading with 0x00/0x01 because base64 alphabet
     * cannot represent control characters in source form, so this check
     * has effectively no false positives on misformatted JSON.
     */
    private static function looksLikeBase64Avro(string $data): bool
    {
        if ($data === '' || preg_match('/^[A-Za-z0-9+\/]+={0,2}$/', $data) !== 1) {
            return false;
        }

        $decoded = base64_decode($data, true);
        if ($decoded === false || $decoded === '') {
            return false;
        }

        return $decoded[0] === "\x00" || $decoded[0] === "\x01";
    }
}
