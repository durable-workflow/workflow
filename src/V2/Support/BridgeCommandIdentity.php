<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use InvalidArgumentException;
use JsonSerializable;
use LogicException;
use Workflow\V2\Enums\CommandType;
use Workflow\V2\Exceptions\BridgeCommandIdentityConflict;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowInstance;

/** @internal Host-created bridge identities cannot be supplied as JSON context. */
final class BridgeCommandIdentity implements JsonSerializable
{
    private const CONTEXT_KEY = '__bridge_command_identity';

    /**
     * @param array<string, string> $metadata
     */
    private function __construct(
        private readonly string $key,
        private readonly array $metadata
    ) {
    }

    /**
     * @return array<never, never>
     */
    public function __serialize(): array
    {
        throw new LogicException('Bridge command identities cannot be serialized.');
    }

    public static function forCommand(
        string $adapter,
        string $action,
        string $rawKey,
        string $field,
        string $name
    ): self {
        if ($adapter === '' || strlen($adapter) > 64 || preg_match('/^[a-zA-Z0-9._:-]+$/D', $adapter) !== 1
            || ! in_array(
                [$action, $field],
                [['signal_workflow', 'signal_name'], ['update_workflow', 'update_name']],
                true
            )
            || $rawKey === '' || strlen($rawKey) > 1020 || $name === '' || strlen($name) > 1020) {
            throw new InvalidArgumentException(
                'A bridge identity requires a bounded adapter, action, provider key and target.'
            );
        }

        $digest = hash_init('sha256');
        foreach ([$adapter, $action, $rawKey, $field, $name] as $part) {
            // Native uses u64 big-endian byte lengths. Every field is bounded
            // below 2^32, so two network-order words are portable on PHP hosts.
            hash_update($digest, pack('N2', 0, strlen($part)) . $part);
        }

        return new self('dw-bridge:v1:' . hash_final($digest), [
            'adapter' => $adapter,
            'action' => $action,
            'idempotency_key' => $rawKey,
            $field => $name,
        ]);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function fromContext(array $context): ?self
    {
        $identity = $context[self::CONTEXT_KEY] ?? null;

        return $identity instanceof self ? $identity : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function commandContext(): array
    {
        return [
            self::CONTEXT_KEY => $this,
            'server' => [
                'metadata' => $this->metadata + [
                    'request_id' => $this->key,
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function persistentContext(array $context): array
    {
        unset($context[self::CONTEXT_KEY]);
        $context['request'] = is_array($context['request'] ?? null) ? $context['request'] : [];
        $context['request']['request_id'] = $this->key;
        $context['server'] = is_array($context['server'] ?? null) ? $context['server'] : [];
        $metadata = is_array($context['server']['metadata'] ?? null) ? $context['server']['metadata'] : [];
        $context['server']['metadata'] = array_replace($metadata, $this->metadata, [
            'request_id' => $this->key,
        ]);

        return $context;
    }

    /**
     * Called only while the command writer holds the owning instance lock.
     */
    public function find(WorkflowInstance $instance, CommandType $type): ?WorkflowCommand
    {
        $query = ConfiguredV2Models::query('command_model', WorkflowCommand::class)
            ->where('workflow_instance_id', $instance->id)
            ->where('command_type', $type->value)
            ->lockForUpdate();
        $current = (clone $query)->where('request_id', $this->key)
            ->first();
        if ($current instanceof WorkflowCommand) {
            if (! $this->matches($current)) {
                throw new BridgeCommandIdentityConflict();
            }

            return $current;
        }

        // Older PHP receipts stored the provider key (or null) in request_id.
        // Preserve those rows and match their full tuple under the same lock.
        $legacy = (clone $query)->where('status', 'accepted');
        foreach ($this->metadata as $field => $value) {
            $legacy->where('context->server->metadata->' . $field, $value);
        }
        foreach ($legacy->orderBy('created_at')->orderBy('id')->cursor() as $command) {
            // Some SQL collations compare opaque provider keys without case.
            if ($this->matches($command)) {
                return $command;
            }
        }

        return null;
    }

    public function storageToken(): string
    {
        return $this->key;
    }

    public function jsonSerialize(): never
    {
        throw new LogicException('Bridge command identities cannot be JSON serialized.');
    }

    private function matches(WorkflowCommand $command): bool
    {
        $metadata = $command->context['server']['metadata'] ?? [];
        foreach ($this->metadata as $field => $value) {
            if (($metadata[$field] ?? null) !== $value) {
                return false;
            }
        }

        return true;
    }
}
