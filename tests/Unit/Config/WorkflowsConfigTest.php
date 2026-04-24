<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use Tests\TestCase;

final class WorkflowsConfigTest extends TestCase
{
    /**
     * Environment names that can influence config defaults in tests.
     * This includes retired names we explicitly clear so ambient alpha-era
     * exports do not leak into assertions that are meant to prove the final
     * released contract.
     *
     * @var list<string>
     */
    private const CONFIG_ENV_KEYS = [
        'DW_V2_NAMESPACE', 'WORKFLOW_V2_NAMESPACE',
        'DW_V2_CURRENT_COMPATIBILITY', 'WORKFLOW_V2_CURRENT_COMPATIBILITY',
        'DW_V2_SUPPORTED_COMPATIBILITIES', 'WORKFLOW_V2_SUPPORTED_COMPATIBILITIES',
        'DW_V2_COMPATIBILITY_NAMESPACE', 'WORKFLOW_V2_COMPATIBILITY_NAMESPACE',
        'DW_V2_COMPATIBILITY_HEARTBEAT_TTL', 'WORKFLOW_V2_COMPATIBILITY_HEARTBEAT_TTL',
        'DW_V2_PIN_TO_RECORDED_FINGERPRINT', 'WORKFLOW_V2_PIN_TO_RECORDED_FINGERPRINT',
        'DW_V2_CONTINUE_AS_NEW_EVENT_THRESHOLD', 'WORKFLOW_V2_CONTINUE_AS_NEW_EVENT_THRESHOLD',
        'DW_V2_CONTINUE_AS_NEW_SIZE_BYTES_THRESHOLD', 'WORKFLOW_V2_CONTINUE_AS_NEW_SIZE_BYTES_THRESHOLD',
        'DW_V2_HISTORY_EXPORT_SIGNING_KEY', 'WORKFLOW_V2_HISTORY_EXPORT_SIGNING_KEY',
        'DW_V2_HISTORY_EXPORT_SIGNING_KEY_ID', 'WORKFLOW_V2_HISTORY_EXPORT_SIGNING_KEY_ID',
        'DW_V2_UPDATE_WAIT_COMPLETION_TIMEOUT_SECONDS', 'WORKFLOW_V2_UPDATE_WAIT_COMPLETION_TIMEOUT_SECONDS',
        'DW_V2_UPDATE_WAIT_POLL_INTERVAL_MS', 'WORKFLOW_V2_UPDATE_WAIT_POLL_INTERVAL_MS',
        'DW_V2_GUARDRAILS_BOOT', 'WORKFLOW_V2_GUARDRAILS_BOOT',
        'DW_V2_LIMIT_PENDING_ACTIVITIES', 'WORKFLOW_V2_LIMIT_PENDING_ACTIVITIES',
        'DW_V2_LIMIT_PENDING_CHILDREN', 'WORKFLOW_V2_LIMIT_PENDING_CHILDREN',
        'DW_V2_LIMIT_PENDING_TIMERS', 'WORKFLOW_V2_LIMIT_PENDING_TIMERS',
        'DW_V2_LIMIT_PENDING_SIGNALS', 'WORKFLOW_V2_LIMIT_PENDING_SIGNALS',
        'DW_V2_LIMIT_PENDING_UPDATES', 'WORKFLOW_V2_LIMIT_PENDING_UPDATES',
        'DW_V2_LIMIT_COMMAND_BATCH_SIZE', 'WORKFLOW_V2_LIMIT_COMMAND_BATCH_SIZE',
        'DW_V2_LIMIT_PAYLOAD_SIZE_BYTES', 'WORKFLOW_V2_LIMIT_PAYLOAD_SIZE_BYTES',
        'DW_V2_LIMIT_MEMO_SIZE_BYTES', 'WORKFLOW_V2_LIMIT_MEMO_SIZE_BYTES',
        'DW_V2_LIMIT_SEARCH_ATTRIBUTE_SIZE_BYTES', 'WORKFLOW_V2_LIMIT_SEARCH_ATTRIBUTE_SIZE_BYTES',
        'DW_V2_LIMIT_HISTORY_TRANSACTION_SIZE', 'WORKFLOW_V2_LIMIT_HISTORY_TRANSACTION_SIZE',
        'DW_V2_LIMIT_WARNING_THRESHOLD_PERCENT', 'WORKFLOW_V2_LIMIT_WARNING_THRESHOLD_PERCENT',
        'DW_V2_TASK_DISPATCH_MODE', 'WORKFLOW_V2_TASK_DISPATCH_MODE',
        'DW_V2_TASK_REPAIR_REDISPATCH_AFTER_SECONDS', 'WORKFLOW_V2_TASK_REPAIR_REDISPATCH_AFTER_SECONDS',
        'DW_V2_TASK_REPAIR_LOOP_THROTTLE_SECONDS', 'WORKFLOW_V2_TASK_REPAIR_LOOP_THROTTLE_SECONDS',
        'DW_V2_TASK_REPAIR_SCAN_LIMIT', 'WORKFLOW_V2_TASK_REPAIR_SCAN_LIMIT',
        'DW_V2_TASK_REPAIR_FAILURE_BACKOFF_MAX_SECONDS', 'WORKFLOW_V2_TASK_REPAIR_FAILURE_BACKOFF_MAX_SECONDS',
        'DW_V2_MULTI_NODE', 'WORKFLOW_V2_MULTI_NODE',
        'DW_V2_VALIDATE_CACHE_BACKEND', 'WORKFLOW_V2_VALIDATE_CACHE_BACKEND',
        'DW_V2_CACHE_VALIDATION_MODE', 'WORKFLOW_V2_CACHE_VALIDATION_MODE',
        'DW_V2_FLEET_VALIDATION_MODE', 'WORKFLOW_V2_FLEET_VALIDATION_MODE',
        'DW_SERIALIZER', 'WORKFLOW_SERIALIZER',
        'DW_WEBHOOKS_ROUTE', 'WORKFLOW_WEBHOOKS_ROUTE',
        'DW_WEBHOOKS_AUTH_METHOD', 'WORKFLOW_WEBHOOKS_AUTH_METHOD',
        'DW_WEBHOOKS_SIGNATURE_HEADER', 'WORKFLOW_WEBHOOKS_SIGNATURE_HEADER',
        'DW_WEBHOOKS_SECRET', 'WORKFLOW_WEBHOOKS_SECRET',
        'DW_WEBHOOKS_TOKEN_HEADER', 'WORKFLOW_WEBHOOKS_TOKEN_HEADER',
        'DW_WEBHOOKS_TOKEN', 'WORKFLOW_WEBHOOKS_TOKEN',
        'DW_WEBHOOKS_CUSTOM_AUTH_CLASS', 'WORKFLOW_WEBHOOKS_CUSTOM_AUTH_CLASS',
    ];

    public function testConfigIsLoaded(): void
    {
        $previous = $this->snapshotEnv(['WORKFLOW_SERIALIZER', 'DW_SERIALIZER']);
        $this->clearEnv(['WORKFLOW_SERIALIZER', 'DW_SERIALIZER']);

        try {
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
                'webhooks_route' => 'webhooks',
            ];

            foreach ($expectedConfig as $key => $expectedValue) {
                $this->assertTrue(array_key_exists($key, $config), "The config key [workflows.{$key}] is missing.");

                $this->assertEquals(
                    $expectedValue,
                    $config[$key],
                    "The config key [workflows.{$key}] does not match the expected value."
                );
            }
        } finally {
            $this->restoreEnv($previous);
        }
    }

    /**
     * DW_SERIALIZER remains visible in config so workflow:v2:doctor can
     * flag stale v1/custom settings, even though final v2 new-run payloads
     * always resolve to Avro through CodecRegistry::defaultCodec().
     */
    public function testSerializerEnvironmentValueIsLoadedForDiagnostics(): void
    {
        $previous = $this->snapshotEnv(['WORKFLOW_SERIALIZER', 'DW_SERIALIZER']);
        $this->clearEnv(['WORKFLOW_SERIALIZER', 'DW_SERIALIZER']);

        putenv('DW_SERIALIZER=json');
        $_ENV['DW_SERIALIZER'] = 'json';
        $_SERVER['DW_SERIALIZER'] = 'json';

        try {
            $config = require dirname(__DIR__, 3) . '/src/config/workflows.php';

            $this->assertSame('json', $config['serializer']);
        } finally {
            $this->restoreEnv($previous);
        }
    }

    /**
     * The legacy WORKFLOW_SERIALIZER name still resolves during the
     * deprecation window so existing deployments keep working.
     */
    public function testSerializerLegacyEnvironmentValueStillResolves(): void
    {
        $previous = $this->snapshotEnv(['WORKFLOW_SERIALIZER', 'DW_SERIALIZER']);
        $this->clearEnv(['WORKFLOW_SERIALIZER', 'DW_SERIALIZER']);

        putenv('WORKFLOW_SERIALIZER=workflow-serializer-y');
        $_ENV['WORKFLOW_SERIALIZER'] = 'workflow-serializer-y';
        $_SERVER['WORKFLOW_SERIALIZER'] = 'workflow-serializer-y';

        try {
            $config = require dirname(__DIR__, 3) . '/src/config/workflows.php';

            $this->assertSame('workflow-serializer-y', $config['serializer']);
        } finally {
            $this->restoreEnv($previous);
        }
    }

    /**
     * A fresh installation must be able to boot with no v2 environment
     * variables set. The compatibility markers and history-export signing
     * keys default to null ("no marker required" / "unsigned"), the namespace
     * defaults to null ("no namespace isolation"), and the remaining v2 keys
     * keep their shipped defaults.
     */
    public function testV2SectionBootsWithoutEnvironmentOverrides(): void
    {
        $previous = $this->snapshotEnv(self::CONFIG_ENV_KEYS);
        $this->clearEnv(self::CONFIG_ENV_KEYS);

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
            $this->restoreEnv($previous);
        }
    }

    public function testGuardrailsBootReadsDwNameOnly(): void
    {
        $keys = ['DW_V2_GUARDRAILS_BOOT', 'WORKFLOW_V2_GUARDRAILS_BOOT'];
        $previous = $this->snapshotEnv($keys);
        $this->clearEnv($keys);

        putenv('DW_V2_GUARDRAILS_BOOT=throw');
        $_ENV['DW_V2_GUARDRAILS_BOOT'] = 'throw';
        $_SERVER['DW_V2_GUARDRAILS_BOOT'] = 'throw';

        putenv('WORKFLOW_V2_GUARDRAILS_BOOT=silent');
        $_ENV['WORKFLOW_V2_GUARDRAILS_BOOT'] = 'silent';
        $_SERVER['WORKFLOW_V2_GUARDRAILS_BOOT'] = 'silent';

        try {
            $config = require dirname(__DIR__, 3) . '/src/config/workflows.php';

            $this->assertSame('throw', $config['v2']['guardrails']['boot']);
        } finally {
            $this->restoreEnv($previous);
        }
    }

    public function testGuardrailsBootIgnoresLegacyWorkflowV2Name(): void
    {
        $keys = ['DW_V2_GUARDRAILS_BOOT', 'WORKFLOW_V2_GUARDRAILS_BOOT'];
        $previous = $this->snapshotEnv($keys);
        $this->clearEnv($keys);

        putenv('WORKFLOW_V2_GUARDRAILS_BOOT=throw');
        $_ENV['WORKFLOW_V2_GUARDRAILS_BOOT'] = 'throw';
        $_SERVER['WORKFLOW_V2_GUARDRAILS_BOOT'] = 'throw';

        try {
            $config = require dirname(__DIR__, 3) . '/src/config/workflows.php';

            $this->assertSame('warn', $config['v2']['guardrails']['boot']);
        } finally {
            $this->restoreEnv($previous);
        }
    }

    /**
     * Setting a DW_V2_* name takes effect even when the legacy
     * WORKFLOW_V2_* counterpart is also set — the DW_V2_* primary wins.
     */
    public function testV2PrimaryDwNameWinsOverLegacyAlias(): void
    {
        $keys = ['DW_V2_NAMESPACE', 'WORKFLOW_V2_NAMESPACE'];
        $previous = $this->snapshotEnv($keys);
        $this->clearEnv($keys);

        putenv('WORKFLOW_V2_NAMESPACE=legacy-tenant');
        $_ENV['WORKFLOW_V2_NAMESPACE'] = 'legacy-tenant';
        $_SERVER['WORKFLOW_V2_NAMESPACE'] = 'legacy-tenant';

        putenv('DW_V2_NAMESPACE=primary-tenant');
        $_ENV['DW_V2_NAMESPACE'] = 'primary-tenant';
        $_SERVER['DW_V2_NAMESPACE'] = 'primary-tenant';

        try {
            $config = require dirname(__DIR__, 3) . '/src/config/workflows.php';
            $this->assertSame('primary-tenant', $config['v2']['namespace']);
        } finally {
            $this->restoreEnv($previous);
        }
    }

    /**
     * The legacy WORKFLOW_V2_* name still resolves when the DW_V2_*
     * primary is not set, so existing deployments keep working during
     * the deprecation window.
     */
    public function testV2LegacyAliasStillResolvesWhenPrimaryIsUnset(): void
    {
        $keys = ['DW_V2_LIMIT_PAYLOAD_SIZE_BYTES', 'WORKFLOW_V2_LIMIT_PAYLOAD_SIZE_BYTES'];
        $previous = $this->snapshotEnv($keys);
        $this->clearEnv($keys);

        putenv('WORKFLOW_V2_LIMIT_PAYLOAD_SIZE_BYTES=4194304');
        $_ENV['WORKFLOW_V2_LIMIT_PAYLOAD_SIZE_BYTES'] = '4194304';
        $_SERVER['WORKFLOW_V2_LIMIT_PAYLOAD_SIZE_BYTES'] = '4194304';

        try {
            $config = require dirname(__DIR__, 3) . '/src/config/workflows.php';
            $this->assertSame(4194304, $config['v2']['structural_limits']['payload_size_bytes']);
        } finally {
            $this->restoreEnv($previous);
        }
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, string|null>
     */
    private function snapshotEnv(array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = getenv($key) === false ? null : getenv($key);
        }
        return $out;
    }

    /**
     * @param  list<string>  $keys
     */
    private function clearEnv(array $keys): void
    {
        foreach ($keys as $key) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }
    }

    /**
     * @param  array<string, string|null>  $snapshot
     */
    private function restoreEnv(array $snapshot): void
    {
        foreach ($snapshot as $key => $value) {
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
