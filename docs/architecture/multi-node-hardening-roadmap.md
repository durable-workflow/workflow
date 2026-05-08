# Workflow V2 Multi-Node Architecture Hardening Roadmap

This document is the parent tracker for hardening workflow v2 multi-node
execution. It records the order of the remaining architecture work so
semantics, compatibility routing, dispatch, topology, cache independence, and
rollout safety are reviewed as one dependency chain instead of as unrelated
features.

The phase contracts below apply to the `durable-workflow/workflow` package at
v2, to the standalone `durable-workflow/server` that embeds it, and to every
host that embeds the package directly or talks to the server over HTTP. Each
linked contract remains the authority for its own behavior; this roadmap is
the authority for ordering, dependencies, and non-goals.

## Background

Workflow v2 already supports multi-node execution with shared database
coordination and shared wake signals. The remaining work hardens four
architectural risks:

- control-plane and execution-plane responsibilities are still easy to couple
  inside generic application nodes;
- scheduler correctness must be explained in terms of durable dispatch state,
  not shared cache or broad database polling;
- mixed-version safety must become an explicit routing and rollout contract,
  not an environment-discipline convention;
- duplicate execution, retries, lease expiry, and idempotency need one frozen
  product language used by package, server, SDK, CLI, cloud, and Waterline.

The roadmap is deliberately incremental. It does not adopt Temporal wholesale,
rebuild the engine in one step, eliminate SQL persistence, or treat shared
cache plus identical nodes as the final architecture.

## Ordered Phases

| Phase | Contract | Contract authority | Depends on | Adjacent contracts |
| --- | --- | --- | --- | --- |
| 1 | Phase 1: execution guarantees and idempotency contract | `docs/architecture/execution-guarantees.md` | none | operational liveness and transport repair, testing strategy, documentation plan |
| 2 | Phase 2: mixed-version compatibility and worker routing | `docs/architecture/worker-compatibility.md` | Phase 1 | deployment modes, routing precedence and inheritance |
| 3 | Phase 3: dedicated task matching and dispatch | `docs/architecture/task-matching.md` | Phases 1 and 2 | operational liveness and transport repair, operating envelope and hosting guidance |
| 4 | Phase 4: control-plane and execution-plane role split | `docs/architecture/control-plane-split.md` | Phases 1, 2, and 3 | deployment modes, hosted control-plane and data-plane split |
| 5 | Phase 5: remove shared cache from scheduler correctness | `docs/architecture/scheduler-correctness.md` | Phases 1 through 4 | operational liveness and transport repair, operating envelope and hosting guidance |
| 6 | Phase 6: rollout safety enforcement and coordination health | `docs/architecture/rollout-safety.md` | Phases 1 through 5 | operating envelope and hosting guidance |

## Dependency Rules

Phase 1 freezes the semantic vocabulary. Later phases must reuse its language
for at-least-once execution, deterministic replay, exactly-once durable rows,
lease expiry, redelivery, retry, and idempotency. A later contract must not
define a competing duplicate-execution model.

Phase 2 consumes Phase 1 and freezes which workers may execute which work. Any
dispatch, topology, or rollout change must preserve the compatibility marker
and worker-fleet semantics named there.

Phase 3 consumes Phases 1 and 2 and freezes the matching role. It may change
where ready-task discovery runs, but it must preserve execution guarantees and
compatibility routing.

Phase 4 consumes Phases 1 through 3 and freezes role authority boundaries.
Control-plane, matching, history/projection, scheduler, and execution-plane
roles may be hosted in different process shapes, but each role keeps the
semantics, routing, and matching contracts already frozen.

Phase 5 consumes Phases 1 through 4 and freezes the correctness boundary. Shared
cache, notifier backends, and wake stores are acceleration layers only. Durable
dispatch state remains the source of truth for claim, lease, redelivery, and
schedule-fire eligibility.

Phase 6 consumes every earlier phase and freezes in-system rollout safety and
coordination health. Admission, drains, stuck detectors, and operator metrics
must report violations of the earlier contracts explicitly rather than relying
on operators to infer them from logs or missing progress.

## Adjacent Work

The phase-linked adjacent contracts are inputs, not duplicates:

- The operational liveness and transport repair contract owns repair,
  redelivery, and durable-next-resume behavior. Phases 1, 3, and 5 cite it.
- The testing strategy contract
  ([`docs/architecture/testing-strategy.md`](testing-strategy.md))
  owns contract tests, degraded-mode tests, and cross-surface
  conformance coverage. Phase 1 aligns with it.
- The documentation plan contract owns aligned product language across
  package, server, SDK, CLI, cloud, and Waterline. Phase 1 uses it.
- The deployment modes contract owns routing and topology shapes. Phases 2
  and 4 consume it.
- The routing precedence and inheritance contract owns connection, queue,
  compatibility, and namespace inheritance. Phase 2 consumes it.
- The operating envelope and hosting guidance contract owns hosting guidance,
  scheduler behavior, and unsupported topologies. Phases 3, 5, and 6 consume
  it.
- The hosted control-plane and data-plane split contract owns managed
  topology decisions. Phase 4 consumes it.

When adjacent work changes a shared term, the phase contract that owns that term
must be updated in the same change. This roadmap should not be expanded into a
second copy of the adjacent contracts.

## Cross-Repo Coordination

The workflow package owns the normative contracts. Server, SDK, CLI, cloud, and
Waterline consume those contracts through API manifests, deployment docs,
operator metrics, diagnostics, and compatibility checks.

Cross-repo changes must follow the dependency order above. For example, a server
change that exposes rollout-safety health must preserve Phase 1 idempotency,
Phase 2 compatibility routing, Phase 3 matching partition primitives, Phase 4
role authority, and Phase 5 cache independence. If a consuming repo cannot
depend atomically on a newly added package surface, it must degrade gracefully or
feature-gate the behavior until the package change lands.

## Non-Goals

- Adopting Temporal wholesale.
- Rebuilding the entire system in one step.
- Eliminating SQL persistence.
- Treating shared cache plus identical app nodes as the final architecture.
- Adding a parallel product contract in a downstream repo instead of linking
  back to the workflow contract that owns the phase.

## Close Condition

This roadmap is complete when the child contracts above capture the
architecture work in the listed order and preserve explicit dependencies between
semantics, compatibility routing, dispatch, topology, cache independence, and
rollout safety. Once a phase contract changes, this roadmap must still describe
the correct ordering and dependency chain, or the change is incomplete.
