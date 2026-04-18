# Quality Cycle

Run these commands in order before considering any change complete.

When working from Workspace HQ, run them inside the workflow repo `app` container via the root `Makefile`:

- `make workflow-ecs`
- `make workflow-stan`
- `make workflow-unit`
- `make workflow-coverage`
- `make workflow-feature`
- `make workflow-quality`

If you need the raw container command for unit tests, use:

- `docker compose -f .devcontainer/docker-compose.yml exec app composer unit`

Inside the repo container, the quality cycle is:

1. `composer ecs` — Fix code style (auto-fixes)
2. `composer stan` — Static analysis (must pass with no errors)
3. `composer unit` — Unit tests (must all pass)
4. `composer coverage` — Unit tests with coverage (must be 100%)
5. `composer feature` — Feature tests (must all pass)
