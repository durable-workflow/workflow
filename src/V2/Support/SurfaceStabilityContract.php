<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

/**
 * Canonical, machine-readable mirror of the platform-wide compatibility
 * and release-authority contract published at
 * https://durable-workflow.github.io/docs/2.0/compatibility.
 *
 * The doc page is the human-readable authority. This class is the same
 * contract in machine-readable form so that server endpoints, CI gates,
 * and per-package stability docs can validate themselves against one
 * source of truth instead of inferring stability from scattered prose
 * or package metadata.
 *
 * Adding a surface family, changing its stability level, changing the
 * patch/minor/major rules, or changing the diagnostic-vs-guaranteed
 * field rule is a contract change. Bump VERSION and align the doc page
 * in the same change. Removing a surface family is a major change.
 *
 * @api Stable class surface consumed by the standalone workflow-server,
 * which re-exports the manifest from `GET /api/cluster/info` under the
 * `surface_stability_contract` key.
 */
final class SurfaceStabilityContract
{
    public const SCHEMA = 'durable-workflow.v2.surface-stability.contract';

    public const VERSION = 1;

    public const AUTHORITY_URL = 'https://durable-workflow.github.io/docs/2.0/compatibility';

    public const STABILITY_FROZEN = 'frozen';

    public const STABILITY_STABLE = 'stable';

    public const STABILITY_PRERELEASE = 'prerelease';

    public const STABILITY_EXPERIMENTAL = 'experimental';

    public const STABILITY_LEVELS = [
        self::STABILITY_FROZEN,
        self::STABILITY_STABLE,
        self::STABILITY_PRERELEASE,
        self::STABILITY_EXPERIMENTAL,
    ];

    /**
     * @return array<string, mixed>
     */
    public static function manifest(): array
    {
        return [
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'authority_url' => self::AUTHORITY_URL,
            'stability_levels' => self::stabilityLevels(),
            'release_rules' => self::releaseRules(),
            'field_visibility_rule' => self::fieldVisibilityRule(),
            'surface_families' => self::surfaceFamilies(),
            'release_check' => self::releaseCheck(),
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function stabilityLevelValues(): array
    {
        return self::STABILITY_LEVELS;
    }

    /**
     * @return array<string, array<string, string>>
     */
    private static function stabilityLevels(): array
    {
        return [
            self::STABILITY_FROZEN => [
                'meaning' => 'Wire-format or persisted shape that must decode the same way for the workflow lifetime. Renaming, removing, or repurposing a field is a protocol break, never a minor change. Treat any new shape as a parallel primitive with a new type name.',
                'breaking_change_release' => 'parallel_primitive_only',
            ],
            self::STABILITY_STABLE => [
                'meaning' => 'Public surface covered by the platform semver guarantee. Additive changes ship in minor releases. Removing, renaming, or narrowing the surface requires a major version.',
                'breaking_change_release' => 'major',
            ],
            self::STABILITY_PRERELEASE => [
                'meaning' => 'Public surface that is feature-complete but still allowed to change before the matching `1.0.0` / `2.0.0` cut. Breaking changes ship in clearly labelled prerelease versions and are called out in the version-history page.',
                'breaking_change_release' => 'prerelease_minor',
            ],
            self::STABILITY_EXPERIMENTAL => [
                'meaning' => 'Public-but-unstable surface. May change in any release, including patch releases. Callers must opt in by reading the experimental flag on the surface; release notes call out breaking changes.',
                'breaking_change_release' => 'any',
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function releaseRules(): array
    {
        return [
            'patch' => [
                'allowed_changes' => [
                    'bug fixes that preserve the documented contract',
                    'documentation fixes',
                    'dependency bumps that do not change the public surface',
                    'changes to surfaces marked `experimental`',
                ],
                'forbidden_changes' => [
                    'removing or renaming any `stable` or `frozen` field, route, command, or class',
                    'narrowing accepted input on any `stable` route or command',
                    'changing the meaning of an existing `stable` field',
                ],
            ],
            'minor' => [
                'allowed_changes' => [
                    'adding new fields, routes, commands, or classes to a `stable` surface',
                    'adding new optional parameters with safe defaults',
                    'adding new capability flags to discovery responses',
                    'promoting a `prerelease` or `experimental` surface to `stable`',
                ],
                'forbidden_changes' => [
                    'removing or renaming any `stable` or `frozen` field, route, command, or class',
                    'changing the meaning of an existing `stable` or `frozen` field',
                ],
            ],
            'major' => [
                'allowed_changes' => [
                    'removing, renaming, or narrowing a `stable` surface',
                    'increasing the required `control_plane.version` or `worker_protocol.version`',
                    'dropping a previously supported SDK or CLI version range',
                ],
                'requirements' => [
                    'announce in the version-history page at least one minor release before cutting the major',
                    'add the new surface alongside the old surface in a previous minor where feasible',
                    'document the migration path on the migration guide before publish',
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function fieldVisibilityRule(): array
    {
        return [
            'guaranteed_fields' => [
                'definition' => 'Fields that are part of the documented contract for a `stable` or `frozen` surface. Producers must keep emitting them in the documented shape; consumers may rely on their presence and meaning.',
                'change_rule' => 'Changing a guaranteed field follows the surface\'s stability level. Removing or renaming a guaranteed field on a `stable` surface is a major change.',
            ],
            'diagnostic_only_fields' => [
                'definition' => 'Fields that are emitted for human triage, debugging, and observability. They are not part of the documented contract and consumers must not gate behavior on them.',
                'change_rule' => 'May be added, renamed, or removed in any minor release. They must be marked `diagnostic_only: true` (or the doc-page equivalent) wherever they are documented.',
                'consumer_obligation' => 'Treat unknown diagnostic fields as additive. Do not parse, persist, or branch on diagnostic fields in production decision logic.',
            ],
            'unknown_field_policy' => 'Unknown additive fields on a `stable` or `frozen` shape must be ignored by older consumers. Unknown required fields must fail closed.',
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function surfaceFamilies(): array
    {
        return [
            'server_api' => [
                'description' => 'Standalone Durable Workflow server HTTP API: control-plane routes, namespace routes, schedule routes, system routes, and the discovery endpoints `/api/health`, `/api/ready`, `/api/cluster/info`.',
                'stability_level' => self::STABILITY_STABLE,
                'authority_manifest' => 'control_plane',
                'requires_protocol_header' => 'X-Durable-Workflow-Control-Plane-Version',
                'breaking_change_release' => 'major',
                'notes' => 'Per-route version is governed by the `control_plane.request_contract` and `control_plane.response.contract` schema/version pairs published from `/api/cluster/info`. The top-level server `version` is build identity, not the client compatibility authority.',
            ],
            'worker_protocol' => [
                'description' => 'Worker-plane HTTP API used by external SDK workers to register, poll, heartbeat, complete, and fail workflow and activity tasks.',
                'stability_level' => self::STABILITY_STABLE,
                'authority_manifest' => 'worker_protocol',
                'requires_protocol_header' => 'X-Durable-Workflow-Protocol-Version',
                'breaking_change_release' => 'major',
                'notes' => 'Includes the `external_execution_surface_contract`, `external_executor_config_contract`, `invocable_carrier_contract`, `external_task_input_contract`, and `external_task_result_contract` published from `/api/cluster/info`. Each nested contract carries its own schema/version and may evolve independently per its own contract rules.',
            ],
            'cli_json' => [
                'description' => 'The `--output=json` and `--output=jsonl` shapes emitted by the `dw` CLI. This is the contract that automation, agents, and operator scripts depend on.',
                'stability_level' => self::STABILITY_STABLE,
                'authority_manifest' => 'cli_reference',
                'breaking_change_release' => 'major',
                'notes' => 'The human-readable `--output=table` form is documentation, not contract. JSON exit codes and JSON field names are the durable surface.',
            ],
            'waterline_api' => [
                'description' => 'Waterline observability HTTP API at `/waterline/api/v2/*`, the engine-source contract, and the Waterline operator dashboard JSON shapes.',
                'stability_level' => self::STABILITY_STABLE,
                'authority_manifest' => 'waterline_operator_api',
                'breaking_change_release' => 'major',
                'notes' => 'Waterline must match the workflow package major version. See the Waterline ↔ Workflow compatibility matrix.',
            ],
            'mcp_discovery_results' => [
                'description' => 'The Model Context Protocol surfaces under `/mcp/*` and the `llms.txt` / `llms-2.0.txt` discovery files used by AI clients to find Durable surfaces.',
                'stability_level' => self::STABILITY_STABLE,
                'authority_manifest' => 'mcp_workflows',
                'breaking_change_release' => 'major',
                'notes' => 'MCP tool names, parameter schemas, and `payload_preview_limit_bytes` semantics are part of the contract. Tool descriptions and discovery hints are diagnostic.',
            ],
            'official_sdks' => [
                'description' => 'The first-party SDKs distributed by the project: PHP `durable-workflow/workflow` (workflow authoring + embedded host), `durable-workflow/server`, `dw` CLI, and `durable_workflow` Python SDK.',
                'stability_level' => self::STABILITY_STABLE,
                'authority_manifest' => 'client_compatibility',
                'breaking_change_release' => 'major',
                'notes' => 'Each SDK\'s public surface is governed by its own per-package stability document. Per-package documents must defer to this contract; disagreements are bugs in the per-package document.',
                'per_package_contracts' => [
                    'php_workflow_package' => 'docs/api-stability.md in `durable-workflow/workflow`',
                    'server' => 'README.md and docs/contracts/* in `durable-workflow/server`',
                    'cli' => 'docs/polyglot/cli-reference.md',
                    'python_sdk' => 'README.md in `durable-workflow/sdk-python`',
                ],
            ],
            'history_event_wire_formats' => [
                'description' => 'The persisted shape of every row in `workflow_history_events` and `workflow_schedule_history_events`. Once a workflow writes an event, every future SDK that replays that workflow must decode the same field set.',
                'stability_level' => self::STABILITY_FROZEN,
                'authority_manifest' => 'history_event_payload_contract',
                'breaking_change_release' => 'parallel_primitive_only',
                'notes' => 'Renaming, removing, or repurposing a field on any published event is a protocol break regardless of whether the producing PHP class is `@internal`. Treat any new shape as a parallel primitive with a new event type name. The frozen field tables in workflow `docs/api-stability.md` are the per-event source of truth.',
            ],
            'cluster_info_manifests' => [
                'description' => 'The protocol manifests published by `GET /api/cluster/info` itself: `client_compatibility`, `control_plane`, `worker_protocol`, `auth_composition_contract`, `coordination_health`, and `surface_stability_contract`.',
                'stability_level' => self::STABILITY_STABLE,
                'authority_manifest' => 'cluster_info',
                'breaking_change_release' => 'major',
                'notes' => 'Each nested manifest carries its own `schema` and `version` and evolves under its own contract rules. The envelope keys themselves are stable; renaming `client_compatibility`, `control_plane`, `worker_protocol`, or `surface_stability_contract` is a major change.',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function releaseCheck(): array
    {
        return [
            'description' => 'Release reviewers must confirm the compatibility-authority check before publish.',
            'gates' => [
                'docs_authority_aligned' => 'docs/compatibility.md (in durable-workflow.github.io) lists every surface family in this manifest with the same stability level.',
                'install_docs_aligned' => 'docs/installation.md and any package install snippets do not claim a stability level different from the one this manifest assigns to the relevant SDK.',
                'package_metadata_aligned' => 'composer.json / pyproject.toml / package.json prerelease tags match the `stability_level` for the SDK family they belong to.',
                'version_history_aligned' => 'The version-history table in docs/compatibility.md does not introduce stability claims that contradict this manifest.',
            ],
            'enforcement' => [
                'machine' => 'docs site CI runs scripts/check-compatibility-authority.js, which loads static/compatibility-contract.json (a copy of this manifest) and walks docs/compatibility.md, docs/installation.md, and the version-history table.',
                'human' => 'Release reviewers tick the compatibility-authority check on every release PR before tagging.',
            ],
        ];
    }
}
