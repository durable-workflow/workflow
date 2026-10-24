# Failure message storage

Read a failure's canonical message through `WorkflowFailure::message`, retained
history or the history export. The physical `workflow_failures.message` column
can contain a compatibility preview for a new large message. Reading that
column directly does not always return the full message.

The additive `2026_10_23_000100_add_full_failure_message_storage` migration adds
nullable `message_full` storage. Existing rows are not rewritten or backfilled.
Ordinary messages keep their existing storage. Messages beyond the old TEXT
capacity, and messages containing NUL, retain their full bytes in a marked
base64 representation in the new column. The model decodes that representation
and hides the physical field from public serialization. Invalid encoded storage
raises an error instead of presenting a preview as the canonical message.

Base64 is a storage representation, not encryption. Apply the same access and
backup protections to both columns. SDK report limits and visible truncation
markers still apply independently; this storage expansion does not increase an
HTTP request limit or turn an already shortened report into its original error.

## Upgrades with live traffic

The migration adds a nullable column without a data backfill. MySQL and MariaDB
must support its explicit `ALGORITHM=INSTANT` operation. Unsupported table
layouts fail instead of silently copying the table. The migration bounds
metadata-lock acquisition to one second on MySQL, MariaDB and PostgreSQL, then
restores the connection's original lock-wait policy on success or failure.

If an active transaction prevents the schema change, finish that transaction
and retry the normal migration. If the engine rejects the instant operation,
use a separately qualified online schema-change procedure appropriate to that
database and table layout. Do not substitute a copying alteration during a
rolling deployment. The expected column is nullable LONGTEXT on MySQL/MariaDB
and the equivalent unbounded text storage on PostgreSQL/SQLite.

Package rollback retains the nullable expansion. It does not drop stored
messages or race with active writers to decide whether the column is empty.
Older readers can show the labelled compatibility preview for new oversized or
NUL-bearing failures. Keep an upgraded reader when the complete canonical
message is needed. Existing retained history continues to carry its full public
failure payload.
