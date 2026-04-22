<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Workflow\Serializers\CodecRegistry;
use Workflow\Serializers\Serializer;
use Workflow\V2\Contracts\ExternalPayloadStorageDriver;

/**
 * Resolves `input` request fields into a concrete `(codec, blob)` envelope.
 *
 * The worker protocol carries every payload as `{codec, blob}`. Clients may
 * send `input` in two shapes on the HTTP API:
 *
 *   1. A plain array of arguments  →  encoded with the final v2 default codec (Avro)
 *   2. An explicit envelope object `{codec: "<name>", blob: "<opaque>"}`
 *      →  codec = the declared name, blob = the opaque string as-is
 *
 * Shape 2 lets PHP clients that already have SerializableClosure-encoded
 * payloads (or any other codec) preserve the exact bytes they produced.
 *
 * @see docs/configuration/worker-protocol.md
 *
 * @api Stable class surface consumed by the standalone workflow-server.
 *      The public static method signatures on this class are covered by
 *      the workflow package's semver guarantee. See docs/api-stability.md.
 */
final class PayloadEnvelopeResolver
{
    /**
     * Resolve a payload field to a PHP array of arguments.
     *
     * Used for control-plane surfaces (signal, query, update) where the
     * package API expects a PHP array, not a codec-tagged blob. Accepts
     * either a plain array of positional arguments or a {codec, blob}
     * envelope. When an envelope is received, the blob is decoded using
     * the declared codec — any codec in the {@see CodecRegistry} that can
     * round-trip an array is accepted (json, avro, and the legacy PHP
     * closure codecs). The decoded value must be an array.
     *
     * @param  mixed  $input  the `input` field from a validated request
     * @return array<int|string, mixed>  arguments (positional or named)
     */
    public static function resolveToArray(
        $input,
        string $field = 'input',
        ?ExternalPayloadStorageDriver $externalStorage = null
    ): array {
        if ($input === null || $input === []) {
            return [];
        }

        if (! is_array($input)) {
            throw ValidationException::withMessages([
                $field => [sprintf('The %s field must be an array or an envelope object.', $field)],
            ]);
        }

        if (! self::looksLikeEnvelope($input)) {
            return $input;
        }

        $envelope = self::resolveExplicitEnvelope($input, $field, $externalStorage);

        try {
            $decoded = Serializer::unserializeWithCodec($envelope['codec'], $envelope['blob']);
        } catch (\Throwable $e) {
            throw ValidationException::withMessages([
                $field . '.blob' => [sprintf(
                    'The %s envelope blob could not be decoded with codec "%s": %s',
                    $field,
                    $envelope['codec'],
                    $e->getMessage(),
                )],
            ]);
        }

        if (! is_array($decoded)) {
            throw ValidationException::withMessages([
                $field . '.blob' => [sprintf('The %s envelope blob must decode to an array.', $field)],
            ]);
        }

        return array_values($decoded);
    }

    /**
     * Resolve a worker-protocol command payload field (result or arguments)
     * that may be either a raw value or a {codec, blob} envelope.
     *
     * When an envelope is detected, the blob string is returned directly
     * (the bridge stores codec-tagged serialized payloads). When a raw
     * non-envelope value is received, it is returned as-is for backwards
     * compatibility with PHP workers that send pre-serialized strings.
     *
     * @param  mixed  $value  the command field value (result, arguments, etc.)
     * @return mixed  the resolved payload — either the blob string or the raw value
     */
    public static function resolveCommandPayload(
        $value,
        string $field = 'result',
        ?ExternalPayloadStorageDriver $externalStorage = null
    ): mixed {
        if ($value === null) {
            return null;
        }

        if (is_array($value) && self::looksLikeEnvelope($value)) {
            $envelope = self::resolveExplicitEnvelope($value, $field, $externalStorage);

            return $envelope['blob'];
        }

        return $value;
    }

    /**
     * Like resolveCommandPayload but also returns the codec when present.
     *
     * @return array{payload: mixed, codec: string|null}
     */
    public static function resolveCommandPayloadWithCodec(
        $value,
        string $field = 'result',
        ?ExternalPayloadStorageDriver $externalStorage = null
    ): array {
        if ($value === null) {
            return [
                'payload' => null,
                'codec' => null,
            ];
        }

        if (is_array($value) && self::looksLikeEnvelope($value)) {
            $envelope = self::resolveExplicitEnvelope($value, $field, $externalStorage);

            return [
                'payload' => $envelope['blob'],
                'codec' => $envelope['codec'],
            ];
        }

        return [
            'payload' => $value,
            'codec' => null,
        ];
    }

    /**
     * @param  mixed  $input    the `input` field from a validated request (array or null)
     * @return array{codec: string|null, blob: string|null}
     *         codec/blob are null when the client sent no input — callers
     *         should fall through to the final v2 default codec.
     */
    public static function resolve(
        $input,
        string $field = 'input',
        ?ExternalPayloadStorageDriver $externalStorage = null
    ): array {
        if ($input === null || $input === []) {
            return [
                'codec' => null,
                'blob' => null,
            ];
        }

        if (! is_array($input)) {
            throw ValidationException::withMessages([
                $field => [sprintf('The %s field must be an array or an envelope object.', $field)],
            ]);
        }

        if (self::looksLikeEnvelope($input)) {
            return self::resolveExplicitEnvelope($input, $field, $externalStorage);
        }

        $values = array_values($input);
        $codec = CodecRegistry::defaultCodec();

        return [
            'codec' => $codec,
            'blob' => Serializer::serializeWithCodec($codec, $values),
        ];
    }

    /**
     * Detect the `{codec, blob}` or `{codec, external_storage}` envelope shape.
     *
     * The array must be associative with keys exactly {codec, blob} or
     * {codec, external_storage} (order-independent).
     */
    private static function looksLikeEnvelope(array $input): bool
    {
        if ($input === []) {
            return false;
        }

        if (! array_key_exists('codec', $input)) {
            return false;
        }

        $keys = array_keys($input);
        sort($keys);

        return $keys === ['blob', 'codec'] || $keys === ['codec', 'external_storage'];
    }

    /**
     * @return array{codec: string, blob: string}
     */
    private static function resolveExplicitEnvelope(
        array $input,
        string $field,
        ?ExternalPayloadStorageDriver $externalStorage = null,
    ): array {
        $codec = $input['codec'] ?? null;

        if (! is_string($codec) || $codec === '') {
            throw ValidationException::withMessages([
                $field . '.codec' => ['The payload envelope codec must be a non-empty string.'],
            ]);
        }

        try {
            $canonical = CodecRegistry::canonicalize($codec);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([
                $field . '.codec' => [sprintf(
                    'Unknown payload codec "%s". Known codecs: %s.',
                    $codec,
                    implode(', ', CodecRegistry::names()),
                )],
            ]);
        }

        if (array_key_exists('external_storage', $input)) {
            return self::resolveExternalStorageEnvelope($input, $field, $canonical, $externalStorage);
        }

        $blob = $input['blob'] ?? null;

        if (! is_string($blob)) {
            throw ValidationException::withMessages([
                $field . '.blob' => ['The payload envelope blob must be a string.'],
            ]);
        }

        return [
            'codec' => $canonical,
            'blob' => $blob,
        ];
    }

    /**
     * @return array{codec: string, blob: string}
     */
    private static function resolveExternalStorageEnvelope(
        array $input,
        string $field,
        string $canonicalCodec,
        ?ExternalPayloadStorageDriver $externalStorage,
    ): array {
        if ($externalStorage === null) {
            throw ValidationException::withMessages([
                $field . '.external_storage' => ['External payload references require an external storage driver.'],
            ]);
        }

        $referenceInput = $input['external_storage'] ?? null;
        if (! is_array($referenceInput)) {
            throw ValidationException::withMessages([
                $field . '.external_storage' => ['The external payload reference must be an object.'],
            ]);
        }

        try {
            $reference = ExternalPayloadReference::fromArray($referenceInput);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages([
                $field . '.external_storage' => [$e->getMessage()],
            ]);
        }

        if ($reference->codec !== $canonicalCodec) {
            throw ValidationException::withMessages([
                $field . '.external_storage.codec' => [
                    'The external payload reference codec must match the payload envelope codec.',
                ],
            ]);
        }

        return [
            'codec' => $canonicalCodec,
            'blob' => ExternalPayloadStorage::fetch($externalStorage, $reference),
        ];
    }
}
