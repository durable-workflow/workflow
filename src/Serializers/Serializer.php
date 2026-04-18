<?php

declare(strict_types=1);

namespace Workflow\Serializers;

use Illuminate\Queue\SerializesAndRestoresModelIdentifiers;
use Throwable;

final class Serializer
{
    /**
     * Shared codec-independent normalization helpers live on this object so
     * that static calls like {@see Serializer::serializable()} can reach them
     * without coupling callers to the configured codec.
     */
    private static ?ModelIdentifierHelper $helper = null;

    /**
     * Legacy magic dispatch — preserves the pre-codec-registry behavior for
     * the codec-specific surface: {@see serialize()} / {@see unserialize()}.
     *
     * - serialize(): uses config('workflows.serializer') (default "avro").
     * - unserialize(): sniffs the blob ("base64:" prefix → Base64, JSON-like →
     *   Json, else Y). Avro blobs are not detectable by sniff alone, so
     *   call sites with codec context should prefer
     *   {@see self::unserializeWithCodec()}.
     *
     * Codec-independent helpers ({@see serializable()}, {@see serializeModels()},
     * {@see unserializeModels()}) are declared as first-class static methods
     * on this class and short-circuit before __callStatic so they produce the
     * same result regardless of the configured codec. They originated to
     * keep the JSON path safe ({@see Json} does not implement those helpers
     * itself), and silently returning null from them used to drop exception
     * trace frames and failure-property values during v2 failure normalization.
     *
     * New code should prefer {@see self::serializeWithCodec()} /
     * {@see self::unserializeWithCodec()} which make the codec choice explicit.
     */
    public static function __callStatic(string $name, array $arguments)
    {
        if ($name === 'unserialize') {
            $class = self::legacyUnserializeClass((string) ($arguments[0] ?? ''));
        } else {
            $class = self::defaultCodecClass();
        }

        if ($name === 'serialize' && ! is_subclass_of($class, AbstractSerializer::class)) {
            $arguments[0] = self::normalizeForCodec($arguments[0] ?? null);
        }

        if (method_exists($class, $name)) {
            return $class::{$name}(...$arguments);
        }
    }

    /**
     * Codec-independent replacement for the legacy AbstractSerializer helper:
     * is this value safe to pass to PHP's native serialize()?
     *
     * Used by exception-trace filtering and v2 failure property capture. Must
     * be safe to call regardless of the configured codec.
     */
    public static function serializable(mixed $data): bool
    {
        try {
            serialize($data);
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Recursively replace Eloquent models inside $data with their serialized
     * identifier representation, and convert Throwable instances into plain
     * arrays. Always applied by v1 and v2 failure normalization paths.
     *
     * Codec-independent: returns the same shape regardless of whether the
     * configured codec is "json", Y, or Base64.
     */
    public static function serializeModels(mixed $data): mixed
    {
        if ($data instanceof Throwable) {
            return self::throwableToArray($data);
        }

        if (is_array($data)) {
            return self::helper()->serializeValue($data);
        }

        return $data;
    }

    /**
     * Inverse of {@see serializeModels()} for nested arrays. Scalars and
     * non-array values are returned unchanged.
     */
    public static function unserializeModels(mixed $data): mixed
    {
        if (is_array($data)) {
            return self::helper()->unserializeValue($data);
        }

        return $data;
    }

    /**
     * Serialize using an explicit codec name.
     *
     * If $codec is null, falls back to the final v2 default codec (Avro).
     */
    public static function serializeWithCodec(?string $codec, $data): string
    {
        $class = CodecRegistry::resolve($codec);

        if (! is_subclass_of($class, AbstractSerializer::class)) {
            $data = self::normalizeForCodec($data);
        }

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

    /**
     * @return class-string<SerializerInterface>
     */
    private static function defaultCodecClass(): string
    {
        $configured = function_exists('config') ? config('workflows.serializer') : null;

        if (is_string($configured) && $configured !== '') {
            return CodecRegistry::resolve($configured);
        }

        return CodecRegistry::resolve(null);
    }

    /**
     * Pre-normalize $data before handing it to a codec that does not itself
     * apply model/Throwable normalization (for example {@see Json}). Legacy
     * codecs that extend {@see AbstractSerializer} already call serializeModels
     * internally and must not be double-normalized here.
     */
    private static function normalizeForCodec(mixed $data): mixed
    {
        return self::serializeModels($data);
    }

    /**
     * @return array{class: class-string<Throwable>, message: string, code: int|string, line: int, file: string, trace: list<array<string, mixed>>}
     */
    private static function throwableToArray(Throwable $throwable): array
    {
        return [
            'class' => get_class($throwable),
            'message' => $throwable->getMessage(),
            'code' => $throwable->getCode(),
            'line' => $throwable->getLine(),
            'file' => $throwable->getFile(),
            'trace' => collect($throwable->getTrace())
                ->filter(static fn ($trace) => self::serializable($trace))
                ->values()
                ->toArray(),
        ];
    }

    private static function helper(): ModelIdentifierHelper
    {
        if (self::$helper === null) {
            self::$helper = new ModelIdentifierHelper();
        }

        return self::$helper;
    }

    /**
     * @return class-string<SerializerInterface>
     */
    private static function legacyUnserializeClass(string $blob): string
    {
        if (str_starts_with($blob, 'base64:')) {
            return Base64::class;
        }

        // JSON blobs always start with "{", "[", a digit, quote, minus, "t"/"f"/"n".
        // PHP-serialized-closure blobs start with "O:".
        if ($blob !== '' && $blob[0] !== 'O' && self::looksLikeJson($blob)) {
            return Json::class;
        }

        return Y::class;
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

/**
 * @internal
 */
final class ModelIdentifierHelper
{
    use SerializesAndRestoresModelIdentifiers {
        getSerializedPropertyValue as public;
        getRestoredPropertyValue as public;
    }

    public function serializeValue(mixed $value): mixed
    {
        if (is_array($value)) {
            foreach ($value as $key => $nested) {
                $value[$key] = $this->serializeValue($nested);
            }

            return $value;
        }

        return $this->getSerializedPropertyValue($value);
    }

    public function unserializeValue(mixed $value): mixed
    {
        if (is_array($value)) {
            foreach ($value as $key => $nested) {
                $value[$key] = $this->unserializeValue($nested);
            }

            return $value;
        }

        return $this->getRestoredPropertyValue($value);
    }
}
