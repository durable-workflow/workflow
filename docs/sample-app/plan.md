# Sample-App Plan

This document is the formal phase plan for the `sample-app` Laravel
project that ships under the Durable Workflow umbrella. It explains why
the sample app exists, how it relates to the engine, and what each phase
of investment is meant to deliver.

The clarity rule for this plan is the same as the rest of the v2
documentation: every claim about the sample app must point at an
inspectable artifact in the `sample-app` repository. If a phase of this
plan claims something is in place, the linked file or directory must
prove it.

## Why a Sample-App Plan Lives Here

The workflow package owns the durable kernel. The sample app is the
first place new features are demonstrated end-to-end and the first place
bugs are reproduced against a realistic Laravel host. Because every
release of the workflow package is meant to be exercised against the
sample app within one cycle, the sample-app plan is published next to
the workflow plan: a workflow-package contributor needs to know which
samples a new feature must land in, and a sample-app contributor needs
to know which engine surface a sample is meant to exercise.

The sample-app source of truth lives in the
[`sample-app` repository](https://github.com/durable-workflow/sample-app);
this document only describes the contract and phase plan.

## Scope

This plan covers:

- the four phases of sample-app evolution, from "small Laravel demo"
  to "first-class upstream feedback loop";
- the deliverables and exit criteria for each phase;
- how the sample app stays in sync with workflow-package releases;
- how upstream features get sample coverage and how missing coverage is
  surfaced.

It does not cover:

- engine architecture or durable-kernel contracts (see
  [`docs/workflow/plan.md`](../workflow/plan.md) and the architecture
  documents next to it);
- managed-cloud readiness (see the readiness section of
  [`docs/workflow/plan.md`](../workflow/plan.md));
- the standalone server's polyglot worker examples, which live with the
  server documentation.

## Status

The sample app currently runs on Laravel 13 with the Durable Workflow
2.0-alpha series. The Laravel 12 / Durable Workflow 1.x snapshot is
preserved on the
[`Laravel-12` branch](https://github.com/durable-workflow/sample-app/tree/Laravel-12)
of the sample-app repository.

| Phase | Title | State |
|------|------|-------|
| 1 | Foundational samples | done |
| 2 | Multi-pattern coverage and codespace flow | done |
| 3 | Operator surfaces and MCP | done |
| 4 | Upstream feedback loop | done |

## Phase 1 — Foundational samples

**Goal.** Prove that a Laravel application can run a v2 workflow
end-to-end with the published package.

**Deliverables.**

- a runnable codespace and `docker compose` flow;
- `App\Workflows\Simple\SimpleWorkflow` plus an artisan command that
  starts it;
- a `composer run dev` entry point that boots the queue worker and
  Waterline together.

**Exit criteria.**

- a new contributor can clone the repository, run one command, and see a
  workflow complete inside Waterline.

## Phase 2 — Multi-pattern coverage and codespace flow

**Goal.** Demonstrate the patterns a real Laravel application is most
likely to need.

**Deliverables.**

- samples for elapsed-time measurement, multi-service coordination,
  webhook-started workflows, browser automation, AI activity loops, and
  durable inbox/outbox message streams;
- a sample index in the repository README that names the goal, the
  workflow class, the artisan command, and the MCP key for each entry.

**Exit criteria.**

- every sample listed in the README is registered in
  `config/workflow_mcp.php` and exercised by `php artisan test`.

## Phase 3 — Operator surfaces and MCP

**Goal.** Make the sample app double as a teaching surface for the
operator-facing parts of the engine.

**Deliverables.**

- Waterline available out of the box, with explicit guidance on how to
  read durable history versus runtime worker logs;
- an MCP server that exposes the registered samples to AI clients with
  safe discovery metadata;
- replay-safety teaching notes in the README that contrast `now()` with
  `sideEffect()` and link the relevant samples.

**Exit criteria.**

- an operator who runs the sample app can answer "what did the run do?"
  from Waterline alone, and an AI client can start, observe, and
  diagnose a sample run through MCP.

## Phase 4 — Upstream feedback loop

**Goal.** Make the sample app the first place new features land and the
first place bugs get reproduced, and make missing coverage visible
instead of implicit.

**Deliverables.**

- a structured **sample-request flow**: when a feature ships upstream,
  an issue can be opened against the sample-app repo with the
  `sample-request` template, and it closes when a workflow class lands
  under `app/Workflows/` that exercises the pattern end-to-end and is
  wired into both the artisan command list and `config/workflow_mcp.php`;
- a structured **bug-reproducer flow**: bugs file with the `bug-report`
  template and land as workflows under `app/Workflows/Bug/`, where they
  stay covered by CI after the fix;
- a **release-cycle upgrade cadence**: the sample app's pinned
  `durable-workflow/workflow` version moves within one release cycle of
  every upstream tag, and drift past one cycle is treated as tech debt;
- a **lightweight upstream-coverage tracker**: a manifest in the
  sample-app repository names the upstream feature surfaces the sample
  app is expected to demonstrate, marks each one `covered` or `gap`, and
  is linted on every push so a feature that ships upstream without a
  sample becomes visible in CI rather than in tribal memory.

**Exit criteria.**

- a feature shipping upstream without a sample is visible in the
  coverage tracker before the next release, and the missing entry blocks
  CI on the sample-app repository;
- bug reports routinely land with a sample-app reproducer, and the
  reproducer stays runnable after the bug is fixed because it is
  registered as a workflow under `app/Workflows/Bug/` and exercised by
  `php artisan test`.

**Anchors in the sample-app repository.**

- `.github/ISSUE_TEMPLATE/sample_request.yml` — sample-request flow.
- `.github/ISSUE_TEMPLATE/bug_report.yml` — reproducer flow.
- `.github/ISSUE_TEMPLATE/config.yml` — issue chooser that routes engine
  bugs and standalone-server bugs to their own repositories so they do
  not pollute the sample-app reproducer queue.
- `docs/upstream-coverage.md` and `docs/upstream-coverage.yaml` — the
  human-readable tracker and machine-readable manifest of upstream
  feature surfaces and their sample-coverage status.
- `scripts/check-upstream-coverage.php` and the CI job that runs it —
  the lint that proves the manifest stays consistent with the registered
  samples and that no listed feature surface has slipped to `gap` without
  an open `sample-request` issue.
- README "Reporting Bugs and Requesting Samples" section — operator-
  visible entry point that explains both flows in one place.

## Cadence Contract

The sample-app pinned `durable-workflow/workflow` version moves within
one release cycle of every upstream tag. A release cycle is defined by
the workflow package's tag schedule, not by sample-app activity:

- when an upstream tag ships, the sample-app coverage manifest's
  `tracked_workflow_version` is updated to that tag in the same
  release cycle;
- if `tracked_workflow_version` is older than the latest upstream tag
  for more than one release cycle, the lint job fails so the gap is
  visible in CI;
- security-driven upstream patches are treated as in-cycle and do not
  reset the cadence window.

This contract gives external readers a predictable answer to "is this
sample app in sync with the engine right now?" — they read the manifest
header and compare it to the upstream tag list.

## Coverage Manifest Shape

The coverage manifest is the load-bearing artifact of Phase 4. It must:

- enumerate every upstream feature surface the sample app is expected
  to demonstrate, with a stable `id` so links from issues, blog posts,
  or external docs do not rot;
- mark each entry `covered` (with a workflow class and an artisan
  command that exercises it) or `gap` (with a link to an open
  `sample-request` issue, so a missing sample is always paired with a
  ticket that can absorb the work);
- name the upstream documentation page that defines the surface, so a
  reader can see what the sample is supposed to teach without leaving
  the manifest;
- record the `tracked_workflow_version` so the cadence contract is
  machine-readable.

The manifest is rendered to a human-readable table in
`docs/upstream-coverage.md` for casual reading, but the YAML file is
the source of truth that the lint script checks.

## Changing This Plan

Adding a phase, marking a phase as done, changing the deliverables of a
phase, or relaxing the cadence contract requires updating this document
and the corresponding artifacts in the sample-app repository in the same
change. In particular, marking Phase 4 deliverables as done requires the
linked anchors above to exist on `main` of the sample-app repository.

Removing a sample from the sample app or replacing it with a different
demonstration of the same feature surface is a manifest update, not a
plan change: edit `docs/upstream-coverage.yaml` and run the lint script,
and leave this document alone.

## See Also

- [`docs/workflow/plan.md`](../workflow/plan.md) — workflow package
  plan, durable kernel feature mapping, and managed-cloud readiness.
- [`docs/api-stability.md`](../api-stability.md) — stable API and
  history-event surface that the sample app is allowed to depend on.
