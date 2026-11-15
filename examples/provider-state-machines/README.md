# Provider-backed Order, Payment and booking machines

Work in progress for [#1069](https://github.com/durable-workflow/workflow/issues/1069).
The policy and original child-binding values are implemented; the recorded SQL
host, workflow composition and native acceptance tests are still being built.
This directory is not yet a runnable example or an installed-package guide.

The first selected policy uses an original stock reservation, immediate full
payment capture and carrier booking before handover. Payment is a distinct
machine with its own capture transition; booking is a distinct Shipment
boundary. A booked parcel has not been delivered. All three machines use the
same transition protocol, immutable recorded facts and protected commits.
The existing configured-machine profiles and fingerprints remain unchanged.

The required compensation sequence is confirmed cancellation of the original
booking, confirmed refund of the original capture, then confirmed release of
the original reservation. A cancellation request or processing refund is not
settlement. The independent synthetic provider journal distinguishes request
attempts from effects and remains separate from DW history.

`OrderTerms` is an application assignment persisted before execution. Original
client, subject, business generation, integer amount, currency and quantities
remain pinned. Provider operation keys do not depend on task/attempt IDs.
`BindingPlan` binds each state entry, native owner, child policy, input and
retained scope pair together; a trusted live ownership callback is still
mandatory before native storage. Portable descriptors alone grant no authority.

This first full-refund, pre-handover policy does not implement the remaining
payment, subscription, partial-stock, physical return or replacement variants in
the [consumer acceptance matrix](https://github.com/durable-workflow/workflow/issues/1069#issuecomment-6889952106).
Those requirements remain open. No native scenario, release, installed upgrade,
rollback, real provider operation or production readiness is qualified here.
