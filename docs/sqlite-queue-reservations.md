# SQLite database queue recovery

With Laravel 12 or 13 and the default database queue connector, Durable Workflow
preserves an unclaimed job when SQLite refuses its reservation write and the
original transaction has fully rolled back. The queue worker reports the error,
backs off and selects work again. The failed reservation does not consume a job
attempt or fail its workflow before the handler runs.

This integration uses Laravel's existing `database` queue driver. No queue names,
connection settings or additional services are required. After upgrading the
package, restart existing queue workers so they load the new code.

The guard checks the original SQLite connection, the exact reservation statement
and a completed root rollback. Other database drivers keep Laravel's queue
implementation. Invalid jobs, handler failures, nested transactions and uncertain
commit or rollback outcomes retain Laravel's existing failure handling. Workflows
that have already failed are not automatically restarted.

An application's custom queue manager or database connector is not replaced.
Applications that own those integrations must handle their reservation behavior.
The reservation guard covers Laravel 12.54 through Laravel 13. Older supported
frameworks retain their original reservation handling. The acknowledgement guard
below covers Laravel 9 through Laravel 13. A new framework major requires explicit
qualification.

## Acknowledging completed work

After a handler returns, the queue deletes only the row matching its original job
ID, queue, reservation time and attempt. A reservation acquired by another worker
cannot be deleted by the previous worker's acknowledgement.

If SQLite refuses this DELETE and the original root transaction has demonstrably
rolled back on the same connection, the queue can retry the acknowledgement.
There are at most three DELETE statements. New retries must start within five
seconds, and each statement retains the application's SQLite busy timeout.
Those five seconds bound admission of retries, not the duration of an individual
database statement. The handler is not rerun by this retry loop.

A failed commit or rollback acknowledgement, a changed connection and other
database errors are propagated without this retry. They are not proof that the
handler or database write did not happen.

## Recovering from sustained contention

1. Inspect the worker's original exception and SQL. A reservation error occurs
   before the handler. An acknowledgement DELETE error occurs after it, so check
   workflow history and the activity outcome before taking a recovery action.
2. Resolve the database lock or storage problem. Keep the original queue row and
   workflow history. Do not copy the job, reset its attempts or delete its
   reservation to force another execution.
3. Keep a healthy queue worker on the same connection and queue. After a
   reservation rollback it backs off and selects available work again. After an
   exhausted acknowledgement retry, the original row can remain reserved until
   the database queue's configured `retry_after` makes it available normally.
4. Verify the original workflow's outcome and queue drainage. Already completed
   or failed workflows are not automatically restarted. Treat an uncertain
   commit outcome separately and reconcile durable state before manually
   retrying an application operation.

Choose `retry_after` to exceed the worker timeout, as required by Laravel's
database queue. Repeated lock errors are a database contention problem to
diagnose, not a reason to continuously restart workers or enqueue replacement
workflows.
