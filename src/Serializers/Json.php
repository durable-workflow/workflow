<?php

declare(strict_types=1);

namespace Workflow\Serializers;

use JsonException;
use RuntimeException;

/**
 * Language-neutral JSON codec.
 *
 * Produces plain UTF-8 JSON bytes — no PHP SerializableClosure wrapping, no HMAC,
 * no base64. Any SDK in any language can encode/decode these payloads.
 *
 * Registered as codec name "json" in {@see CodecRegistry}.
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
            throw new RuntimeException('Failed to JSON-decode payload: ' . $e->getMessage(), 0, $e);
        }
    }
}
