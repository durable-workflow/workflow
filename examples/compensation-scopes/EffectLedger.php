<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\CompensationScopes;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Blueprint;

/**
 * A tiny provider stand-in: one row per operation ID, so every call is idempotent by that ID.
 */
final readonly class EffectLedger
{
    public function __construct(
        private ConnectionInterface $db
    ) {
    }

    public static function install(ConnectionInterface $db): void
    {
        $db->getSchemaBuilder()
            ->create('example_compensation_effects', static function (Blueprint $table): void {
                $table->string('operation_id', 64)
                    ->primary();
                $table->string('action');
                $table->string('status');
                $table->unsignedInteger('undo_sequence')
                    ->nullable();
            });
    }

    public static function uninstall(ConnectionInterface $db): void
    {
        $db->getSchemaBuilder()
            ->dropIfExists('example_compensation_effects');
    }

    /**
     * Apply the effect once; a retry or lost acknowledgement finds the same row.
     */
    public function apply(string $operationId, string $action): string
    {
        $this->db->table('example_compensation_effects')
            ->insertOrIgnore([
                'operation_id' => $operationId,
                'action' => $action,
                'status' => 'active',
            ]);
        return (string) $this->db->table('example_compensation_effects')
            ->where('operation_id', $operationId)
            ->value('status');
    }

    /**
     * Undo once. An operation that never took effect is recorded as absent, which is also undone.
     */
    public function undo(string $operationId, string $action): string
    {
        return $this->db->transaction(function () use ($operationId, $action): string {
            $row = $this->db->table('example_compensation_effects')
                ->where('operation_id', $operationId)
                ->lockForUpdate()
                ->first();
            if ($row === null) {
                $this->db->table('example_compensation_effects')
                    ->insert([
                        'operation_id' => $operationId,
                        'action' => $action,
                        'status' => 'absent',
                    ]);
                return 'absent';
            }
            if ($row->status === 'active') {
                $next = (int) $this->db->table('example_compensation_effects')
                    ->max('undo_sequence') + 1;
                $this->db->table('example_compensation_effects')
                    ->where('operation_id', $operationId)
                    ->update([
                        'status' => 'voided',
                        'undo_sequence' => $next,
                    ]);
                return 'voided';
            }
            return $row->status;
        });
    }

    /**
     * @return list<string> actions in the order they were undone
     */
    public function undoOrder(): array
    {
        return $this->db->table('example_compensation_effects')
            ->whereNotNull('undo_sequence')
            ->orderBy('undo_sequence')
            ->pluck('action')->all();
    }
}
