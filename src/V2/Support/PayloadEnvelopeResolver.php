<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Validation\ValidationException;
use Workflow\Serializers\CodecRegistry;
use Workflow\Serializers\Serializer;

/**
 * Resolves `input` request fields into a concrete `(codec, blob)` envelope.
 *
 * The worker protocol carries every payload as `{codec, blob}`. Clients may
 * send `input` in two shapes on the HTTP API:
 *
 *   1. A plain JSON array of arguments  →  codec "json", blob = JSON bytes
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
     * envelope — when an envelope is received, the blob is decoded using
     * the declared codec. Only the "json" codec is supported for array
     * surfaces; other codecs cannot be losslessly represented as PHP arrays.
     *
     * @param  mixed  $input  the `input` field from a validated request
     * @return array<int|string, mixed>  arguments (positional or named)
     */
    public static function resolveToArray($input, string $field = 'input'): array
    {
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

        $envelope = self::resolveExplicitEnvelope($input, $field);

        if ($envelope['codec'] !== 'json') {
            throw ValidationException::withMessages([
                $field . '.codec' => [sprintf(
                    'Only the "json" codec is supported for %s on this surface. Got "%s".',
                    $field,
                    $envelope['codec'],
                )],
            ]);
        }

        $decoded = json_decode($envelope['blob'], true);

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
    public static function resolveCommandPayload($value, string $field = 'result'): mixed
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value) && self::looksLikeEnvelope($value)) {
            $envelope = self::resolveExplicitEnvelope($value, $field);

            return $envelope['blob'];
        }

        return $value;
    }

    /**
     * Like resolveCommandPayload but also returns the codec when present.
     *
     * @return array{payload: mixed, codec: string|null}
     */
    public static function resolveCommandPayloadWithCodec($value, string $field = 'result'): array
    {
        if ($value === null) {
            return ['payload' => null, 'codec' => null];
        }

        if (is_array($value) && self::looksLikeEnvelope($value)) {
            $envelope = self::resolveExplicitEnvelope($value, $field);

            return ['payload' => $envelope['blob'], 'codec' => $envelope['codec']];
        }

        return ['payload' => $value, 'codec' => null];
    }

    /**
     * @param  mixed  $input    the `input` field from a validated request (array or null)
     * @return array{codec: string|null, blob: string|null}
     *         codec/blob are null when the client sent no input — callers
     *         should fall through to the configured default codec.
     */
    public static function resolve($input, string $field = 'input'): array
    {
        if ($input === null || $input === []) {
            return ['codec' => null, 'blob' => null];
        }

        if (! is_array($input)) {
            throw ValidationException::withMessages([
                $field => [sprintf('The %s field must be an array or an envelope object.', $field)],
            ]);
        }

        if (self::looksLikeEnvelope($input)) {
            return self::resolveExplicitEnvelope($input, $field);
        }

        $values = array_values($input);

        return [
            'codec' => 'json',
            'blob' => Serializer::serializeWithCodec('json', $values),
        ];
    }

    /**
     * Detect the `{codec, blob}` envelope shape.
     *
     * The array must be associative with keys exactly {codec, blob} (order-independent).
     */
    private static function looksLikeEnvelope(array $input): bool
    {
        if ($input === []) {
            return false;
        }

        if (! array_key_exists('codec', $input) || ! array_key_exists('blob', $input)) {
            return false;
        }

        $extra = array_diff(array_keys($input), ['codec', 'blob']);

        return $extra === [];
    }

    /**
     * @return array{codec: string, blob: string}
     */
    private static function resolveExplicitEnvelope(array $input, string $field): array
    {
        $codec = $input['codec'] ?? null;
        $blob = $input['blob'] ?? null;

        if (! is_string($codec) || $codec === '') {
            throw ValidationException::withMessages([
                $field . '.codec' => ['The payload envelope codec must be a non-empty string.'],
            ]);
        }

        if (! is_string($blob)) {
            throw ValidationException::withMessages([
                $field . '.blob' => ['The payload envelope blob must be a string.'],
            ]);
        }

        try {
            $canonical = CodecRegistry::canonicalize($codec);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages([
                $field . '.codec' => [sprintf(
                    'Unknown payload codec "%s". Known codecs: %s.',
                    $codec,
                    implode(', ', CodecRegistry::names()),
                )],
            ]);
        }

        return ['codec' => $canonical, 'blob' => $blob];
    }
}
