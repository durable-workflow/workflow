# Changelog

## 2.0.0-alpha.179

Workflow 2.0.0-alpha.179 keeps the Durable Workflow 2.0 PHP package
conformance claim aligned to platform conformance suite version 12. For this
alpha, upgrade-path migration runtime coverage remains outside the release
claim; claiming that category requires a versioned migration scenario manifest
and published-artifact conformance evidence.

- `php artisan workflow:v2:replay-conformance` now reports `outcome: pass`
  when every Workflow PHP replay shard scenario passes, so host replay
  conformance can compose the PHP shard with Python and server evidence
  without treating the shard itself as non-passing.
