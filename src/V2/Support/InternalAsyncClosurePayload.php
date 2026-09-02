<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Laravel\SerializableClosure\SerializableClosure;
use LogicException;
use Throwable;
use UnexpectedValueException;
use Workflow\Serializers\AvroBinaryValue;

/**
 * @internal Binary adapter used only between async() and the built-in AsyncWorkflow.
 */
final class InternalAsyncClosurePayload
{
    private const PREFIX = "durable-workflow.internal.async-closure.v1\0";

    private const SIGNATURE_BYTES = 32;

    public static function encode(SerializableClosure $closure): AvroBinaryValue
    {
        $serialized = serialize($closure);
        $signature = hash_hmac('sha256', $serialized, self::signingKey(), true);

        return AvroBinaryValue::fromBytes(self::PREFIX . $signature . $serialized);
    }

    public static function decode(AvroBinaryValue $payload): SerializableClosure
    {
        if (! str_starts_with($payload->bytes, self::PREFIX)) {
            throw new UnexpectedValueException('Invalid internal async closure payload tag.');
        }

        $signedPayload = substr($payload->bytes, strlen(self::PREFIX));
        if (strlen($signedPayload) <= self::SIGNATURE_BYTES) {
            throw new UnexpectedValueException('Truncated internal async closure payload.');
        }

        $signature = substr($signedPayload, 0, self::SIGNATURE_BYTES);
        $serialized = substr($signedPayload, self::SIGNATURE_BYTES);
        $expected = hash_hmac('sha256', $serialized, self::signingKey(), true);

        if (! hash_equals($expected, $signature)) {
            throw new UnexpectedValueException('Invalid internal async closure payload signature.');
        }

        try {
            $closure = unserialize($serialized);
        } catch (Throwable $throwable) {
            throw new UnexpectedValueException('Invalid internal async closure payload.', 0, $throwable);
        }

        if (! $closure instanceof SerializableClosure) {
            throw new UnexpectedValueException('Internal async payload did not contain a serializable closure.');
        }

        return $closure;
    }

    private static function signingKey(): string
    {
        $key = function_exists('config') ? config('app.key') : null;

        if (! is_string($key) || $key === '') {
            throw new LogicException('A non-empty application key is required for durable async closures.');
        }

        return $key;
    }
}
