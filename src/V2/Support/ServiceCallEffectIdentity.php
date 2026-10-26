<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

final class ServiceCallEffectIdentity
{
    public const OPTION = '__service_call_effect_identity';

    private const CONTEXT_KEY = '__effect_identity';

    private function __construct(
        private readonly string $identity,
    ) {
    }

    public static function forCurrentClaim(ServiceCallAttemptClaim $claim): ?self
    {
        return ServiceCallScheduler::ownsClaim($claim)
            ? new self(ServiceCallScheduler::attemptIdentity($claim->call->id, $claim->attempt))
            : null;
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
            'idempotency_key' => $this->identity,
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
        $serviceCall['idempotency_key'] = $this->identity;
        $context['service_call'] = $serviceCall;

        return $context;
    }

    public function storageToken(): string
    {
        return 'service-call-v1:' . hash('sha256', $this->identity);
    }
}
