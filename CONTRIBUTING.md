# Contributing to Durable Workflow

First off, thank you for considering contributing to Durable Workflow! It's people like you that make this engine a great tool for the Laravel community.

## Development Environment Setup

To set up the project locally:

1. **Clone the repository:**
   ```bash
   git clone https://github.com/durable-workflow/workflow.git
   cd workflow
   ```

2. **Install dependencies:**
   Make sure you have PHP 8.1+ and Composer installed.
   ```bash
   composer install
   ```

3. **Set up the Testbench environment:**
   We use Orchestra Testbench for our testing environment.
   ```bash
   composer prepare
   composer build
   ```

## Development Workflow

### Code Style & Static Analysis

We enforce strict coding standards using EasyCodingStandard (ECS) and PHPStan. Before submitting a PR, always run:

```bash
# Check and automatically fix code style issues
composer ecs

# Run static analysis
composer stan
# Alternatively, to run with verbose output:
composer lint
```

### Testing

Tests are written using PHPUnit. Ensure your changes pass existing tests and include new ones if you're adding functionality.

```bash
# Run the entire test suite
composer test

# Run only unit tests
composer unit

# Run only feature tests
composer feature

# Generate coverage report (requires Xdebug)
composer coverage
```

## Regression Corpus & Fixtures

**Important:** Replay and payload-codec fixes must follow the [regression-corpus contract](https://github.com/durable-workflow/.github/tree/main/regression-corpus).

When working with worker replay or shared codecs, adhere to the following rules:

1. Use one-case golden histories under `tests/Fixtures/V2/GoldenHistory/` whenever the query-state replay runner can consume them.
2. Cold worker replay evidence belongs under `tests/Fixtures/V2/ReplayRegression/`. Each fixture names an autoloadable workflow and is executed through the production `WorkflowFiberRunner` using either persisted history or a worker command sequence.
3. Shared codec evidence belongs under `tests/Fixtures/V2/CodecRegression/` and in every applicable official binding.
4. Fixtures must preserve protocol version, value and type, framing, and stable failure policy.
5. Existing evidence is **append-only**. Do not modify existing evidence unless explicitly directed by maintainers.

To validate your regression corpus additions against a target branch, run the validation script:

```bash
python scripts/ci/validate-regression-corpus.py --base-ref main
```

## Pull Request Process

1. Follow the Pull Request template provided when opening a PR.
2. Update the README.md or docs if your changes impact user-facing APIs.
3. The CI pipelines will automatically run on your PR. You must have all CI checks (ECS, PHPStan, PHPUnit) passing before your PR can be merged.
