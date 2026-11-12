<?php

declare(strict_types=1);

namespace Workflow\V2\Compensation\Persistence;

use Closure;
use LogicException;
use Workflow\V2\Compensation\Definition\PortableDescriptor;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Support\ConfiguredV2Models;
use Workflow\V2\Support\SqliteTransaction;

/** @internal Scope lock, bounded journal and ledger mutations on the native storage connection. */
final class ScopeStorage
{
    public const SCOPES = 'workflow_compensation_scopes';

    public const OBLIGATIONS = 'workflow_compensation_obligations';

    public const JOURNAL = 'workflow_compensation_journal';

    public static function mutate(ScopeIdentity $identity, Closure $operation): mixed
    {
        if (\Workflow\V2\Support\WorkflowFiberContext::active()) {
            throw new LogicException(
                'Scope storage belongs at a recorded activity boundary, never in workflow replay.'
            );
        }
        $database = ConfiguredV2Models::connection();
        return SqliteTransaction::run(
            static fn (): mixed => $operation(self::locked($identity), $database),
            admission: static function () use ($database, $identity): void {
                $database->table(self::SCOPES)->where('id', $identity->id())->update([
                    'id' => $identity->id(),
                ]);
            },
            connection: $database
        );
    }

    public static function locked(ScopeIdentity $identity): array
    {
        $row = ConfiguredV2Models::connection()->table(self::SCOPES)->where('id', $identity->id())->lockForUpdate()
            ->first();
        if ($row === null || $row->identity !== PortableDescriptor::encode($identity->toArray())) {
            throw new LogicException('The exact compensation scope generation is unavailable.');
        }
        return (array) $row;
    }

    public static function append(array $scope, string $kind, array $data): void
    {
        $database = ConfiguredV2Models::connection();
        $sequence = (int) $database->table(self::SCOPES)->where('id', $scope['id'])->value('event_sequence') + 1;
        if ($sequence > 8192) {
            throw new LogicException('The scope journal work limit is exhausted; unresolved work is retained.');
        }
        $database->table(self::JOURNAL)->insert([
            'scope_id' => $scope['id'],
            'sequence' => $sequence,
            'kind' => $kind,
            'data' => PortableDescriptor::encode($data),
            'recorded_at' => now('UTC'),
        ]);
        $database->table(self::SCOPES)->where('id', $scope['id'])->update([
            'event_sequence' => $sequence,
        ]);
    }
}
