<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Closure;
use JsonSerializable;
use LogicException;
use Workflow\Serializers\Serializer;
use Workflow\V2\Exceptions\ServiceOperationAuthorizationException;

/** One request's observed query outcome, reused after a database rollback. */
final class ServiceCallQueryObservation implements JsonSerializable
{
    public const OPTION = '__service_call_query_observation';

    private ?string $scope = null;

    /**
     * @var array<string, mixed>|null
     */
    private ?array $result = null;

    /**
     * @return array{observed: bool}
     */
    public function __debugInfo(): array
    {
        return [
            'observed' => $this->hasResult(),
        ];
    }

    /**
     * @return array<never, never>
     */
    public function __serialize(): array
    {
        throw new LogicException('Request-local service query observations cannot be serialized.');
    }

    /**
     * @param array<mixed> $parts
     */
    public static function scope(#[\SensitiveParameter] array $parts): string
    {
        return hash('sha256', Serializer::serializeWithCodec('avro', $parts));
    }

    public function hasResult(): bool
    {
        return $this->result !== null;
    }

    public function assertScope(string $scope): void
    {
        if ($this->scope !== null && $this->scope !== $scope) {
            throw new ServiceOperationAuthorizationException('service_query_scope_changed');
        }
    }

    /**
     * @param Closure(): array<string, mixed> $read
     * @return array<string, mixed>
     */
    public function read(string $scope, Closure $read): array
    {
        $this->assertScope($scope);
        if ($this->result !== null) {
            return $this->result;
        }
        $result = $read();
        $this->scope = $scope;
        $this->result = $result;

        return $result;
    }

    public function jsonSerialize(): never
    {
        throw new LogicException('Request-local service query observations cannot be JSON serialized.');
    }
}
