<?php

declare(strict_types=1);

use Workflow\Support\FenceColumn;
use Workflow\Support\WorkflowMigration;

return new class() extends WorkflowMigration {
    use FenceColumn;

    protected const COLUMN = 'native_root_reservation';

    protected const REFUSAL = 'Native root reservation fences cannot be discarded by a schema rollback.';

    public $withinTransaction = false;
};
