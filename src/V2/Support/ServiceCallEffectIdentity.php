<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

final class ServiceCallEffectIdentity
{
    /**
     * @param array<string, mixed> $options
     */
    public static function fromOptions(array $options): ?string
    {
        return self::nonEmptyString($options['service_call_idempotency_key'] ?? null);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function fromContext(array $context): ?string
    {
        $serviceCall = $context['service_call'] ?? null;

        return is_array($serviceCall)
            ? self::nonEmptyString($serviceCall['idempotency_key'] ?? null)
            : null;
    }

    public static function storageToken(string $identity): string
    {
        return 'service-call-v1:' . hash('sha256', $identity);
    }

    private static function nonEmptyString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
