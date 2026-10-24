<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Workflow\Support\WorkflowMigration;

return new class() extends WorkflowMigration {
    public $withinTransaction = false;

    public function up(): void
    {
        $this->withMetadataLockBudget(static function (): void {
            if (Schema::hasColumn('workflow_failures', 'message_full')) {
                return;
            }

            $connection = Schema::getConnection();
            if (in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
                $table = $connection->getQueryGrammar()
                    ->wrapTable('workflow_failures');
                // Never silently copy a live failure table during bootstrap.
                $connection->statement(
                    "ALTER TABLE {$table} ADD COLUMN message_full LONGTEXT NULL, ALGORITHM=INSTANT",
                );

                return;
            }

            Schema::table('workflow_failures', static function (Blueprint $table): void {
                $table->longText('message_full')
                    ->nullable();
            });
        });
    }

    public function down(): void
    {
        // Retain the nullable expansion on package rollback. Checking for
        // empty storage before dropping it would race with active writers.
        // Older code can ignore this column; removing it could destroy evidence.
    }

    /**
     * @param callable(): void $operation
     */
    private function withMetadataLockBudget(callable $operation): void
    {
        $connection = Schema::getConnection();
        $driver = $connection->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $settings = (array) $connection->selectOne('SELECT @@SESSION.lock_wait_timeout AS lock_wait_seconds');
            $previous = (int) $settings['lock_wait_seconds'];
            $connection->statement('SET SESSION lock_wait_timeout = 1');
            try {
                $operation();
            } finally {
                $connection->statement('SET SESSION lock_wait_timeout = ' . $previous);
            }

            return;
        }

        if ($driver === 'pgsql') {
            $settings = (array) $connection->selectOne('SHOW lock_timeout');
            $connection->selectOne("SELECT set_config('lock_timeout', '1s', false)");
            try {
                $operation();
            } finally {
                $connection->selectOne("SELECT set_config('lock_timeout', ?, false)", [$settings['lock_timeout']]);
            }

            return;
        }

        $operation();
    }
};
