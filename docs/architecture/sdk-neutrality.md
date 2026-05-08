# SDK Neutrality Contract

This document is the human-readable authority for the SDK neutrality
contract enforced by `Workflow\V2\Support\SdkNeutralityContract`. It
sits downstream of the platform compatibility authority
(`SurfaceStabilityContract`) and the platform protocol-spec catalog
(`PlatformProtocolSpecs`). Where this document and one of those
authorities disagree, the upstream authority wins and this document
is the bug.

## Why this exists

Durable ships two first-party SDKs: the PHP `durable-workflow/workflow`
package and the Python `durable_workflow` package. There is no plan to
ship a broad official SDK portfolio in the v2 release, and there is no
reserved release slot for a TypeScript, Go, Java, or .NET SDK.

That choice is intentional. The maintenance cost of a wide first-party
SDK roster is high and the demand for SDKs in those ecosystems has not
yet been demonstrated.

What we do not want, however, is for the public contracts under those
SDKs to quietly hard-code PHP-only or Python-only assumptions. If a
future TypeScript or Go SDK becomes worth building, the work should be
"write a new client against the published wire protocol", not
"redesign the protocol so a non-PHP, non-Python language can speak it
at all".

This contract is the standing rule that protects that property.

## Scope

- **Goal**: every public Durable contract is shaped so a future
  TypeScript, Go, Java, or .NET SDK could be written without breaking
  the wire protocol or the replay-fixture corpus.
- **Non-goal**: shipping additional first-party SDKs. Breadth is
  demand-driven. New first-party SDKs are added only when adoption
  signal justifies the maintenance commitment.
- **Present priority**: Python is the highest-value non-PHP SDK. It
  exists today and is treated as a parity-coverage priority surface.
- **Future posture**: TypeScript, Go, Java, .NET, and other languages
  are demand-driven. They have no reserved release slot, but every
  public contract must be implementable in any of them using only the
  language's standard HTTP and JSON tooling and the published spec
  catalog.

The `SdkNeutralityContract` class enumerates these as `posture` values
on each language entry and on the `expansion_criteria` map.

## Neutrality rules

The contract defines seven minimum neutrality rules. Every public
contract must satisfy each of them. The class is the authority for the
exact field shapes; the summary below is for reviewers.

| Rule | Requirement |
| --- | --- |
| `protocol_neutrality` | Public RPC and event surfaces use HTTP+JSON or AsyncAPI shapes that any HTTP-capable runtime can produce and consume. |
| `codec_neutrality` | Every payload that crosses a public boundary advertises a codec name. At least one universal codec is always offered alongside any engine-specific codec. |
| `error_shape_neutrality` | Public failure objects use a structured envelope of (`code`, `message`, optional `details`). PHP/Python exception class names are diagnostic only. |
| `type_identity_neutrality` | Workflow, activity, child workflow, and exception types are identified by stable string names. Class FQCNs are SDK-input convenience, not contract. |
| `replay_fixture_neutrality` | Replay fixtures and golden history bundles are JSON conforming to the published `history_event_payloads` and `replay_bundle` schemas. |
| `discovery_neutrality` | Every public surface is reachable from `GET /api/cluster/info` and the `platform_protocol_specs` catalog. |
| `documentation_neutrality` | Public-contract docs describe shapes in schema, route, and field semantics. PHP and Python class behaviour appears as SDK examples, not as the normative contract. |

The full rationale, authority pointer, and "how to apply" for each rule
is on the matching `neutrality_rules` entry of the class manifest.

## Standing audit checklist

Every new server, workflow, CLI, Waterline, or MCP surface must clear
the `audit_checklist` before promotion to `stable`. The checklist is a
standing review item on every release PR that touches an audit-scoped
surface family. The audit-scoped families today are:

- `server_api`
- `worker_protocol`
- `cli_json`
- `waterline_api`
- `mcp_discovery_results`
- `cluster_info_manifests`

The checklist has eight steps. Seven correspond to the neutrality rules
above. The eighth is a thought experiment: the reviewer must be able to
describe in two sentences how a TypeScript or Go SDK would consume the
new surface using only the published spec catalog and a standard
HTTP+JSON toolchain. If the answer requires a first-party SDK, the
surface is not neutral and either the surface is reshaped or the
neutrality gap is recorded as a known limitation before promotion.

## SDK breadth policy

The `sdk_breadth_policy` map on the manifest is the source of truth for
the official-SDK roster:

- `first_party.php_workflow_package`: posture `priority`. Reference
  workflow authoring SDK and embedded host.
- `first_party.python_sdk`: posture `priority`. Highest-value non-PHP
  SDK; used to validate that the worker protocol, control plane, and
  replay fixtures behave the same way outside PHP.
- `demand_driven.typescript_sdk`, `go_sdk`, `java_sdk`, `dotnet_sdk`:
  posture `demand_driven`. No first-party SDK exists. Public contracts
  must remain implementable in those languages without protocol
  redesign.

A new first-party SDK is added only when:

1. There is documented user demand the existing SDKs cannot serve.
2. A candidate maintainer team commits to keeping the SDK on the
   conformance harness, the protocol-spec catalog, and the release
   authority manifest.
3. Adding the SDK does not require breaking changes to the worker
   protocol, control plane, history-event wire formats, or replay
   fixtures. If it would, the protocol is the bug, not the SDK.

## What a future SDK relies on

The contract identifies the surfaces a future SDK must be able to read
without inspecting any first-party SDK source. These are the
load-bearing inputs for any non-PHP, non-Python SDK:

- **Protocol**: the `control_plane_api`, `worker_protocol_api`, and
  `worker_protocol_stream` spec entries in the
  `PlatformProtocolSpecs` catalog.
- **Codecs**: the universal codec set advertised by
  `Workflow\Serializers\CodecRegistry::universal()` and surfaced on
  the `worker_protocol` cluster_info manifest.
- **Error shape**: the `external_task_result_contract` failure
  envelope and the `repair_actionability_objects` schemas.
- **Replay fixtures**: the `history_event_payloads` and
  `replay_bundle` JSON Schemas plus the `history_replay_bundles`
  fixture category in the `PlatformConformanceSuite`.
- **Discovery**: the `cluster_info_envelope` schema and the
  `platform_protocol_specs` catalog itself.

If any of those surfaces is not reachable for a candidate SDK in a
given language, building the SDK requires protocol changes and the
language-agnosticism guarantee is not being honored.

## Release gates

A release that introduces a new public surface family or promotes an
existing surface from `prerelease` or `experimental` to `stable` must
record the audit outcome on the release PR. The `release_gates.gates`
map enumerates the specific checks. Enforcement is a mix of:

- **Machine**: tests under `tests/Unit/V2/SdkNeutralityContractTest.php`
  pin the manifest, the docs site CI cross-references the audit scope
  against the surface stability families, and the conformance harness
  rejects fixtures that do not validate against the published JSON
  Schemas.
- **Human**: release reviewers tick the SDK-neutrality audit on every
  release PR that adds or promotes a public surface. The reviewer is
  responsible for the future-SDK thought experiment.

## Changing this contract

Adding a neutrality rule, tightening an existing rule, adding a
required audit step, adding a surface family to the audit scope, or
changing the official-SDK breadth policy is a contract change. Bump
`SdkNeutralityContract::VERSION`, update this document, the static
JSON mirror at `static/sdk-neutrality-contract.json` on the
`durable-workflow.github.io` docs site (the public mirror page is
`docs/sdk-neutrality.md`), and the per-package stability documents in
the same change. Removing a neutrality rule or audit step is a major
change.
