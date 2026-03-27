This repository contains the Durable Workflow package for Laravel. It is a Composer library that implements durable, long-running workflows and activities in PHP.

When making changes in this repository:

- Keep changes focused and avoid unrelated refactors.
- Follow the existing PHP style: add `declare(strict_types=1);` to PHP files and keep code PSR-12 compatible.
- Preserve the existing architecture around `Workflow`, `Activity`, workflow stubs, signals, and generator-based workflow execution.
- Add or update PHPUnit tests when behavior changes. Keep unit tests in `tests/Unit` and integration-style coverage in `tests/Feature`.
- Validate changes with the project quality cycle from the repository root:
  1. `composer ecs`
  2. `composer stan`
  3. `composer unit`
  4. `composer coverage`
  5. `composer feature`
- Treat `composer coverage` as a hard requirement: unit-test coverage must remain at 100%.
- Prefer solutions that stay compatible with the supported Laravel versions declared in `composer.json`.
