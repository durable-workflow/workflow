# Published service grouped-condition reproduction

This fresh consumer uses the published Rust SDK pinned in `Cargo.toml`. Point it
at an isolated Server fixture with namespace `default` and token `test-token`.
Run language tooling in the repository runtime or an ephemeral Rust container.

```sh
DURABLE_WORKFLOW_SERVER_URL=http://server:8080 \
  cargo run --manifest-path tests/fixtures/service-grouped-condition-reopen-rust/Cargo.toml
```

The consumer exercises scalar, nested parallel and keyed selection condition
waits through actual Worker registration, polling and completion. One vote is
insufficient for its two-vote predicate. Each case must durably open a second
physical wait. It prints a bounded JSON outcome/history summary and exits 1 if
the Server rejects work.

The unchanged published counterfactual and exact tuple are retained in
[Workflow #601](https://github.com/durable-workflow/workflow/issues/601).
The cancellation source cases remain in Rust #55 and shared issue #136.
