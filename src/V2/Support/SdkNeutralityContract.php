<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

/**
 * Canonical, machine-readable mirror of the platform-wide SDK
 * neutrality contract.
 *
 * The platform ships a deliberately narrow set of first-party SDKs
 * (PHP `durable-workflow/workflow`, Python `durable_workflow`, and Rust
 * `durable-workflow`).
 * Building or maintaining additional first-party SDKs is not a release
 * goal. But the public contracts that those SDKs sit on top of must not
 * quietly hard-code language-specific assumptions, because doing
 * so would make a future TypeScript, Go, Java, or .NET SDK impossible
 * without redesigning the protocol.
 *
 * This contract enumerates the minimum neutrality rules every public
 * contract must satisfy and the standing audit checklist that every new
 * server, workflow, CLI, Waterline, or MCP surface must clear before
 * release. It is downstream of `SurfaceStabilityContract` (which says
 * *which* surfaces are public and *how* they may change) and of
 * `PlatformProtocolSpecs` (which says *where* the normative spec for
 * each surface lives). This contract says *what shape* those specs are
 * allowed to take so that a future SDK outside the current PHP, Python,
 * and Rust roster can target them without requiring a protocol redesign.
 *
 * The standalone `workflow-server` re-exports this manifest from
 * `GET /api/cluster/info` under `sdk_neutrality_contract`.
 *
 * Adding a neutrality rule, tightening an existing rule, adding a
 * required audit step, adding a surface family to the audit scope, or
 * changing the official-SDK breadth policy is a contract change. Bump
 * VERSION and align the architecture doc, the static JSON mirror, and
 * the per-package stability documents in the same change. Removing a
 * neutrality rule or audit step is a major change.
 *
 * @api Stable class surface consumed by the standalone workflow-server.
 */
final class SdkNeutralityContract
{
    public const SCHEMA = 'durable-workflow.v2.sdk-neutrality.contract';

    public const VERSION = 2;

    public const AUTHORITY_DOC = 'https://github.com/durable-workflow/workflow/blob/v2/docs/architecture/sdk-neutrality.md';

    public const POSTURE_PRIORITY = 'priority';

    public const POSTURE_DEMAND_DRIVEN = 'demand_driven';

    public const POSTURE_OUT_OF_SCOPE = 'out_of_scope';

    public const POSTURES = [
        self::POSTURE_PRIORITY,
        self::POSTURE_DEMAND_DRIVEN,
        self::POSTURE_OUT_OF_SCOPE,
    ];

    public const RULE_PROTOCOL = 'protocol_neutrality';

    public const RULE_CODEC = 'codec_neutrality';

    public const RULE_ERROR_SHAPE = 'error_shape_neutrality';

    public const RULE_TYPE_IDENTITY = 'type_identity_neutrality';

    public const RULE_REPLAY_FIXTURE = 'replay_fixture_neutrality';

    public const RULE_DISCOVERY = 'discovery_neutrality';

    public const RULE_DOCUMENTATION = 'documentation_neutrality';

    public const RULES = [
        self::RULE_PROTOCOL,
        self::RULE_CODEC,
        self::RULE_ERROR_SHAPE,
        self::RULE_TYPE_IDENTITY,
        self::RULE_REPLAY_FIXTURE,
        self::RULE_DISCOVERY,
        self::RULE_DOCUMENTATION,
    ];

    /**
     * @return array<string, mixed>
     */
    public static function manifest(): array
    {
        return [
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'authority_doc' => self::AUTHORITY_DOC,
            'surface_stability_authority' => SurfaceStabilityContract::SCHEMA,
            'protocol_specs_authority' => PlatformProtocolSpecs::SCHEMA,
            'conformance_suite_authority' => PlatformConformanceSuite::SCHEMA,
            'scope' => self::scope(),
            'sdk_breadth_policy' => self::sdkBreadthPolicy(),
            'neutrality_rules' => self::neutralityRules(),
            'audit_checklist' => self::auditChecklist(),
            'audit_scope_surface_families' => self::auditScopeSurfaceFamilies(),
            'release_gates' => self::releaseGates(),
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function ruleNames(): array
    {
        return self::RULES;
    }

    /**
     * @return array<int, string>
     */
    public static function postureValues(): array
    {
        return self::POSTURES;
    }

    /**
     * @return array<string, string>
     */
    private static function scope(): array
    {
        return [
            'goal' => 'Preserve protocol and contract neutrality so a future TypeScript, Go, Java, or .NET SDK does not require a protocol redesign to exist.',
            'non_goal' => 'Ship a broad official SDK portfolio. First-party SDK breadth is intentionally narrow and grows only when adoption demand justifies it.',
            'present_priority' => 'Python is the current highest-value non-PHP path for existing users and is treated as a priority surface for parity coverage.',
            'future_posture' => 'TypeScript, Go, Java, .NET and other languages are demand-driven. They have no reserved release slot, but every public contract must be shaped so a future SDK in those languages can be written without breaking the wire protocol.',
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function sdkBreadthPolicy(): array
    {
        return [
            'description' => 'The official-SDK roster is intentionally narrow. Each entry below names a posture against this contract; new languages are added only when adoption demand justifies the maintenance commitment.',
            'first_party' => [
                'php_workflow_package' => [
                    'package' => 'durable-workflow/workflow',
                    'language' => 'php',
                    'posture' => self::POSTURE_PRIORITY,
                    'role' => 'Reference workflow authoring SDK and embedded host. Workflow authoring semantics are validated against this SDK first.',
                ],
                'python_sdk' => [
                    'package' => 'durable_workflow',
                    'language' => 'python',
                    'posture' => self::POSTURE_PRIORITY,
                    'role' => 'Highest-value non-PHP SDK. Used to validate that the worker protocol, control plane, and replay fixtures behave the same way outside PHP.',
                ],
                'rust_sdk' => [
                    'package' => 'durable-workflow',
                    'language' => 'rust',
                    'posture' => self::POSTURE_PRIORITY,
                    'role' => 'First-party deterministic workflow, activity, worker-service, and control-plane SDK. Used to validate replay, lifecycle, and codec interoperability outside PHP and Python.',
                ],
            ],
            'demand_driven' => [
                'typescript_sdk' => [
                    'language' => 'typescript',
                    'posture' => self::POSTURE_DEMAND_DRIVEN,
                    'note' => 'No first-party SDK exists. Public contracts must remain implementable in TypeScript without protocol redesign.',
                ],
                'go_sdk' => [
                    'language' => 'go',
                    'posture' => self::POSTURE_DEMAND_DRIVEN,
                    'note' => 'No first-party SDK exists. Public contracts must remain implementable in Go without protocol redesign.',
                ],
                'java_sdk' => [
                    'language' => 'java',
                    'posture' => self::POSTURE_DEMAND_DRIVEN,
                    'note' => 'No first-party SDK exists. Public contracts must remain implementable in Java without protocol redesign.',
                ],
                'dotnet_sdk' => [
                    'language' => 'dotnet',
                    'posture' => self::POSTURE_DEMAND_DRIVEN,
                    'note' => 'No first-party SDK exists. Public contracts must remain implementable in .NET without protocol redesign.',
                ],
            ],
            'expansion_criteria' => [
                'adoption_signal' => 'A new first-party SDK is considered only when there is documented user demand the existing SDKs cannot serve.',
                'maintenance_commitment' => 'A candidate language must have an owner team willing to keep it on the conformance harness, the platform protocol-spec catalog, and the release authority manifest.',
                'no_protocol_redesign' => 'Adding a new SDK must not require breaking changes to the worker protocol, control plane, history event wire formats, or replay-fixture shapes. If it would, the protocol is the bug, not the SDK.',
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function neutralityRules(): array
    {
        return [
            self::RULE_PROTOCOL => [
                'requirement' => 'Public RPC and event surfaces use HTTP+JSON or AsyncAPI shapes that any HTTP-capable runtime can produce and consume. No language-specific RPC framing, no PHP-only or Python-only headers, and no transport that requires an existing first-party SDK to be parseable.',
                'rationale' => 'A future TypeScript, Go, Java, or .NET SDK must be able to drive the worker protocol and control plane using only the language\'s standard HTTP and JSON tools.',
                'authority' => 'PlatformProtocolSpecs entries with format=openapi or format=asyncapi.',
                'how_to_apply' => 'Every public route documented in the protocol-spec catalog must validate against the published OpenAPI document. Any field that requires a first-party serialization library is either internal or covered by an explicit codec entry under `codec_neutrality`.',
            ],
            self::RULE_CODEC => [
                'requirement' => 'Every payload that crosses a public boundary advertises a codec name. At least one universal codec (a codec that any language can implement using only schema tooling, with no language-specific serializer dependency) is always offered alongside any engine-specific codec.',
                'rationale' => 'PHP-specific codecs (`workflow-serializer-y`, `workflow-serializer-base64`) and Python `pickle`-style codecs cannot be safely consumed across languages. Universal codecs (Avro, raw JSON) are reachable from any language.',
                'authority' => 'Workflow\\Serializers\\CodecRegistry::universal() and the worker_protocol cluster_info manifest.',
                'how_to_apply' => 'Worker protocol negotiation must always include a universal codec in the advertised set. New persisted payload shapes that require a custom codec must register the codec name and ship a JSON Schema or Avro schema alongside the serializer.',
            ],
            self::RULE_ERROR_SHAPE => [
                'requirement' => 'Public failure objects use a structured envelope of (`code`, `message`, optional `details`) where `code` is a stable string identifier. Language-specific exception class names, stack traces, or framework-internal error types are diagnostic only and must not be required for consumers to branch on.',
                'rationale' => 'A non-PHP, non-Python SDK has no way to map a PHP `Throwable` FQCN or a Python exception class to a meaningful failure category. Stable string codes allow every SDK to translate the failure into the language\'s native error type.',
                'authority' => 'docs/contracts/external-task-result.md (server) and the failure_repair_actionability fixture category.',
                'how_to_apply' => 'New public failure shapes must declare the `code` vocabulary and mark exception class names, file paths, and line numbers as diagnostic-only under the field-visibility rule.',
            ],
            self::RULE_TYPE_IDENTITY => [
                'requirement' => 'Workflow types, activity types, child workflow types, and exception types are identified by stable string names in every public contract. PHP class fully-qualified names and Python module paths are accepted as inputs from their respective SDKs but are normalized to a string identity before crossing a public surface.',
                'rationale' => 'A TypeScript SDK has no PHP autoloader and no Python module path. Type identity must be portable across runtimes; class names are an SDK-specific convenience, not a contract.',
                'authority' => 'docs/architecture/authoring-definition-boundary.md and Workflow\\V2\\Support\\WorkflowTypeRegistry-style registries.',
                'how_to_apply' => 'New surface areas that emit or accept a workflow/activity/exception identifier must use the registered type name. Any field that leaks a class FQCN must be marked diagnostic-only.',
            ],
            self::RULE_REPLAY_FIXTURE => [
                'requirement' => 'Replay fixtures and golden history bundles are stored as language-neutral JSON conforming to the `history_event_payloads` and `replay_bundle` JSON Schemas. No serialized PHP objects, no Python pickles, no language-specific binary state in fixtures that any conforming SDK must replay.',
                'rationale' => 'A future SDK proves conformance by replaying the fixture catalog. If a fixture only round-trips through PHP `unserialize` or Python `pickle`, no other language can claim conformance against it.',
                'authority' => 'PlatformConformanceSuite history_replay_bundles category and the `history_event_payloads` and `replay_bundle` spec entries.',
                'how_to_apply' => 'Every fixture under `tests/Fixtures/V2/GoldenHistory/` and `sdk-python/tests/fixtures/golden_history/` must validate against the published JSON Schemas. Adding a fixture that depends on a language-specific serializer is a contract violation.',
            ],
            self::RULE_DISCOVERY => [
                'requirement' => 'Every public surface family is reachable from `GET /api/cluster/info` and the `platform_protocol_specs` catalog. SDK authors must not have to read PHP source, Python source, CLI help text, or this repository\'s tests to discover a public contract.',
                'rationale' => 'Discovery is the entry point for any SDK author. If a contract is only discoverable by reading PHP code, building it in Go is harder than it needs to be.',
                'authority' => 'PlatformProtocolSpecs and the cluster_info_envelope spec entry.',
                'how_to_apply' => 'New public surfaces must add a catalog entry (status `published`, `in_progress`, or `planned`) and a discovery endpoint reference before the surface ships in a stable release.',
            ],
            self::RULE_DOCUMENTATION => [
                'requirement' => 'Public-contract documentation describes shapes in language-neutral terms. Code samples may use PHP and Python, but the normative description must read as schema, route, and field semantics, not as PHP class behavior or Python type behavior.',
                'rationale' => 'A documentation page that says "the response is a `WorkflowRunDescription` object" is unhelpful to a TypeScript developer. The same page that says "the response is the JSON object documented at `cluster_info_envelope` with fields X, Y, Z" is portable.',
                'authority' => 'durable-workflow.github.io docs/2.0 pages and the per-package stability docs.',
                'how_to_apply' => 'New public-contract docs link to the protocol-spec catalog entry, name the discovery endpoint, and describe field-level semantics. PHP class names and Python type names appear only as SDK-specific examples.',
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function auditChecklist(): array
    {
        return [
            'description' => 'Every new server, workflow, CLI, Waterline, or MCP surface must clear this checklist before promotion to `stable`. The checklist is a standing review item on the release PR.',
            'steps' => [
                'protocol_review' => [
                    'rule' => self::RULE_PROTOCOL,
                    'check' => 'The surface is described by an OpenAPI, AsyncAPI, or JSON Schema document in the platform-protocol-specs catalog. No required field depends on a PHP-only or Python-only serializer.',
                ],
                'codec_review' => [
                    'rule' => self::RULE_CODEC,
                    'check' => 'Any payload field carries a codec name and at least one universal codec is offered. Engine-specific codecs are accepted but never required.',
                ],
                'error_shape_review' => [
                    'rule' => self::RULE_ERROR_SHAPE,
                    'check' => 'Failures emit a stable string `code`. PHP `Throwable` FQCNs and Python exception class names are marked diagnostic-only.',
                ],
                'type_identity_review' => [
                    'rule' => self::RULE_TYPE_IDENTITY,
                    'check' => 'Workflow, activity, child workflow, and exception identity uses registered string names. Class FQCNs do not appear in guaranteed fields.',
                ],
                'replay_fixture_review' => [
                    'rule' => self::RULE_REPLAY_FIXTURE,
                    'check' => 'Any history or replay fixture introduced with the surface is JSON-shaped and validates against the `history_event_payloads` and `replay_bundle` schemas.',
                ],
                'discovery_review' => [
                    'rule' => self::RULE_DISCOVERY,
                    'check' => 'The surface is reachable from `GET /api/cluster/info`. The protocol-spec catalog has an entry with the correct surface family and owner repo.',
                ],
                'documentation_review' => [
                    'rule' => self::RULE_DOCUMENTATION,
                    'check' => 'Public docs describe the surface in schema/route/field terms. Language-specific behavior appears in SDK examples, not in the normative section.',
                ],
                'future_sdk_thought_experiment' => [
                    'rule' => self::RULE_PROTOCOL,
                    'check' => 'Reviewer can describe, in two sentences, how a TypeScript or Go SDK would consume the new surface using only the published spec and a standard HTTP+JSON toolchain. If the answer requires a first-party SDK, the surface is not neutral.',
                ],
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    private static function auditScopeSurfaceFamilies(): array
    {
        return [
            'server_api',
            'worker_protocol',
            'cli_json',
            'waterline_api',
            'mcp_discovery_results',
            'cluster_info_manifests',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function releaseGates(): array
    {
        return [
            'description' => 'A release that introduces a new public surface family or promotes an existing surface from `prerelease` or `experimental` to `stable` must record the audit checklist outcome on the release PR.',
            'gates' => [
                'audit_recorded' => 'The release PR description (or a linked design note) states which audit steps were applied and links the protocol-spec catalog entry, the conformance fixture, and the discovery entry for the new surface.',
                'no_php_or_python_only_required_fields' => 'No guaranteed field on a `stable` surface requires the `workflow-serializer-y`, `workflow-serializer-base64`, PHP `serialize`, or Python `pickle` codec.',
                'universal_codec_advertised' => 'Worker protocol negotiation continues to advertise at least one entry from `Workflow\\Serializers\\CodecRegistry::universal()` in its codec set.',
                'fixture_schema_validated' => 'New replay fixtures or golden history bundles validate against the published JSON Schemas in `static/platform-protocol-specs/`.',
                'discovery_entry_present' => 'New public surfaces have a `platform_protocol_specs` catalog entry with a non-empty `surface_family`, `owner_repo`, and `format`.',
            ],
            'enforcement' => [
                'machine' => 'Tests under `tests/Unit/V2/SdkNeutralityContractTest.php` pin this manifest. Docs site CI cross-references the audit scope against the surface stability families. The conformance harness rejects fixtures that do not validate against the published JSON Schemas.',
                'human' => 'Release reviewers tick the SDK-neutrality audit on every release PR that adds or promotes a public surface. The reviewer is responsible for the future-SDK thought experiment.',
            ],
        ];
    }
}
