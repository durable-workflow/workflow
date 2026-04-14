<?php

declare(strict_types=1);

namespace Workflow\Serializers;

final class Serializer
{
    /**
     * Legacy magic dispatch — preserves the pre-codec-registry behavior.
     *
     * - serialize(): uses config('workflows.serializer') (default Y).
     * - unserialize(): sniffs the blob ("base64:" prefix → Base64, else Y).
     *
     * New code should prefer {@see self::serializeWithCodec()} /
     * {@see self::unserializeWithCodec()} which make the codec choice explicit.
     */
    public static function __callStatic(string $name, array $arguments)
    {
        if ($name === 'unserialize') {
            $instance = self::legacyUnserializeInstance((string) ($arguments[0] ?? ''));
        } else {
            $instance = config('workflows.serializer', Y::class)::getInstance();
        }

        if (method_exists($instance, $name)) {
            return $instance->{$name}(...$arguments);
        }
    }

    /**
     * Serialize using an explicit codec name.
     *
     * If $codec is null, falls back to the default codec (config value or "json").
     */
    public static function serializeWithCodec(?string $codec, $data): string
    {
        $class = CodecRegistry::resolve($codec);
        return $class::serialize($data);
    }

    /**
     * Unserialize using an explicit codec name.
     */
    public static function unserializeWithCodec(?string $codec, string $data)
    {
        $class = CodecRegistry::resolve($codec);
        return $class::unserialize($data);
    }

    private static function legacyUnserializeInstance(string $blob): SerializerInterface
    {
        if (str_starts_with($blob, 'base64:')) {
            return Base64::getInstance();
        }

        // JSON blobs always start with "{", "[", a digit, quote, minus, "t"/"f"/"n".
        // PHP-serialized-closure blobs start with "O:".
        if ($blob !== '' && $blob[0] !== 'O' && self::looksLikeJson($blob)) {
            return Json::getInstance();
        }

        return Y::getInstance();
    }

    private static function looksLikeJson(string $blob): bool
    {
        $first = $blob[0] ?? '';
        if ($first === '[' || $first === '{' || $first === '"') {
            return true;
        }
        if ($first === '-' || ($first >= '0' && $first <= '9')) {
            return true;
        }
        return in_array($blob, ['true', 'false', 'null'], true);
    }
}
