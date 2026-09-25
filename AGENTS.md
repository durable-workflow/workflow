# Repository Agent Guide

Follow the [Durable Workflow organization-wide agent guide](https://github.com/durable-workflow/.github/blob/main/AGENTS.md).

## Quality Cycle

Run these commands before considering a change complete:

1. `composer ecs` - fix code style.
2. `composer stan` - run static analysis with no errors.
3. `composer unit` - run the unit suite.
4. `composer coverage` - maintain the repository's coverage requirement.
5. `composer feature` - run the feature suite.

## Stable Releases

Before tagging a stable 2.x release, set
`composer.json`'s `extra.durable-workflow.product-train` to the exact tag and
verify the published-package contract against the committed source. Never
move a published tag; correct a mismatched release with a new patch version.
