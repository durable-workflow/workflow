# Helm Self-Serve Contract

This document is the engine-library view of the self-serve Helm path for the
standalone Durable Workflow Server. It states what the engine guarantees
when a deployment is rolled out via the published Helm chart, the rules an
operator has to keep in place, and the boundary against support-led
topologies.

The chart itself lives in the standalone server repository at
[`durable-workflow/server` `k8s/helm/durable-workflow/`](https://github.com/durable-workflow/server/tree/main/k8s/helm/durable-workflow).
The server-side validation contract — distribution, CI harness, recovery
packet additions — lives at
[`durable-workflow/server` `docs/helm-validation.md`](https://github.com/durable-workflow/server/blob/main/docs/helm-validation.md).
The public deployment-matrix entry lives at
[`durable-workflow.github.io/docs/deployment.md`](https://durable-workflow.github.io/docs/2.0/deployment).

This document is the engine-side anchor those three reference.

## What the engine promises under Helm

Helm is a packaging and rollout contract, not a new engine topology. The
engine guarantees the chart inherits are exactly the engine guarantees of
the underlying small-cluster shape and the raw-Kubernetes shape:

* The
  [single-region high-availability and failover contract](https://durable-workflow.github.io/docs/2.0/deployment#single-region-high-availability-and-failover)
  applies, with no relaxation, when the chart is configured to preserve
  the readiness, singleton-scheduler, and shared-substrate invariants in
  that contract.
* The [multi-node deployment requirements](./multi-node-requirements.md)
  apply unchanged: shared external durable database, shared
  acceleration-layer cache backend, exactly one
  scheduler/maintenance runner, stateless API replicas behind a load
  balancer, and externally-scaled workers.
* The
  [active/passive multi-region contract](https://durable-workflow.github.io/docs/2.0/deployment#activepassive-multi-region)
  layers on top of the chart in the same way it layers on top of the raw
  manifests: one active region's chart release is the active small-cluster
  shape; the standby region's chart release is idle until promotion.

Helm does not weaken any of those contracts. It also does not strengthen
any of them — Helm cannot turn a deployment with two scheduler runners into
a deployment with one, and it cannot turn a non-fenced database promotion
into a fenced one.

## Chart-level invariants

The published chart enforces three operator-facing invariants by default,
each of which corresponds to an engine guarantee that would silently break
if the operator overrode it:

### 1. Singleton scheduler/maintenance runner

The engine's recovery bounds for schedule fires, activity-timeout
enforcement, and history pruning assume exactly one scheduler at a time.
The chart enforces this two ways:

* `scheduler.concurrencyPolicy` is locked to `Forbid` and the values
  schema rejects any other value;
* the chart never renders a parallel scheduler `Deployment` or
  `StatefulSet`. A `CronJob` with `Forbid` is the only scheduler shape.

Operators who need duplicate scheduler runners as a steady-state topology
fall outside this contract and must engage support; the engine's
duplicate-runner behaviour is not a self-serve guarantee.

### 2. Readiness on `/api/ready`, not `/api/health`

`/api/health` proves only that the process is serving HTTP. `/api/ready`
proves the server can use its configured database and Redis. The
single-region HA contract relies on `/api/ready` failing during a
managed-database failover so that the load balancer does not route to a
node whose database is unreachable.

The chart defaults `server.readinessProbe.httpGet.path` to `/api/ready`.
Changing it would silently weaken the failover contract; the chart README
flags this and the deployment guide mirrors the warning.

### 3. Bootstrap-before-workloads ordering

The engine relies on `server-bootstrap` having committed before any
workload pod takes traffic. The chart wires this in two layers so it
holds for plain Helm and for GitOps controllers:

* Helm hooks: the bootstrap Job is annotated
  `helm.sh/hook: pre-install,pre-upgrade`,
  `helm.sh/hook-weight: -5`,
  `helm.sh/hook-delete-policy: before-hook-creation,hook-succeeded`.
* GitOps annotations: Argo CD sync-wave annotations put the bootstrap Job
  in an earlier sync wave than the workload Deployments and CronJob.
  Flux `kustomize.toolkit.fluxcd.io/depends-on` annotations point each
  workload at the bootstrap Job.

The chart-side defaults make both annotations active so that the same
release is safe under all three rollout models without per-environment
reshaping.

## Externals-first persistence

The published chart deliberately does not bundle a database or Redis. The
chart will refuse to render until `externalDatabase.host` and
`externalRedis.host` are populated.

This matches the engine's view of persistence: the durable database is
the correctness substrate and Redis is the acceleration layer
(see [multi-node deployment requirements](./multi-node-requirements.md)).
Both are operator-owned services. Bundling them inside the chart would
silently move them into the chart's lifecycle, break the recovery-packet
contract from the
[Operator Operating Envelope](https://durable-workflow.github.io/docs/2.0/operator-operating-envelope),
and introduce a class of upgrade failures that have nothing to do with
the engine.

## Configuration surface

The values schema exposes — and the chart-validation CI exercises — the
configuration surface called out as table-stakes for a serious Helm path:

* liveness, readiness, and startup probes for the server, and a liveness
  probe for the worker;
* deployment strategy and rollout pacing
  (`strategy.type`, `strategy.rollingUpdate.maxUnavailable`,
  `strategy.rollingUpdate.maxSurge`, `minReadySeconds`,
  `progressDeadlineSeconds`, `revisionHistoryLimit`,
  `topologySpreadConstraints`);
* PodDisruptionBudget for both server and worker;
* resource requests and limits, plus `nodeSelector`, `tolerations`,
  `affinity`, and `priorityClassName` per workload;
* the existing-secret pattern for the app secret, the database secret,
  and the Redis secret, with companion examples for the External Secrets
  Operator;
* optional Ingress, HorizontalPodAutoscaler, and NetworkPolicy templates
  that an operator can adopt without leaving the chart.

The chart's [README](https://github.com/durable-workflow/server/blob/main/k8s/helm/durable-workflow/README.md)
is the per-value reference; this document is the engine contract those
values must not break.

## Versioning and upgrade

The chart carries its own semver in `Chart.yaml.version`, independent of
the server image semver in `Chart.yaml.appVersion`. The
[chart upgrading guide](https://github.com/durable-workflow/server/blob/main/k8s/helm/durable-workflow/docs/UPGRADING.md)
publishes:

* the per-MAJOR migration steps for chart breaking changes;
* the universal upgrade procedure (back up DB → render diff → apply with
  `--atomic --wait`);
* the rules for bumping `appVersion` independently of the chart;
* the rollback procedure with explicit notes on irreversible migrations.

A chart MAJOR or MINOR bump is only published after the kind-based chart
smoke (`helm install` → readiness check → `helm upgrade` → readiness
check → `helm uninstall`) passes in the validation harness described in
the server-side
[helm-validation.md](https://github.com/durable-workflow/server/blob/main/docs/helm-validation.md).

## Boundary against support-led topologies

Helm being self-serve does not move any engine boundary. The following
remain support-led — the same boundary that applies to the raw-manifest
and small-cluster shapes:

- active/active multi-writer database topologies;
- automatic or hands-free regional failover (active/passive with
  operator-driven failover stays the
  [active/passive multi-region](https://durable-workflow.github.io/docs/2.0/deployment#activepassive-multi-region)
  contract);
- duplicate scheduler/maintenance runners as a steady-state topology;
- engine-enforced region-pinned task queues as a routing axis;
- provider-specific managed-Kubernetes validation (EKS, GKE, AKS,
  OpenShift) — the chart targets the upstream Kubernetes contract;
- broad "five-nines" or "zero-downtime" SLA promises beyond the bounded
  recovery times in the
  [single-region HA contract](https://durable-workflow.github.io/docs/2.0/deployment#single-region-high-availability-and-failover).

The engine's single statement on Helm: it is a *self-serve install,
upgrade, and rollback contract for the small-cluster and raw-Kubernetes
shapes*, not a new engine topology and not an uptime promise.
