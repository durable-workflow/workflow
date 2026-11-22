<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Workflow\Support\WorkflowMigration;
use Workflow\V2\Models\WorkflowRunWait;
use Workflow\V2\Support\ConfiguredV2Models;

return new class() extends WorkflowMigration {
    public $withinTransaction = false;

    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());

        foreach ($this->tables() as $table) {
            if ($schema->hasTable($table)) {
                $this->createIndexOnline($table, $this->index($table), [
                    'workflow_run_id', 'kind', 'status', 'sequence',
                ]);
            }
        }
    }

    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());

        foreach ($this->tables() as $table) {
            $index = $this->index($table);
            if ($schema->hasTable($table) && $schema->hasIndex($table, $index)) {
                $this->withSchemaLockBudget(static fn () => $schema->table($table, static function (Blueprint $table) use (
                    $index
                ): void {
                    $table->dropIndex($index);
                }));
            }
        }
    }

    /**
     * @return list<string>
     */
    private function tables(): array
    {
        $configured = ConfiguredV2Models::resolve('run_wait_model', WorkflowRunWait::class);

        return array_values(array_unique([(new WorkflowRunWait())->getTable(), (new $configured())->getTable()]));
    }

    private function index(string $table): string
    {
        $prefix = DB::connection($this->getConnection())->getTablePrefix();

        return 'dw_run_wait_sequence_' . substr(hash('sha256', $prefix . $table), 0, 12);
    }
};
