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
mutate inventory, execute a shipment or prove reimbursement. Native business
integration must retain authoritative warehouse receipts, serialize the selected
policy with cancellation and physical deduction, fix the waiting deadline at
original admission, and bind refunds to the original paid unit allocations.
Before payment, there is no money to refund. After payment, a refund request is
not confirmed repayment. Reservation release and physical restocking remain
different operations. These execution and accounting requirements are unfinished.

The detailed per-unit source representation is bounded to 1,000 requested units.
Larger or multi-line allocations require an explicit extension; units are never
silently dropped or truncated.
