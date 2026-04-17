<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use Tests\TestCase;

final class WorkflowsConfigTest extends TestCase
{
    public function testConfigIsLoaded(): void
    {
        $config = require dirname(__DIR__, 3) . '/src/config/workflows.php';

        $this->assertNotEmpty($config, 'The workflows config file is not loaded.');

        $expectedConfig = [
            'workflows_folder' => 'Workflows',
            'stored_workflow_model' => \Workflow\Models\StoredWorkflow::class,
            'stored_workflow_exception_model' => \Workflow\Models\StoredWorkflowException::class,
            'stored_workflow_log_model' => \Workflow\Models\StoredWorkflowLog::class,
            'stored_workflow_signal_model' => \Workflow\Models\StoredWorkflowSignal::class,
            'stored_workflow_timer_model' => \Workflow\Models\StoredWorkflowTimer::class,
            'workflow_relationships_table' => 'workflow_relationships',
            'serializer' => 'json',
            'prune_age' => '1 month',
            'webhooks_route' => env('WORKFLOW_WEBHOOKS_ROUTE', 'webhooks'),
        ];

        foreach ($expectedConfig as $key => $expectedValue) {
            $this->assertTrue(array_key_exists($key, $config), "The config key [workflows.{$key}] is missing.");

            $this->assertEquals(
                $expectedValue,
                $config[$key],
                "The config key [workflows.{$key}] does not match the expected value."
            );
        }
    }
}
