# Accepted signals at terminal task boundaries

Use a disposable PHP Server database and a normally installed Python SDK.
Provide `SERVER_URL`, `AUTH_TOKEN` and optionally `NAMESPACE` (default `default`),
then run `python consumer.py`.

The fixture runs six real Workers per namespace. An HTTPX request hook pauses each Worker's
first terminal request after it has replayed history. The public signal call
then accepts an int64/null/Unicode value, and the original terminal request
continues. No HTTP response is replaced. Each case retains its original
30-second observation, 120-second execution and 60-second run budgets.

Both ordinary completion and continue-as-new give a declared signal a delivery
turn on its admitted run. The actual handler must see the exact value, history
must contain one `SignalApplied`, and the run chain must close once. These
Workers register before admission so the run has a durable declaration snapshot.

Four additional cases start before registration. With those legacy unknown
declarations, neither a matching nor an absent SDK handler establishes delivery
authority. Both signals must end explicitly rejected, with no handler invocation
or `SignalApplied`. Their original acceptance remains in history. A legacy
signal cannot cross continuation merely because its original receipt exists.
Continue-as-new arguments remain authored by the workflow; this test does not
assume arbitrary handler state is automatically copied into a successor.

The source suite additionally covers signals that cannot be applied: they have
an explicit rejected disposition when their run closes. The reference script
uses the client's HTTPX transport only as a test barrier and response observer.
It does not install an application transport override or require an SDK change.

Run this against both the preceding published tuple and the corrected tuple.
Keep failed observations separate. Retire the task database, Workers, installed
dependencies and raw responses after reading the result.
