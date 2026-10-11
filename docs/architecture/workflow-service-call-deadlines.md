# Service call deadlines

A service call deadline bounds the original durable call across embedded and Server execution. The accepted call retains one resolved deadline throughout its lifetime.

An operation's `deadline_policy` is null, or an object containing exactly one field:

- `timeout_seconds`: an integer from 1 through 2147483647, measured from the call's original durable `accepted_at` instant.
- `deadline_at`: an offset-bearing RFC3339 instant with zero through six fractional digits.

Reject unknown fields, combined forms, booleans, numeric strings, invalid dates, missing offsets, excessive precision and overflow before acceptance. The resolved UTC instant must fall within years 1970 through 9999. A valid absolute deadline may already be expired.

Persist `call_deadline_at` once, retaining microseconds. Its wire representation is UTC RFC3339 with six fractional digits and `Z`, or null. Catalog edits, duplicate admission, handler retries, process replacement and migration never restart that budget. Null policy retains the existing linked target's timeout behavior without introducing an independent call deadline.

Deadline expiry and terminal completion use the same durable call transition fence. Expiry wins at the boundary. Expiry produces durable `status=failed`, `outcome=timed_out` and one typed failure delivered at the original caller run, call and sequence. The existing background repair pass enforces expiry without requiring an API read or client poll. Replacement workers deliver or replay the same outcome without duplicate completion.

A linked target may retain its own legitimate terminal history after the call expires. That result cannot replace the call's timeout or produce another caller success. Target cancellation is governed by its separate cancellation policy, rather than implicitly triggered by a call deadline.

The PHP migration resolves existing accepted calls from their stored original policy and `accepted_at`, retaining null policies. It must never derive a deadline from migration/startup time. Already expired nonterminal calls become timeout candidates for the normal repair pass. Completed historical calls retain their recorded outcome. Unsupported legacy policies fail migration with the affected call identity for operator correction, rather than receiving an invented deadline.

The new deadline column stores UTC. Existing acceptance timestamps retain their Laravel model timezone convention. Preserve the application's existing timezone during migration so its old acceptance timestamps resolve to the same instants. Stop old application/Server and queue-worker processes before migrating, then start processes using the new package. A process running the old code cannot enforce the new transition fence. Take the normal database backup before the upgrade.

Required evidence includes no-poll expiry, duplicate/retry identity, completion at the boundary, late results, original typed failure delivery in same and cross namespaces, restart/recovery, migration backfill and all supported database families. Published package and image qualification are required before claiming delivery.
