# Original requested units and warehouse disposition

`StockAllocation` is the source data model for the missing partial-stock policies
in #1069. Each original requested unit keeps its ordinal and one disposition:
held and suitable, unavailable, damaged, or missing. The complete original
reservation descriptor is retained, including owner, generation, SKU and quantity.
Consumers must compare it with their pinned original reservation.

For three requested units with two held, the caller explicitly chooses partial
fulfillment, waiting for the complete order, or full cancellation. The plan keeps
the fulfilled, unavailable and required-refund unit ordinals separate. Damaged or
missing reserved units stop fulfillment under every policy. Cancellation releases
only suitable held units; it never classifies damaged or absent units as saleable.

The result is a plan. This type does not authenticate warehouse observations,
mutate inventory, execute a shipment or prove reimbursement. Before payment,
there is no money to refund. After payment, a refund request is not confirmed
repayment. Reservation release and physical restocking remain different
operations.

The common payment-first checkout now consumes these plans natively; see
"Partial stock on the common payment-first checkout" in [README.md](README.md).
It retains the warehouse receipts, serializes the selected policy with
cancellation, fixes the waiting deadline at original admission and binds the
shortage repayment to the original paid unit allocations. Physical stock
deduction and partial customer returns remain unfinished.

The detailed per-unit source representation is bounded to 1,000 requested units.
Larger or multi-line allocations require an explicit extension; units are never
silently dropped or truncated.

## Disposable warehouse provider

`StockWarehouse` is a separate SQLite simulator for original reservation and
release commands. It pins the complete original reserve/release pair, records
per-unit damage or missing-stock observations, and commits physical counters with
receipts in its owned transaction. Duplicate commands do not consume or return
stock again. An observation key cannot move to another unit or reservation.

Releasing a damaged or partly missing reservation returns only suitable held units
to the available counter. Damaged and missing counters remain separate. A receipt
write failure rolls back the counter update, and a new PHP process can replay the
committed original release from stored rows without reseeding inventory. Reads
and writes refuse workflow replay and caller-owned transactions. A goods receipt is
idempotent per delivery-note key, and `allocate()` assigns newly available
suitable stock to an open reservation's unavailable original units. This is a
disposable provider source component, not a real warehouse.
