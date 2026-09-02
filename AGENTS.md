# Repository Agent Guide

Follow the [Durable Workflow organization-wide agent guide](https://github.com/durable-workflow/.github/blob/main/AGENTS.md).

## Quality Cycle

Run these commands before considering a change complete:

1. `composer ecs` - fix code style.
2. `composer stan` - run static analysis with no errors.
3. `composer unit` - run the unit suite.
4. `composer coverage` - maintain the repository's coverage requirement.
5. `composer feature` - run the feature suite.
