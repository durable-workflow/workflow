# Reader-first rollout for rejected activity outcomes

The rejected-outcome audit in [issue 878](https://github.com/durable-workflow/workflow/issues/878)
is not yet published. Its emitter remains separate from this reader change.

Core 2.12.0 cannot read `ActivityOutcomeRejected`: the history model's enum cast
throws `ValueError`. Adding the event to an application's database before every
reader understands it would break history pages, exports and replay inspection.

The prospective Core 2.13.0 reader recognizes the proposed event and its bounded
identity, outcome, reason and observation fields. It maps a diagnostic timeline point and preserves
the event during history export. It does not emit events, accept late activity
results, renew leases, change retries or turn a refused result into a replay step.
Unknown event names remain unknown; this is not a catch-all enum fallback.

## Qualification and deployment order

1. Qualify and publish the non-emitting reader release after the independent
   failure-storage and retention repairs. Exercise history pages,
   cold replay and exported replay with constructed rejected-outcome facts.
   A rejected completion must leave an unfinished activity unresolved.
2. Update all participating embedded applications, PHP Server roles, inspection
   tools and rollback artifacts to a reader-equipped release. A package tag does
   not update a pinned Server image. Keep the emitter disabled while any reader
   still uses a release without this bridge. Qualify the
   exact reader-equipped package and image rather than assuming that a version
   range or an unrelated patch includes this event.
3. Qualify the normal PHP, Python and Rust SDK readers and real Waterline against
   the exact published tuple. Frozen parity actors are not moved to establish
   this acceptance, and no native adoption follows from the PHP release.
4. Qualify the separate emitter's identity, authentication, retention races,
   early refusal before external resolution and bounded first-record contract.
   Only then publish and adopt that change. The audit remains diagnostic and
   never grants business-outcome authority.

After the first new fact is written, rolling back to an older reader that rejects
the event is unsafe. Use the qualified reader-equipped rollback artifact. Do not
delete audit facts, drop unknown rows or rewrite business history to make an old
binary appear compatible.

These are maintainer delivery gates. Source checks alone do not establish a
published reader, a completed rollout or the full activity lease requirement.
