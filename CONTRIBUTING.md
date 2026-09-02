# Contributing to Durable Workflow

Thank you for contributing. This repository provides the embedded Laravel
runtime and Server orchestration engine within Durable Workflow 2.0. The wider
Durable Workflow product also includes the standalone server, CLI, and official
language SDKs, so changes here should preserve their shared contracts where
applicable.

## Local setup

The package supports the PHP versions declared in `composer.json`. Clone the
`main` branch and install its development dependencies:

```bash
git clone --branch main https://github.com/durable-workflow/workflow.git
cd workflow
composer install
composer build
```

`composer install` runs Testbench package discovery automatically. Run
`composer prepare` when you need to repeat that discovery step, and
`composer build` after workbench configuration changes.

## Quality checks

Run the checks relevant to your change before opening a pull request. ECS has a
check-only command for review and a Composer command that applies fixes:

```bash
vendor/bin/ecs check
composer ecs
composer stan
```

`composer lint` runs the same PHPStan analysis with verbose output.

Tests use PHPUnit through Orchestra Testbench. Choose focused suites while
developing, then run the broader suite when the change can affect it:

```bash
composer unit
composer feature
composer test
```

To generate the unit-test coverage report when Xdebug is available, run
`composer coverage`.

Behavior changes should normally include focused regression coverage. Tests or
documentation are not necessary for changes they cannot usefully exercise or
explain; note the reason and provide other verification in the pull request.

## Regression corpus and fixtures

Replay and payload-codec fixes also follow the organization
[regression-corpus contract](https://github.com/durable-workflow/.github/tree/main/regression-corpus).
Use one-case golden histories under `tests/Fixtures/V2/GoldenHistory/` whenever
the query-state replay runner can consume them. Cold worker replay evidence
belongs under `tests/Fixtures/V2/ReplayRegression/`; each fixture names an
autoloadable workflow and is executed through the production
`WorkflowFiberRunner` using either persisted history or a worker command
sequence. Shared codec evidence belongs under `tests/Fixtures/V2/CodecRegression/`
and in every applicable official binding.

Fixtures preserve protocol version, value and type, framing, and stable failure
policy. Existing evidence is append-only. Validate new evidence against the
pull request's target branch; `origin/main` is the usual base for this repository,
but set `base_ref` to another target when appropriate:

```bash
git fetch origin main
base_ref=origin/main
python scripts/ci/validate-regression-corpus.py --base-ref "$base_ref"
```

## Pull requests

Explain the motivation and resulting behavior, link relevant context, and list
the commands or other evidence used to verify the change. Call out checks that
were not run and why they do not apply. CI runs the repository's required
quality and test matrix before merge.
