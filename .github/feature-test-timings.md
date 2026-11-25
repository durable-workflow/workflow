# Feature shard weights

`feature-test-timings.json` contains balancing hints in seconds. It does not select or exclude tests. The shard verifier still requires every discovered feature file exactly once, and each backend retains its existing process limit and required coverage reports. Reports that contain only declared backend skips receive a positive scheduling floor of 0.001 seconds.

The November 17 refresh uses the per-file JUnit timings from accepted main [build 44004967899](https://github.com/durable-workflow/workflow/actions/runs/44004967899) at `7b2e41eedd17137e6acdbeb83292e73b100f7fc4`. PostgreSQL entries with a complete newer report use [build 44017266532](https://github.com/durable-workflow/workflow/actions/runs/44017266532) at `569eb03bb385c490b80d1614b53cdd5433dfa346` instead. Its PostgreSQL shard 5 reached the 20-minute limit without a complete JUnit report, so those existing files retain the accepted-main measurements.

The new Order billing-period class measured **284.935660 seconds on MySQL** in build 44017266532. Its **427.403490-second PostgreSQL weight is a provisional estimate**, 1.5 times that MySQL measurement, pending a complete PostgreSQL report. It replaces the one-second unprofiled-file fallback; it is not a measured PostgreSQL duration. Refresh that entry from the next successful PostgreSQL report.

## Covered Order-period class

The first complete main [build 44024863937](https://github.com/durable-workflow/workflow/actions/runs/44024863937) at `36dcd4d58f0b42acc74033592f7612880e80c2c2` timed out in MySQL shard 11 after 1,200 seconds. Its retained event trace records all 17 Order-period cases passed, then reaches the existing timeout-cleanup class. The combined shard lacks a complete JUnit/coverage report and remains failed.

The new class used a non-coverage MySQL duration in the covered profile. Give it a conservative **1,200-second scheduling weight**, the upper bound of that combined run, so the existing allocator places it alone. This is not a measured per-class duration and does not extend the process limit. Other MySQL weights, the complete test inventory, all 16 coverage shards and coverage gates are unchanged. Replace this reservation with the next complete covered class measurement when refreshing the profile.

The current PostgreSQL class measurement is 362.791408 seconds in [build 44022117686](https://github.com/durable-workflow/workflow/actions/runs/44022117686). The conservative 427.403490-second PostgreSQL hint remains unchanged in this correction, preserving its passing allocation.

## Configured child-completion race class

`V2ConfiguredChildCompletionRaceTest` has no CI measurement yet. Its weights are **provisional estimates**: 212 seconds on PostgreSQL and 422 seconds on MySQL. On a local PostgreSQL run, the class took 135.5 seconds and `V2ConfiguredStateMachineExampleTest` took 157.7 seconds. Each estimate scales the 135.5 seconds by the example class's CI-to-local ratio on that database (246.483415 and 491.275492 seconds). Replace both entries with measured durations from the next complete reports.
