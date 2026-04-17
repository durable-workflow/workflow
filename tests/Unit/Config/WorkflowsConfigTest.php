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
            'serializer' => 'avro',
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

    /**
     * A fresh installation must be able to boot with no v2 environment
     * variables set. The compatibility markers and history-export signing
     * keys default to null ("no marker required" / "unsigned"), the namespace
     * defaults to null ("no namespace isolation"), and every other v2 key
     * ships with a working fallback.
     */
    public function testV2SectionBootsWithoutEnvironmentOverrides(): void
    {
        $envKeys = [
            'WORKFLOW_V2_NAMESPACE',
            'WORKFLOW_V2_CURRENT_COMPATIBILITY',
            'WORKFLOW_V2_SUPPORTED_COMPATIBILITIES',
            'WORKFLOW_V2_COMPATIBILITY_NAMESPACE',
            'WORKFLOW_V2_HISTORY_EXPORT_SIGNING_KEY',
            'WORKFLOW_V2_HISTORY_EXPORT_SIGNING_KEY_ID',
        ];

        $previous = [];

        foreach ($envKeys as $key) {
            $previous[$key] = getenv($key) === false ? null : getenv($key);
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }

        try {
            $config = require dirname(__DIR__, 3) . '/src/config/workflows.php';

            $this->assertNull($config['v2']['namespace']);
            $this->assertNull($config['v2']['compatibility']['current']);
            $this->assertNull($config['v2']['compatibility']['supported']);
            $this->assertNull($config['v2']['compatibility']['namespace']);
            $this->assertNull($config['v2']['history_export']['signing_key']);
            $this->assertNull($config['v2']['history_export']['signing_key_id']);

            // Defaults that must still be active regardless of env vars.
            $this->assertSame(30, $config['v2']['compatibility']['heartbeat_ttl_seconds']);
            $this->assertSame('queue', $config['v2']['task_dispatch_mode']);
            $this->assertSame('warn', $config['v2']['guardrails']['boot']);
        } finally {
            foreach ($previous as $key => $value) {
                if ($value === null) {
                    putenv($key);
                    unset($_ENV[$key], $_SERVER[$key]);
                } else {
                    putenv(sprintf('%s=%s', $key, $value));
                    $_ENV[$key] = $value;
                    $_SERVER[$key] = $value;
                }
            }
        }
    }
}
