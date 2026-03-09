# Quality Cycle

Run these commands in order before considering any change complete:

1. `composer ecs` — Fix code style (auto-fixes)
2. `composer stan` — Static analysis (must pass with no errors)
3. `composer unit` — Unit tests (must all pass)
4. `composer coverage` — Unit tests with coverage (must be 100%)
5. `composer feature` — Feature tests (must all pass)
