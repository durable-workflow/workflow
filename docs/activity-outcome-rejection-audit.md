# Rejected activity outcome audit

This is the proposed contract for the remaining history requirement in #878.
It is not a published capability. Existing completion and failure responses
continue to decide whether the business outcome was accepted.

## Durable fact

Record `ActivityOutcomeRejected` once for the first refused late outcome of a
recognized original attempt after it loses lease or deadline authority. Bind
the record to its original run, activity, attempt, task, attempt number and
lease owner. Include the attempted outcome (`completed` or `failed`), the
server's refusal reason and the observation timestamp. The first record stays
immutable. Repeated requests do not create additional records.

Do not store the rejected result, failure message, stack trace, external object
reference, authentication material or an unrestricted request snapshot.
An already committed successful or failed outcome is not a new rejected
outcome merely because its response was retried.

## Authority and ordering

- Authenticate and enforce namespace, task, attempt and original owner binding
  before any audit write. Unrelated or unknown identities create no record.
- Recheck lost authority under the existing storage transaction and locks.
  Select the reason from stored state and original deadlines. A request cannot
  nominate its own audit reason or make a valid claim stale.
- Refuse lost authority before resolving or retaining an external payload.
  Audit recording never accepts the result, renews a lease, reopens a run,
  dispatches work or creates a success receipt. `recorded=false` retains its
  existing business meaning.
- Keep prior business history and progress timestamps unchanged. The diagnostic
  record advances history sequence/count metadata only. It does not become a
  replay step or manufacture business progress.
- Keep retention boundaries. Do not recreate deleted or pruned history, and
  serialize the append against pruning. Closed retained runs may acquire this
  diagnostic fact without acquiring new execution authority.

## Required qualification

Prove original identity, one-record behavior across repeated and concurrent
requests, first-record immutability, transaction rollback, no payload/provider
access, namespace/owner refusals, pruning races and unchanged business state.
Exercise active and closed runs, expired leases, declared retry exhaustion and
authored deadlines on SQLite, MySQL and PostgreSQL. Replay the resulting history
with the published PHP, Python and Rust readers and verify real Waterline
visibility. Existing failed originals remain failed.

No schema, SDK protocol floor or native/frozen adoption is implied. The event
and payload contract must be coordinated before publication; a source draft or
passing local test does not deliver this requirement.
