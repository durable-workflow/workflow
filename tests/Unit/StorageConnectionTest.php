<?php

declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use Workflow\Models\StoredWorkflow;
use Workflow\V2\Models\WorkflowMessage;
use Workflow\V2\Models\WorkflowRun;

final class StorageConnectionTest extends TestCase
{
    public function testModelsResolveConfiguredStorageConnection(): void
    {
        config([
            'workflows.storage.connection' => 'secondary',
        ]);

        // StoredWorkflow (v1), WorkflowRun (v2 resolve-path) and WorkflowMessage
        // (v2 hardcoded-usage) all route through the same trait, so the
        // hardcoded and ConfiguredV2Models::resolve() call sites agree.
        $this->assertSame('secondary', (new StoredWorkflow())->getConnectionName());
        $this->assertSame('secondary', (new WorkflowRun())->getConnectionName());
        $this->assertSame('secondary', (new WorkflowMessage())->getConnectionName());
    }

    public function testModelsFallBackToDefaultConnectionWhenUnset(): void
    {
        config([
            'workflows.storage.connection' => null,
        ]);

        // null config preserves the historical behavior: the model reports its
        // own connection (null => the application's default connection).
        $this->assertNull((new StoredWorkflow())->getConnectionName());
        $this->assertNull((new WorkflowRun())->getConnectionName());
        $this->assertNull((new WorkflowMessage())->getConnectionName());
    }
}
