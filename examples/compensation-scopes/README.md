# Order and Shipment compensation (stable API)

This example uses only the stable compensation-scope API (#1063):
`DurableScope`, `Obligation`, `ScopeIdentity`, `participate()`, the handler contract
(`ScopedOperation`, `OperationContext`, `ForwardResult`, `Resolution`, `HandlerRegistry`),
`ScopeAuthorization` with `ScopeAuthorizationRefused`, `ScopeRecovery` and `ScopeRetention`. It does not use
`reserveChild()`, the persistence classes or the descriptor codec. The
[business state-machine example](../business-state-machines/README.md#native-order-and-shipment-compensation)
covers the experimental `reserveChild()` path.

- `OrderWorkflow` reserves stock, registers the Shipment scope as a participant with
  `participate()`, and only then starts `ShipmentWorkflow` as a native child.
- `ShipmentWorkflow` books a carrier in its own scope.
- `LedgerOperation` is one handler per action. Both directions are idempotent by operation ID,
  and a forward call after compensation already recorded the operation as absent returns
  `ForwardResult::rejected()`.
- `OrderScopeAuthorization` refuses unknown operations and foreign principals with
  `ScopeAuthorizationRefused`.
- `OrderCompensation` is the host wiring a service provider would do. Hosts resolve
  `ScopeRecovery` and `ScopeRetention` from the container.

Two flows are covered by `tests/Feature/V2/V2StableCompensationExampleTest.php`:

1. Cancellation while the Order waits: the request routes to the open Order run, whose saga
   compensates the Shipment scope first and then the stock reservation. The run closes as
   cancelled.
2. Cancellation after the Order completed: the request routes to canonical recovery, which
   compensates in the same child-first order. `describe()` then reports both scopes verified,
   and the host retires them with `ScopeRetention::release()` (`OrderCompensation::release()`),
   after which `describe()` reports them released.

```bash
vendor/bin/phpunit tests/Feature/V2/V2StableCompensationExampleTest.php
```
