<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use JsonSerializable;
use LogicException;

final class ServiceCallEffectIdentity implements JsonSerializable
{
    public const OPTION = '__service_call_effect_identity';

    private const CONTEXT_KEY = '__effect_identity';

    private function __construct(
        private readonly string $auditIdentity,
        #[\SensitiveParameter]
        private readonly string $storageToken,
    ) {
    }

    /**
     * @return array{capability: string}
     */
    public function __debugInfo(): array
    {
        return [
            'capability' => '[opaque]',
        ];
    }

    /**
     * @return array<never, never>
     */
    public function __serialize(): array
    {
        throw new LogicException('Service-call effect identities cannot be serialized.');
    }

    public static function forCurrentClaim(ServiceCallAttemptClaim $claim): ?self
    {
        if (! ServiceCallScheduler::ownsClaim($claim)) {
            return null;
        }

        $token = $claim->effectToken();
        if (preg_match('/^service-call-v1:[a-f0-9]{64}$/D', $token) !== 1) {
            return null;
        }

        return new self(ServiceCallScheduler::attemptIdentity($claim->call->id, $claim->attempt), $token);
    }

    /**
     * @param array<string, mixed> $options
     */
    public static function fromOptions(array $options): ?self
    {
        $identity = $options[self::OPTION] ?? null;

        return $identity instanceof self ? $identity : null;
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function fromContext(array $context): ?self
    {
        $serviceCall = $context['service_call'] ?? null;
        $identity = is_array($serviceCall) ? ($serviceCall[self::CONTEXT_KEY] ?? null) : null;

        return $identity instanceof self ? $identity : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function commandContext(): array
    {
        return [
            'idempotency_key' => $this->auditIdentity,
            self::CONTEXT_KEY => $this,
        ];
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function persistentContext(array $context): array
    {
        $serviceCall = is_array($context['service_call'] ?? null) ? $context['service_call'] : [];
        unset($serviceCall[self::CONTEXT_KEY]);
        $serviceCall['idempotency_key'] = $this->auditIdentity;
        $context['service_call'] = $serviceCall;

        return $context;
    }

    public function storageToken(): string
    {
        return $this->storageToken;
    }

    public function jsonSerialize(): never
    {
        throw new LogicException('Service-call effect identities cannot be JSON serialized.');
    }
}
