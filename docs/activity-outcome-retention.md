# Activity outcome refusals and retention

A late activity callback and pruning can reach the same terminal run at the
same time. Pruning locks the run before deleting detail rows. A callback must
not hold task or execution locks while waiting for that run lock if its attempt
has already lost authority.

The prospective Core 2.12.2 correction first checks an expired or settled
attempt under the run lock, using fresh stored task, execution and attempt
bindings. If pruning has completed, it refuses the callback without recreating
rows. If a renewal committed before the lock, its current lease remains
authoritative and normal outcome recording performs its existing checks.
Ordinary live attempts keep their existing outcome path and deadlines.

This refusal does not serialize a result, resolve an external payload, record a
new diagnostic, alter the retry policy or grant business-outcome authority. It
is independent of the proposed rejected-outcome audit and its reader rollout.
The canonical timeout and cancellation mechanisms remain responsible for
settling the original attempt.

SQLite pruning acquires the writer before reading its conditional snapshot.
Admission does not change the run's identity or ORM timestamps, and already
claimed outcomes retain their original monotonic publication deadline.

The regression controls use two actual database processes. Retention holds the
run lock before the callback requests it, then deletes the same original
details. Both inline and service-bridge paths must finish without a deadlock or
restored detail, across completed, failed, cancelled and terminated runs. Other
controls cover committed renewal, result-preparation refusal and SQLite writer
ownership before reading the retention snapshot.

Source qualification is not publication. Normal package installation and any
affected Server image adoption require their own exact-artifact acceptance.
