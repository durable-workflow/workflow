<?php

declare(strict_types=1);

namespace Workflow\Support;

use Illuminate\Database\Migrations\Migration;

abstract class WorkflowMigration extends Migration
{
    public function getConnection(): ?string
    {
        return config('workflows.storage.connection') ?? $this->connection;
    }
}
