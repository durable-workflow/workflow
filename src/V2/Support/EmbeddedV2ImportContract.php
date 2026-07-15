<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

/**
 * Published contract for moving embedded v2 history into a standalone server.
 *
 * The import format deliberately reuses the v2 history-export bundle so the
 * source runtime remains the authority for durable history. Import writes a
 * transactional copy of that bundle into the target v2 store, then rebuilds
 * server projections from durable rows.
 *
 * @api Stable v2 server/operator contract surface.
 */
final class EmbeddedV2ImportContract
{
    public const SCHEMA = 'durable-workflow.v2.embedded-v2-import.contract';

    public const VERSION = 1;

    public const IMPORT_SOURCE = 'embedded_v2';

    public const ENGINE_SOURCE = 'embedded_v2_import';

    public const SOURCE_RUNTIME = 'embedded';

    public const REPORT_SCHEMA = 'durable-workflow.v2.embedded-v2-import.report';

    public const REPORT_SCHEMA_VERSION = 1;

    /**
     * @return array<string, mixed>
     */
    public static function manifest(): array
    {
        return [
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'history_export' => [
                'schema' => HistoryExport::SCHEMA,
                'schema_version' => HistoryExport::SCHEMA_VERSION,
                'command' => 'workflow:v2:history-export',
                'server_endpoint' => 'GET /api/workflows/{workflowId}/runs/{runId}/history/export',
            ],
            'import' => [
                'report_schema' => self::REPORT_SCHEMA,
                'report_schema_version' => self::REPORT_SCHEMA_VERSION,
                'command' => 'workflow:v2:history-import {bundle}',
                'server_endpoint' => 'POST /api/workflows/import/embedded-v2',
                'dry_run_option' => '--dry-run',
                'namespace_override_option' => '--namespace',
                'signature_option' => '--require-signature',
                'idempotency' => 'workflow_run_id plus import_dedupe_key',
            ],
            'eligibility' => [
                'supported_source_runtimes' => [self::SOURCE_RUNTIME],
                'required_bundle_schema' => HistoryExport::SCHEMA,
                'required_bundle_schema_version' => HistoryExport::SCHEMA_VERSION,
                'required_bundle_field' => 'workflow.source_runtime=embedded',
                'v1_history' => 'out_of_scope',
                'live_run_rules' => [
                    'non_terminal_runs_must_be_current' => true,
                    'leased_workflow_or_activity_tasks' => 'rejected',
                    'running_activity_attempts' => 'rejected',
                    'redacted_payloads' => 'rejected',
                ],
                'terminal_run_rules' => [
                    'history_complete_must_be_true' => true,
                    'may_import_non_current_runs_for_visibility' => true,
                ],
            ],
            'source_of_truth' => [
                'history_events' => 'authoritative replay and audit log copied exactly from the embedded v2 bundle',
                'workflow_and_payload_rows' => 'copied from the bundle envelope and payload sections',
                'tasks_activities_timers_commands_signals_updates' => 'reconstruction hints for currently open server work and operator visibility',
                'projections' => 'rebuilt on the server from imported durable rows after the transaction writes',
                'bundle_integrity' => 'BundleIntegrityVerifier decides whether the decoded bundle is structurally importable',
            ],
            'server_reconstruction' => [
                'current_run' => 'instance.current_run_id is set only when the imported run is current, or when no current run exists for a terminal visibility import',
                'workflow_tasks' => 'ready workflow tasks remain claimable by compatible server workers; leased tasks are not importable',
                'activity_tasks' => 'pending activity executions and ready activity tasks remain claimable; running attempts are not importable',
                'waits' => 'wait projections are rebuilt from typed history plus imported task/activity/timer rows',
                'timers' => 'pending timers are copied with their fire_at timestamp and are visible to timer repair/recovery',
                'lineage' => 'parent and child links from the bundle are copied as diagnostic lineage edges when identifiers are present',
            ],
            'failure_and_rollback' => [
                'transaction' => 'all durable rows and projections are written in one database transaction',
                'partial_or_interrupted_import' => 'no imported rows are committed when the transaction rolls back',
                'retry_same_bundle' => 'returns already_imported when run_id and import_dedupe_key match',
                'conflicting_existing_run' => 'rejected before writing any rows',
            ],
            'visibility_and_audit' => [
                'workflow_run_columns' => [
                    'import_source',
                    'import_id',
                    'import_dedupe_key',
                    'import_contract_version',
                    'imported_at',
                ],
                'summary_engine_source' => self::ENGINE_SOURCE,
                'detail_fields' => ['engine_source', 'import_source', 'import_id', 'import_dedupe_key', 'imported_at'],
            ],
        ];
    }
}
