# Feature shard weights

`feature-test-timings.json` contains balancing hints in seconds. It does not select or exclude tests. The shard verifier still requires every discovered feature file exactly once, and each backend retains its existing process limit and required coverage reports. Reports that contain only declared backend skips receive a positive scheduling floor of 0.001 seconds.

The November 17 refresh uses the per-file JUnit timings from accepted main [build 44004967899](https://github.com/durable-workflow/workflow/actions/runs/44004967899) at `7b2e41eedd17137e6acdbeb83292e73b100f7fc4`. PostgreSQL entries with a complete newer report use [build 44017266532](https://github.com/durable-workflow/workflow/actions/runs/44017266532) at `569eb03bb385c490b80d1614b53cdd5433dfa346` instead. Its PostgreSQL shard 5 reached the 20-minute limit without a complete JUnit report, so those existing files retain the accepted-main measurements.

The new Order billing-period class measured **284.935660 seconds on MySQL** in build 44017266532. Its **427.403490-second PostgreSQL weight is a provisional estimate**, 1.5 times that MySQL measurement, pending a complete PostgreSQL report. It replaces the one-second unprofiled-file fallback; it is not a measured PostgreSQL duration. Refresh that entry from the next successful PostgreSQL report.
