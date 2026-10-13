# SQLite queue reservations

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
The built-in integration currently covers Laravel 12.54 through Laravel 13 and is
version bounded so a new framework major requires explicit qualification.
