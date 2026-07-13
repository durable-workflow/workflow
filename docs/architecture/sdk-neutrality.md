# SDK Neutrality Contract

This document is the human-readable guide to the SDK neutrality contract. The
authority for exact field shapes is the machine-readable
[`durable-workflow.v2.sdk-neutrality.contract`](https://durable-workflow.github.io/sdk-neutrality-contract.json)
manifest. The Workflow package ships the same bytes at
`resources/sdk-neutrality-contract.json`, so release tools and third-party
consumers do not need to inspect a PHP implementation class. The contract sits
downstream of the public
[`durable-workflow.v2.surface-stability.contract`](https://durable-workflow.github.io/compatibility-contract.json),
[`durable-workflow.v2.platform-protocol-specs.catalog`](https://durable-workflow.github.io/platform-protocol-specs.json),
and
[`durable-workflow.v2.platform-conformance.suite`](https://durable-workflow.github.io/platform-conformance-contract.json)
authorities. Where this guide and a published machine-readable authority
disagree, the machine-readable authority wins and this guide is the bug.

## Why this exists

Durable ships three first-party SDKs: the PHP
`durable-workflow/workflow` package, Python `durable_workflow` package,
and Rust `durable-workflow` crate. There is no plan to ship a broad
official SDK portfolio in the v2 release, and there is no reserved
release slot for a TypeScript, Go, Java, or .NET SDK.

That choice is intentional. The maintenance cost of a wide first-party
SDK roster is high and the demand for SDKs in those ecosystems has not
yet been demonstrated.

What we do not want, however, is for the public contracts under those
SDKs to quietly hard-code language-specific assumptions. If a
future TypeScript or Go SDK becomes worth building, the work should be
"write a new client against the published wire protocol", not
"redesign the protocol so another language can speak it at all".

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

The machine-readable contract enumerates these as `posture` values on each
language entry and on the `expansion_criteria` map.

## Neutrality rules

The contract defines seven minimum neutrality rules. Every public contract
must satisfy each of them. The packaged and publicly mirrored JSON contract is
the authority for the exact field shapes; the summary below is for reviewers.

| Rule | Requirement |
| --- | --- |
| `protocol_neutrality` | Public RPC and event surfaces use HTTP+JSON or AsyncAPI shapes that any HTTP-capable runtime can produce and consume. |
| `codec_neutrality` | Every payload that crosses a public boundary advertises a codec name. At least one universal codec is always offered alongside any engine-specific codec. |
| `error_shape_neutrality` | Public failure objects use a structured envelope of (`code`, `message`, optional `details`). PHP/Python exception class names are diagnostic only. |
| `type_identity_neutrality` | Workflow, activity, child workflow, and exception types are identified by stable string names. Class FQCNs are SDK-input convenience, not contract. |
| `replay_fixture_neutrality` | Replay fixtures and golden history bundles are JSON conforming to the published `history_event_payloads` and `replay_bundle` schemas. |
| `discovery_neutrality` | Every public surface is reachable from `GET /api/cluster/info` and the `platform_protocol_specs` catalog. |
| `documentation_neutrality` | Public-contract docs describe shapes in schema, route, and field semantics. PHP and Python class behaviour appears as SDK examples, not as the normative contract. |

The full rationale, public authority references, and "how to apply" guidance
for each rule is on the matching `neutrality_rules` entry of the published
manifest. Each authority reference has a stable schema or catalog ID and an
absolute public URL.

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
  workflow authoring SDK and embedded host. Its replay coverage is enumerated
  by scenario ID in the public
  [`history_replay_bundles` catalog](https://durable-workflow.github.io/platform-conformance/replay-runtime-scenarios.json).
- `first_party.python_sdk`: posture `priority`. Highest-value non-PHP
  SDK; used to validate that the worker protocol, control plane, and
  replay fixtures behave the same way outside PHP. Its replay coverage is
  enumerated in the same public `history_replay_bundles` catalog.
- `first_party.rust_sdk`: posture `priority`. First-party deterministic
  workflow, activity, worker-service, and control-plane SDK; used to
  validate replay, lifecycle, and codec interoperability outside PHP
  and Python. Its worker, client, failure, and cold-restart replay coverage is
  enumerated by scenario ID in the public
  [`signal_query_runtime_contract` catalog](https://durable-workflow.github.io/platform-conformance/signal-query-runtime-scenarios.json).
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
load-bearing inputs for any SDK outside the current PHP, Python, and
Rust roster:

- **Protocol**: the `durable-workflow.v2.control-plane-api`,
  `durable-workflow.v2.worker-protocol-api`, and
  `durable-workflow.v2.worker-protocol-stream` entries in the public
  [protocol catalog](https://durable-workflow.github.io/platform-protocol-specs.json).
- **Codecs**: the universal codec set documented by
  `durable-workflow.v2.worker-protocol-api` and advertised through the
  `durable-workflow.v2.cluster-info-envelope` discovery schema.
- **Error shape**: the worker-protocol failure envelope and
  `durable-workflow.v2.repair-actionability-objects` schema.
- **Replay inputs**: the `durable-workflow.v2.history-event-payloads` and
  `durable-workflow.v2.replay-bundle` JSON Schemas plus scenario IDs in the
  public `history_replay_bundles` catalog.
- **Discovery**: the `durable-workflow.v2.cluster-info-envelope` schema and
  `durable-workflow.v2.platform-protocol-specs.catalog` itself.

If any of those surfaces is not reachable for a candidate SDK in a
given language, building the SDK requires protocol changes and the
language-agnosticism guarantee is not being honored.

## Release gates

A release that introduces a new public surface family or promotes an existing
surface from `prerelease` or `experimental` to `stable` must record the audit
outcome on the release PR. The `release_gates.gates` map enumerates the
specific checks. Enforcement is a mix of:

- **Machine**: release CI resolves every authority URL, protocol/schema ID,
  and conformance scenario ID in the public manifest, cross-references the
  audit scope against the surface stability families, and rejects replay
  inputs that do not validate against the published JSON Schemas.
- **Human**: release reviewers tick the SDK-neutrality audit on every
  release PR that adds or promotes a public surface. The reviewer is
  responsible for the future-SDK thought experiment.

## Changing this contract

Adding a neutrality rule, tightening an existing rule, adding a
required audit step, adding a surface family to the audit scope, or
changing the official-SDK breadth policy is a contract change. Bump the
manifest version, update this guide, the packaged
`resources/sdk-neutrality-contract.json` authority, its byte-equivalent
[public JSON mirror](https://durable-workflow.github.io/sdk-neutrality-contract.json),
the [public SDK-neutrality guide](https://durable-workflow.github.io/docs/2.0/sdk-neutrality),
and the per-package stability documents in the same change. Removing a
neutrality rule or audit step is a major change.
