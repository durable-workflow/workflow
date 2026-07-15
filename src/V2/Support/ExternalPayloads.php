<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use InvalidArgumentException;
use RuntimeException;
use Workflow\Serializers\CodecRegistry;
use Workflow\V2\Contracts\ExternalPayloadStorageDriver;
use Workflow\V2\Contracts\ExternalPayloadStoragePolicy;

final class ExternalPayloads
{
    public const STORED_REFERENCE_PREFIX = 'dw-external-payload:v1:';

    public static function externalizeForNamespace(
        ?string $payload,
        ?string $codec,
        ?string $namespace,
    ): ?string {
        if ($payload === null || self::isStoredReference($payload)) {
            return $payload;
        }

        $policy = self::policy();

        return self::externalize(
            $payload,
            $codec,
            $policy->driverFor($namespace),
            $policy->thresholdBytesFor($namespace),
        );
    }

    public static function externalize(
        string $payload,
        ?string $codec,
        ?ExternalPayloadStorageDriver $driver,
        ?int $thresholdBytes,
    ): string {
        if ($driver === null || $thresholdBytes === null || $thresholdBytes < 1) {
            return $payload;
        }

        if (self::isStoredReference($payload) || strlen($payload) <= $thresholdBytes) {
            return $payload;
        }

        $canonicalCodec = CodecRegistry::canonicalize($codec);
        $reference = ExternalPayloadStorage::store($driver, $payload, $canonicalCodec);

        return self::encodeStoredEnvelope([
            'codec' => $canonicalCodec,
            'external_storage' => $reference->toArray(),
        ]);
    }

    public static function resolveStoredPayload(
        string $payload,
        ?string $codec,
        ?string $namespace,
        ?ExternalPayloadStorageDriver $driver = null,
    ): string {
        $envelope = self::storedEnvelope($payload);

        if ($envelope === null) {
            return $payload;
        }

        $reference = self::referenceFromEnvelope($envelope);
        $canonicalCodec = CodecRegistry::canonicalize($codec ?? $envelope['codec'] ?? $reference->codec);

        if ($reference->codec !== $canonicalCodec) {
            throw new InvalidArgumentException('External payload reference codec does not match the row codec.');
        }

        $driver ??= self::policy()->driverFor($namespace);

        if ($driver === null) {
            throw new RuntimeException('External payload storage driver is unavailable for this namespace.');
        }

        return ExternalPayloadStorage::fetch($driver, $reference);
    }

    /**
     * @return array{codec: string, blob: string}|array{codec: string, external_storage: array<string, mixed>}|null
     */
    public static function wireEnvelope(?string $payload, ?string $codec, ?string $namespace): ?array
    {
        if ($payload === null) {
            return null;
        }

        $envelope = self::storedEnvelope($payload);

        if ($envelope !== null) {
            return $envelope;
        }

        return [
            'codec' => CodecRegistry::canonicalize($codec),
            'blob' => $payload,
        ];
    }

    public static function historyValue(?string $payload, ?string $codec, ?string $namespace): mixed
    {
        if ($payload === null) {
            return null;
        }

        return self::storedEnvelope($payload) ?? $payload;
    }

    public static function payloadBlob(mixed $payload, ?string $codec, ?string $namespace): ?string
    {
        if ($payload === null) {
            return null;
        }

        if (is_string($payload)) {
            return self::resolveStoredPayload($payload, $codec, $namespace);
        }

        if (! is_array($payload)) {
            return null;
        }

        if (isset($payload['blob']) && is_string($payload['blob'])) {
            return $payload['blob'];
        }

        if (! isset($payload['external_storage']) || ! is_array($payload['external_storage'])) {
            return null;
        }

        $reference = self::referenceFromEnvelope($payload);
        $envelopeCodec = isset($payload['codec']) && is_string($payload['codec'])
            ? CodecRegistry::canonicalize($payload['codec'])
            : $reference->codec;
        $canonicalCodec = CodecRegistry::canonicalize($codec ?? $envelopeCodec);

        if ($reference->codec !== $canonicalCodec || $envelopeCodec !== $canonicalCodec) {
            throw new InvalidArgumentException('External payload reference codec does not match the payload codec.');
        }

        $driver = self::policy()->driverFor($namespace);

        if ($driver === null) {
            throw new RuntimeException('External payload storage driver is unavailable for this namespace.');
        }

        return ExternalPayloadStorage::fetch($driver, $reference);
    }

    public static function isStoredReference(string $payload): bool
    {
        return str_starts_with($payload, self::STORED_REFERENCE_PREFIX);
    }

    /**
     * @return array{codec: string, external_storage: array<string, mixed>}|null
     */
    public static function storedEnvelope(string $payload): ?array
    {
        if (! self::isStoredReference($payload)) {
            return null;
        }

        $encoded = substr($payload, strlen(self::STORED_REFERENCE_PREFIX));
        $json = base64_decode($encoded, true);

        if (! is_string($json)) {
            throw new InvalidArgumentException('Stored external payload envelope is not valid base64.');
        }

        $decoded = json_decode($json, true);

        if (! is_array($decoded)) {
            throw new InvalidArgumentException('Stored external payload envelope is not valid JSON.');
        }

        self::referenceFromEnvelope($decoded);

        return [
            'codec' => CodecRegistry::canonicalize($decoded['codec']),
            'external_storage' => $decoded['external_storage'],
        ];
    }

    /**
     * @param array<string, mixed> $envelope
     */
    public static function encodeStoredEnvelope(array $envelope): string
    {
        self::referenceFromEnvelope($envelope);

        $json = json_encode(self::canonicalizeObjectKeys($envelope), JSON_UNESCAPED_SLASHES);

        if (! is_string($json)) {
            throw new InvalidArgumentException('External payload envelope could not be encoded.');
        }

        return self::STORED_REFERENCE_PREFIX . base64_encode($json);
    }

    private static function canonicalizeObjectKeys(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $item) {
            $value[$key] = self::canonicalizeObjectKeys($item);
        }

        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $envelope
     */
    private static function referenceFromEnvelope(array $envelope): ExternalPayloadReference
    {
        $codec = $envelope['codec'] ?? null;

        if (! is_string($codec) || $codec === '') {
            throw new InvalidArgumentException('External payload envelope codec must be a non-empty string.');
        }

        $referenceInput = $envelope['external_storage'] ?? null;

        if (! is_array($referenceInput)) {
            throw new InvalidArgumentException('External payload envelope must contain an external_storage object.');
        }

        $reference = ExternalPayloadReference::fromArray($referenceInput);
        $canonicalCodec = CodecRegistry::canonicalize($codec);

        if ($reference->codec !== $canonicalCodec) {
            throw new InvalidArgumentException('External payload reference codec must match the envelope codec.');
        }

        return $reference;
    }

    private static function policy(): ExternalPayloadStoragePolicy
    {
        if (function_exists('app')) {
            try {
                $app = app();

                if ($app->bound(ExternalPayloadStoragePolicy::class)) {
                    $policy = $app->make(ExternalPayloadStoragePolicy::class);

                    if ($policy instanceof ExternalPayloadStoragePolicy) {
                        return $policy;
                    }
                }
            } catch (\Throwable) {
                return new NullExternalPayloadStoragePolicy();
            }
        }

        return new NullExternalPayloadStoragePolicy();
    }
}
