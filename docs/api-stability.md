# API Stability

This document describes the v2 public API stability contract of the
`durable-workflow/workflow` package.

## Scope

The workflow package is consumed by three distinct audiences:

1. **Workflow authors** — application code that defines workflows and
   activities.
2. **Host integrators** — applications that embed the queue runner, service
   provider, and background sweeps (the "embedded" deployment mode).
3. **External components** — most notably the standalone `workflow-server`
   HTTP service, the `cli`, and managed `cloud` control plane. These call
   into the workflow package as a library but do not author workflows.

The semver guarantee below covers all three surfaces. Breaking changes to
anything marked `@api` require a major version bump.

## What is covered

A class, interface, method, constant, or property is part of the public API
surface if **any** of the following is true:

- The class/interface is in `Workflow\V2\Contracts` and is not marked
  `@internal`.
- The class has an `@api` annotation in its docblock.
- The class is in `Workflow\V2\Enums` (all enum cases are stable).
- The class is in `Workflow\V2\Models` and is documented as a persisted
  Eloquent model (schema changes go through migrations, not code rename).
- The method or constant is marked `@api` individually.

Everything else — including most helpers under `Workflow\V2\Support\*` that
are not explicitly marked — is internal and may change in a minor release.

## Server-facing `Support\*` stability list

The following classes carry an `@api` annotation because the standalone
`workflow-server` uses them directly rather than through a contract. They
are covered by the semver guarantee until their server-facing method surface
is either promoted to a `Contracts\*` interface or removed:

- `Workflow\V2\Support\ActivityTimeoutEnforcer`
- `Workflow\V2\Support\ConfiguredV2Models`
- `Workflow\V2\Support\HistoryPayloadCompression`
- `Workflow\V2\Support\OperatorQueueVisibility`
- `Workflow\V2\Support\PayloadEnvelopeResolver`
- `Workflow\V2\Support\ScheduleManager`
- `Workflow\V2\Support\ScheduleStartResult`
- `Workflow\V2\Support\StructuralLimits`
- `Workflow\V2\Support\TaskRepairCandidates`
- `Workflow\V2\Support\TaskRepairPolicy`
- `Workflow\V2\Support\WorkerCompatibilityFleet`
- `Workflow\V2\Support\WorkerProtocolVersion`
- `Workflow\V2\Support\WorkflowCommandNormalizer`
- `Workflow\V2\Support\WorkflowTaskOwnership`
- `Workflow\V2\TaskWatchdog`

For these classes the semver guarantee is:

- The class name, namespace, and `final` / non-`final` disposition are
  stable.
- Public constructor and public static/instance method signatures
  (parameter names, types, return types, thrown exception types) are
  stable.
- Public constants (name, value, type) are stable.
- Public readonly properties on value objects are stable.

Additive changes — new public methods, new optional parameters with
defaults, new constants — are minor-version changes and do not require a
major bump.

## Pre-existing `Contracts\*` interfaces

Interfaces under `Workflow\V2\Contracts\*` are the preferred extension
point for external components. Implementations of these interfaces are
expected to track the interface as it evolves; adding a method to a
contract is a major-version change.

## Changing this list

Any pull request that removes a class from the list, changes a signature
on a class in the list, or narrows a return type must either be shipped
in a major version, or promote the class to a `Contracts\*` interface in
the same change. Reviewers should treat unmotivated removals from this
list as a breaking change.
