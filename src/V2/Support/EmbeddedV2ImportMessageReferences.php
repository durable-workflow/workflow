<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\V2\Enums\CommandStatus;
use Workflow\V2\Enums\CommandType;
use Workflow\V2\Enums\SignalStatus;
use Workflow\V2\Enums\UpdateStatus;

/** @internal Validates the command authority of reconstructed message rows. */
final class EmbeddedV2ImportMessageReferences
{
    /**
     * @param array<string, mixed> $bundle
     * @return list<array{rule: string, message: string}>
     */
    public static function errors(array $bundle): array
    {
        $commands = [];
        foreach (self::rows($bundle['commands'] ?? null) as $command) {
            $id = self::string($command['id'] ?? null);
            $type = CommandType::tryFrom(self::string($command['type'] ?? null) ?? '');
            $status = CommandStatus::tryFrom(self::string($command['status'] ?? null) ?? '');
            if ($id !== null && $type !== null && $status !== null) {
                $commands[$id][] = $type->value;
            }
        }

        $errors = [];
        foreach ([
            'signals' => 'signal',
            'updates' => 'update',
        ] as $section => $kind) {
            foreach (self::rows($bundle[$section] ?? null) as $index => $message) {
                $status = self::string($message['status'] ?? null) ?? '';
                $knownStatus = $kind === 'signal' ? SignalStatus::tryFrom($status) : UpdateStatus::tryFrom($status);
                if ($knownStatus === null) {
                    continue;
                }
                $commandId = self::string($message['command_id'] ?? null);
                if ($commandId !== null && ($commands[$commandId] ?? []) === [$kind]) {
                    continue;
                }
                // Commands are inserted first, for this exact run/instance,
                // in the import transaction. A colliding existing ID fails
                // that insert and rolls back before a message can bind to it.
                $errors[] = [
                    'rule' => 'references.message_command_invalid',
                    'message' => sprintf(
                        '%s.%d.command_id must identify exactly one %s command imported from this bundle.',
                        $section,
                        $index,
                        $kind,
                    ),
                ];
            }
        }

        return $errors;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function rows(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, is_array(...))) : [];
    }

    private static function string(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        return $value === '' ? null : $value;
    }
}
