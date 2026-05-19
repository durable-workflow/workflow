# Platform Conformance Suite

This document is the normative specification for the Durable Workflow
**platform conformance suite**. It defines the conformance target matrix,
the reusable fixture catalog, the harness contract, the pass / fail
rules, and the release gates that make a "compatibility" claim
executable rather than prose.

It is downstream of the platform-wide compatibility authority published
at <https://durable-workflow.github.io/docs/2.0/compatibility> and
mirrored in `Workflow\V2\Support\SurfaceStabilityContract`. Where the
conformance suite enumerates a surface family or stability rule, it must
match the authority manifest. The authority defines *what* the contract
is; this document defines *how* an implementation proves it follows it.

The machine-readable mirror of this document is
`Workflow\V2\Support\PlatformConformanceSuite`, exported by the
standalone `workflow-server` from `GET /api/cluster/info` under
`platform_conformance_suite`. Schema:
`durable-workflow.v2.platform-conformance.suite`, version `5`.

## Why one suite

Durable already has scattered parity assets:

- `cli` and `sdk-python` share control-plane request fixtures under
  `tests/fixtures/control-plane/`.
- `cli`, `sdk-python`, and the standalone `server` share
  `external-task-input` and `external-task-result` fixtures.
- `workflow` ships golden replay history bundles in
  `tests/Fixtures/V2/GoldenHistory/`.
- `server` has per-route contract docs in `docs/contracts/`.

Each is correct in isolation and gated in its own repo's CI, but a third
party that wants to ship a compatible server, SDK, worker, bridge
adapter, or operator dashboard cannot today run *one* suite and get a
single pass / fail answer. This document binds those slices into one
declared suite and one harness contract so a compatibility claim is a
checkable artifact, not an assertion.

## Conformance target matrix

A conformance **target** is a kind of implementation that may claim
Durable compatibility. Each target lists the surface families it must
implement (from the `SurfaceStabilityContract` manifest) and the fixture
categories it must pass. An implementation may claim more than one
target (the standalone `server` claims `standalone_server` *and*
`worker_protocol_implementation` *and* `repair_actionability_surface`).

| Target | Required surface families | Required fixture categories |
| --- | --- | --- |
| `standalone_server` | `server_api`, `worker_protocol`, `cluster_info_manifests` | `control_plane_request_response`, `signal_query_runtime_contract`, `worker_task_lifecycle`, `failure_repair_actionability` |
| `official_sdk` | `official_sdks` (own row), `worker_protocol`, `history_event_wire_formats` | `control_plane_request_response`, `signal_query_runtime_contract`, `worker_task_lifecycle`, `history_replay_bundles` |
| `worker_protocol_implementation` | `worker_protocol`, `history_event_wire_formats` | `worker_task_lifecycle`, `signal_query_runtime_contract`, `history_replay_bundles` |
| `cli_json_client` | `cli_json` | `control_plane_request_response` (request side), `signal_query_runtime_contract`, `cli_json_envelopes` |
| `waterline_contract_surface` | `waterline_api` | `signal_query_runtime_contract`, `waterline_observer_envelopes` |
| `repair_actionability_surface` | `worker_protocol` (failure subset), `server_api` (repair routes) | `failure_repair_actionability` |
| `mcp_discovery_surface` | `mcp_discovery_results` | `mcp_discovery_envelopes` |

Targets are stable. Adding a new target, adding a required surface to an
existing target, or adding a required fixture category is a contract
change and bumps `PlatformConformanceSuite::VERSION`.

## Fixture catalog

The suite does not duplicate fixtures. It declares a catalog of
**source-of-truth** locations and the categories each one supplies.
Implementations vendor or reference these directly; the harness loads
them from the declared locations.

| Category | Source repository | Path | Purpose |
| --- | --- | --- | --- |
| `control_plane_request_response` | `cli`, `sdk-python` | `tests/fixtures/control-plane/` | Frozen request bodies and response shapes for `workflow.start`, `signal`, `query`, `update`, `cancel`, `task-history`, namespace storage. |
| `worker_task_lifecycle` | `cli`, `sdk-python`, `server` | `tests/fixtures/external-task-input/`, `tests/fixtures/external-task-result/` | Task input envelopes (poll → claim → run) and task result envelopes (complete, fail, cancel, heartbeat) used by every conforming worker. |
| `signal_query_runtime_contract` | `durable-workflow.github.io`, `workflow`, `server`, `cli`, `sdk-python`, `waterline` | `static/platform-conformance/signal-query-runtime-scenarios.json`, `docs/architecture/platform-conformance-suite.md`, `docs/architecture/query-and-live-debug.md`, `src/V2/Client/ControlPlaneClient.php`, `tests/Unit/V2/ControlPlaneClientTest.php`, `tests/Feature/SignalReplayTest.php`, `tests/Feature/V2/V2QueryWorkflowTest.php`, `tests/Feature/WorkflowControlPlaneTest.php`, `tests/Feature/WorkflowQueryTaskBrokerTest.php`, `tests/Commands/`, `tests/test_signals.py`, `tests/test_queries.py`, `tests/test_worker.py`, `CONFORMANCE.md` | Live published-artifact scenarios for signal delivery and query consistency across PHP and Python workers, CLI and SDK clients, replay timing, terminal runs, malformed payloads, and operator visibility. |
| `history_replay_bundles` | `durable-workflow.github.io`, `workflow`, `sdk-python` | `static/platform-conformance/replay-runtime-scenarios.json`, `tests/Fixtures/V2/GoldenHistory/`, `tests/fixtures/golden_history/` | Deterministic replay coverage for frozen history bundles, worker restart replay, adversarial refusal, and in-flight signal timing across the official PHP and Python runtimes. |
| `failure_repair_actionability` | `server`, `workflow` | `docs/contracts/external-task-result.md`, `docs/contracts/replay-verification.md`, fixture pointers therein | Failure objects and repair / actionability shapes for stuck tasks, deterministic failure, and replay-mismatch surfaces. |
| `cli_json_envelopes` | `cli` | `tests/fixtures/control-plane/`, `schemas/` | The `--output=json` and `--output=jsonl` envelopes that automation depends on. Diagnostic-only fields are listed and excluded from the contract diff. |
| `waterline_observer_envelopes` | `waterline` | (TBD: `tests/fixtures/observer/`) | The `/waterline/api/v2/*` shapes and operator dashboard JSON envelopes. Status: provisional — fixtures land alongside the next Waterline contract slice. |
| `mcp_discovery_envelopes` | `durable-workflow.github.io`, `sample-app` | `docs/mcp-workflows.md`, `static/platform-protocol-specs/mcp-tool-results.schema.json`, `tests/Feature/McpWorkflowServerTest.php` | MCP `tools/list`, `tools/call`, tool-result, and `llms-2.0.txt` discovery envelopes. Status: provisional — fixtures land alongside MCP tool stabilization. |

A fixture category is **required** for a target only if both the target
column lists it *and* the category status is not `provisional`.
Provisional categories ship an advisory result (warn but do not fail);
they become required when promoted to `stable` in a later suite version.

### Signals and queries runtime contract

The `signal_query_runtime_contract` category is stable and load-bearing.
It must run against published install channels only, pin the resolved
artifact versions in the result, and name every required scenario as
`pass`, `fail`, `unsupported`, `not_covered`, or `runner_blocked` with a
linked finding. A smoke-only run is nonconforming even if the covered
smoke scenarios pass.

Required scenarios:

- `published_artifact_install_only` — server image, CLI installer,
  Python package, PHP package, and Waterline package are resolved from
  published channels; no local source checkout is used as the artifact
  under test.
- `python_worker_cli_and_sdk_baseline` — a Python-authored workflow
  exposes `increment`, `set`, and `current` handlers through CLI and
  Python SDK clients.
- `php_worker_cli_and_sdk_baseline` — the same workflow shape runs on
  the PHP worker and answers through CLI and PHP SDK paths.
- `python_worker_php_facing_and_cli_clients` — a Python-authored
  workflow accepts the supported PHP-facing client path and CLI path.
- `php_worker_python_and_cli_clients` — a PHP-authored workflow accepts
  the Python SDK client path and CLI path.
- `ordered_signal_delivery` — rapid ordered signals are reflected in the
  queried value and recorded in documented history order.
- `dedup_contract_observation` — duplicate client-side keys are observed
  according to the documented support level, or the absence of such a
  contract is recorded.
- `signal_during_replay` — a signal sent while a worker is replaying is
  applied after replay reaches a consistent point.
- `query_during_replay` — a query waits for replay consistency and does
  not run against stale state.
- `completed_run_signal_and_query` — documented terminal-run signal and
  query behavior is verified.
- `unknown_signal_and_query_errors` — unknown names return stable
  user-facing errors without corrupting history.
- `malformed_signal_and_query_payloads` — incompatible payload shapes
  fail before a handler mutates workflow state.
- `waterline_operator_visibility` — operator surfaces show enough
  signal, query, and state information to diagnose the run, including a
  stable reason when live query values are intentionally not materialized
  in read-only detail responses.

### History replay runtime contract

The `history_replay_bundles` category is also stable and load-bearing.
It must run against published install channels only, pin the resolved
artifact versions in the result, and name every required replay scenario
as `pass`, `fail`, `unsupported`, `not_covered`, or `runner_blocked` with
a linked finding. A smoke-only run is nonconforming even when the smoke
path passes.

Required scenarios are published in the public replay scenario manifest
at `static/platform-conformance/replay-runtime-scenarios.json` and
include:

- published-artifact install-only evidence for server, CLI, PHP runtime,
  and Python SDK;
- PHP and Python completed-history replay for activity, signal/update,
  wait-condition, version-marker, and saga-compensation families;
- PHP and Python worker-restart replay for completed-query, activity,
  signal/update, wait-condition, version-marker, and saga-compensation
  state;
- PHP and Python divergent-code refusal with actionable
  non-determinism diagnostics;
- server history mutation and malformed-history refusal through the
  documented replay verification surface;
- PHP and Python in-flight signal restart timing.

## Pass / fail rules

The harness runs each fixture against the implementation under test and
emits a structured result. The rules below are normative.

1. **Guaranteed-field equality.** Every field marked guaranteed in the
   fixture's schema must be present, type-correct, and value-equal in
   the implementation's response. The equality check follows the
   field-visibility rule from `SurfaceStabilityContract`: guaranteed
   fields use deep-equal; diagnostic-only fields are ignored.

2. **Unknown additive fields are tolerated.** An implementation that
   emits extra fields not present in the fixture passes if and only if
   those fields are documented diagnostic-only or the fixture is on a
   stability level that allows additive evolution.

3. **Frozen-shape exact match.** Fixtures backed by a `frozen` surface
   family (every history event bundle, every persisted shape) must
   match exactly. There is no diagnostic-only allowance for frozen
   shapes; a frozen-shape mismatch is always a fail.

4. **Required fixtures must all pass for the claimed target.** A
   release that claims `official_sdk` must pass every required fixture
   category for `official_sdk`. One failed required fixture means the
   release does not conform.

5. **Stable runtime scenario coverage.** A stable runtime category such
   as `signal_query_runtime_contract` or `history_replay_bundles` must
   report every scenario it declares with one of the statuses published
   by its runtime scenario manifest: `pass`, `fail`, `unsupported`,
   `not_covered`, or `runner_blocked`. Full conformance requires every
   required scenario to pass. A smoke-only subset, omitted scenario,
   unsupported public surface, uncovered cell, or runner-blocked cell is
   nonconforming and must link the owning finding.

6. **Provisional categories warn but do not fail.** A failed fixture in
   a provisional category emits a warning in the harness output. A
   release may still claim the target. The category is promoted to
   required by bumping `PlatformConformanceSuite::VERSION` and is then
   load-bearing.

7. **Diagnostic-only mismatches are not failures.** If only
   diagnostic-only fields differ, the harness records the difference in
   its `diagnostic_diff` output and the fixture passes.

8. **Conformance level.** The harness output declares one of:
   - `full` — every required fixture passes for every claimed target.
   - `partial` — every required fixture passes for at least one claimed
     target, but a target is failing.
   - `provisional` — only provisional categories failed; required ones
     all pass.
   - `nonconforming` — at least one required fixture failed for every
     claimed target.

## Harness contract

The conformance harness is the executable that consumes the catalog and
emits a result. The harness is intentionally language-neutral: any
implementation that can run an HTTP client and a JSON deep-equal can
host it.

A conforming harness:

- Loads the suite manifest from the implementation's
  `surface_stability_contract` and `platform_conformance_suite`
  manifests, or, for offline runs, from a vendored copy of the
  `platform-conformance-contract.json` static mirror.
- Loads each declared fixture from its source-of-truth path and,
  where the fixture declares a separate response payload, that as well.
- Drives the implementation through the fixture's documented operation
  (an HTTP request, a worker poll, a replay invocation, a CLI
  invocation, an MCP discovery call).
- Compares the response against the fixture under the rules in
  "Pass / fail rules" above.
- Emits one **harness result document** per run, schema
  `durable-workflow.v2.platform-conformance.result` (defined in the
  manifest). The document carries the suite version, the
  implementation identity, the per-fixture pass / fail / diagnostic
  diff, and the overall conformance level.
- Exits non-zero if and only if the conformance level is
  `nonconforming`.

The harness implementation lives outside this repo (it is itself
language-neutral). This document is the contract the harness must
satisfy. A first-party reference harness will land in a follow-up under
the `durable-workflow.github.io` repo so it can be linked from the
public docs and run by third parties without depending on PHP or Python
SDKs.

## Release gates

A release that wants to publish a compatibility claim must produce a
**signed harness result document** (the harness output saved as a build
artifact) before tag.

| Release | Required claimed target(s) | Where the result is recorded |
| --- | --- | --- |
| `durable-workflow/server` (standalone) | `standalone_server`, `worker_protocol_implementation`, `repair_actionability_surface` | Release CI workflow attaches the result document to the GitHub release. |
| `durable-workflow/workflow` (PHP package) | `official_sdk` (PHP), `worker_protocol_implementation` | Release CI workflow attaches the result document to the GitHub release. |
| `durable_workflow` (Python SDK) | `official_sdk` (Python), `worker_protocol_implementation` | Release CI workflow attaches the result document to the GitHub release. |
| `dw` (CLI) | `cli_json_client` | Release CI workflow attaches the result document to the GitHub release. |
| `waterline` | `waterline_contract_surface` | Release CI workflow attaches the result document to the GitHub release. |

CI alignment is enforced by the existing
`scripts/check-compatibility-authority.js` (extended to walk the
`platform_conformance_suite` manifest in addition to the surface
stability contract) and a new per-repo CI job named
`platform-conformance` that runs the harness against the local build.

A release reviewer must confirm:

- the harness result is attached to the release artifacts;
- the conformance level is `full` (or `provisional`, with the
  provisional categories enumerated in the release notes);
- the suite version recorded in the result matches the version exposed
  by the build under test (no stale-suite skew).

A `nonconforming` result blocks the release.

## Third-party implementations

Third parties (alternate servers, alternate SDKs, bridge adapters,
operator dashboards) may run the suite against their build and publish
the resulting harness result document. A compatibility claim from a
third party is **executable** — readers can re-run the harness against
the same build and reproduce the result document.

The suite imposes no licensing requirement on third-party
implementations; the fixture catalog and the harness contract are the
project's compatibility surface and are governed by the surrounding
repo's license.

## Relationship to existing assets

This document does not replace the per-repo parity slices and contract
docs. It indexes them under one normative declaration so a single
"compatibility" answer can come out of one harness run. Concretely:

- `docs/architecture/worker-compatibility.md` is the per-fleet
  compatibility-pinning contract. It remains the source of truth for
  how a single deployment pins worker, server, and history versions.
  The conformance suite cites it for the `worker_protocol_implementation`
  target.
- `docs/api-stability.md` (this repo) is the per-package stability list
  for the PHP workflow package. The suite cites it for the
  `official_sdks` (PHP row) target.
- `server/docs/contracts/*` are the per-route contract docs. The suite
  cites them for the `standalone_server` target.
- `cli/tests/fixtures/control-plane/` and
  `sdk-python/tests/fixtures/control-plane/` are the existing parity
  fixtures. The suite cites them as the
  `control_plane_request_response` source-of-truth.
- `durable-workflow.github.io/static/platform-conformance/signal-query-runtime-scenarios.json`
  is the public signals/queries scenario matrix consumed by published
  artifact harnesses. The suite cites it alongside the repo-local docs,
  client surfaces, and executable tests that define each product slice.
- `static/platform-conformance/replay-runtime-scenarios.json`,
  `tests/Fixtures/V2/GoldenHistory/` (this repo), and
  `sdk-python/tests/fixtures/golden_history/` are the replay scenario and
  bundle authorities. The suite cites them as the
  `history_replay_bundles` source-of-truth.

## Changing this document

Adding a target, adding a fixture category, promoting a provisional
category to required, or changing a pass / fail rule is a contract
change. In the same change:

- bump `PlatformConformanceSuite::VERSION`;
- update the static mirror at
  `static/platform-conformance-contract.json` in
  `durable-workflow.github.io`;
- update the per-repo conformance claim docs (`server/docs/contracts/`,
  `sdk-python/CONFORMANCE.md`, `cli/CONFORMANCE.md`,
  `waterline/CONFORMANCE.md`);
- update the version-history table in
  `docs/compatibility.md`.

Removing a target or a required fixture category is a major change.
