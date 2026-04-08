<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class() extends Migration {
    private const PREVIOUS_LENGTH = 26;

    private const INSTANCE_ID_LENGTH = 191;

    public function up(): void
    {
        $this->changeInstanceIdColumns(self::INSTANCE_ID_LENGTH);
    }

    public function down(): void
    {
        $this->changeInstanceIdColumns(self::PREVIOUS_LENGTH);
    }

    private function changeInstanceIdColumns(int $length): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            return;
        }

        $statements = match ($driver) {
            'mysql' => [
                sprintf('ALTER TABLE workflow_instances MODIFY id VARCHAR(%d) NOT NULL', $length),
                sprintf('ALTER TABLE workflow_runs MODIFY workflow_instance_id VARCHAR(%d) NOT NULL', $length),
                sprintf('ALTER TABLE workflow_run_summaries MODIFY workflow_instance_id VARCHAR(%d) NOT NULL', $length),
                sprintf('ALTER TABLE workflow_commands MODIFY workflow_instance_id VARCHAR(%d) NULL', $length),
                sprintf(
                    'ALTER TABLE workflow_links MODIFY parent_workflow_instance_id VARCHAR(%d) NOT NULL, MODIFY child_workflow_instance_id VARCHAR(%d) NOT NULL',
                    $length,
                    $length,
                ),
            ],
            'pgsql' => [
                sprintf('ALTER TABLE workflow_instances ALTER COLUMN id TYPE VARCHAR(%d)', $length),
                sprintf('ALTER TABLE workflow_runs ALTER COLUMN workflow_instance_id TYPE VARCHAR(%d)', $length),
                sprintf('ALTER TABLE workflow_run_summaries ALTER COLUMN workflow_instance_id TYPE VARCHAR(%d)', $length),
                sprintf('ALTER TABLE workflow_commands ALTER COLUMN workflow_instance_id TYPE VARCHAR(%d)', $length),
                sprintf('ALTER TABLE workflow_links ALTER COLUMN parent_workflow_instance_id TYPE VARCHAR(%d)', $length),
                sprintf('ALTER TABLE workflow_links ALTER COLUMN child_workflow_instance_id TYPE VARCHAR(%d)', $length),
            ],
            'sqlsrv' => [
                sprintf('ALTER TABLE workflow_instances ALTER COLUMN id NVARCHAR(%d) NOT NULL', $length),
                sprintf('ALTER TABLE workflow_runs ALTER COLUMN workflow_instance_id NVARCHAR(%d) NOT NULL', $length),
                sprintf('ALTER TABLE workflow_run_summaries ALTER COLUMN workflow_instance_id NVARCHAR(%d) NOT NULL', $length),
                sprintf('ALTER TABLE workflow_commands ALTER COLUMN workflow_instance_id NVARCHAR(%d) NULL', $length),
                sprintf('ALTER TABLE workflow_links ALTER COLUMN parent_workflow_instance_id NVARCHAR(%d) NOT NULL', $length),
                sprintf('ALTER TABLE workflow_links ALTER COLUMN child_workflow_instance_id NVARCHAR(%d) NOT NULL', $length),
            ],
            default => [],
        };

        foreach ($statements as $statement) {
            DB::statement($statement);
        }
    }
};
