# Polyglot Codec Round-Trip Contract

This document is the language-neutral contract for which payload values
round-trip cleanly across the PHP and Python SDKs and which require an
explicit codec adapter at the call site. It sits downstream of the SDK
neutrality contract (`docs/architecture/sdk-neutrality.md`) and the
codec-name advertisement rule (`codec_neutrality`), and is enforced by
the platform conformance suite.

The `payload_codec` envelope tag on every wire payload identifies the
codec used to encode the blob. The language-neutral v2 surface advertises
these universal codecs:

| Codec | Use |
| --- | --- |
| `avro` | Default for new v2 workflows and activities. The blob is a base64-encoded Avro generic-wrapper around a JSON document. |
| `json` | Explicit interop envelope for UTF-8 JSON payloads. Workers and control-plane poll responses can decode it when an external SDK or previously persisted row already tagged the payload as JSON. |

Legacy PHP history can still name PHP-engine-specific codecs. Those
codecs are exposed under `payload_codecs_engine_specific["php"]`, not
in the universal `payload_codecs` list, so non-PHP workers are not
required to decode PHP serializer payloads:

| Engine | Codec | Use |
| --- | --- | --- |
| `php` | `workflow-serializer-y` | Legacy PHP SerializableClosure payloads with byte-escape encoding. |
| `php` | `workflow-serializer-base64` | Legacy PHP SerializableClosure payloads with base64 encoding. |

## Round-trip categories

The contract sorts every value that can appear on the wire into one of
three categories. The category determines whether the value crosses the
boundary unchanged, crosses with a documented loss, or requires an
explicit adapter the workflow author writes before encode.

### Clean round-trip

These values are JSON-native in both languages and round-trip with
identical observable behaviour:

| Wire shape | PHP type | Python type |
| --- | --- | --- |
| `null` | `null` | `None` |
| `boolean` | `bool` | `bool` |
| `integer` | `int` | `int` |
| `number` | `float` | `float` |
| `string` | `string` | `str` |
| `array` | indexed `array<int, mixed>` | `list[Any]` |
| `object` | associative `array<string, mixed>` | `dict[str, Any]` |

Both universal codecs accept any JSON document built from this set. New
PHP-authored v2 payloads write the default `payload_codec: "avro"`
envelope; workers and control-plane clients also read explicit
`payload_codec: "json"` envelopes without further configuration.

### Round-trip with documented coercion

These values decode in both languages but to a different concrete type
on the receiving side. Workflows that need the original concrete type
must adapt the value back at the consumer.

| Producer | Wire shape | Consumer | Coercion |
| --- | --- | --- | --- |
| PHP `int` outside the JS-safe range (above 2^53-1) | JSON `number` | Python `int` | No loss in Python; PHP to Python preserves precision because the universal codecs carry the integer as a JSON integer token. Avoid routing these values through JSON processors that coerce all numbers to floating point. |
| Python `IntEnum` / `StrEnum` | JSON scalar | PHP `int`/`string` | The receiver sees the raw scalar. Re-attach the enum class on the consumer side if it is significant. |
| Python `Decimal` | JSON `string` (via `to_avro_payload_value`) | PHP `string` | The receiver must re-parse to its money/fixed-point type. |
| Python `datetime` / `date` / `time` | ISO 8601 `string` | PHP `string` (parse with `Carbon`/`DateTimeImmutable`) | Time zone is preserved when the producer emits a tz-aware `datetime`; naive datetimes are wire-ambiguous and SHOULD be avoided. |
| Python `UUID` | JSON `string` | PHP `string` | Parse on the consumer with `Ramsey\Uuid\Uuid::fromString()` or equivalent. |
| Empty PHP `array` `[]` | JSON `[]` (always) | Python `list` | The PHP encoder always tags an empty `array` as a JSON list. Producers that need an empty mapping must encode `(object)[]` (`stdClass`) or `[]` typed as `array<string, mixed>` via an explicit adapter. |

### Requires an explicit adapter at the call site

These values are not universal-codec payload safe. The producer MUST adapt
them to a value in the clean round-trip set before encode, or the
encoder raises:

- Python `dataclasses` instances (use `to_avro_payload_value`,
  `dataclasses.asdict`, or a hand-written serializer)
- Python `attrs` classes (the SDK's `_attrs_payload_dict` helper covers
  them, but the producer is still opting in)
- Python `pydantic` models (the SDK calls `model_dump(mode="json")`;
  any custom `to_dict` should match that contract)
- Python `pendulum` values (convert with `.isoformat()`)
- Python `bytes` / `bytearray` (encode as base64 `string` or split
  into a `dict` with explicit `encoding` and `data` fields)
- Python `set` / `frozenset` (convert to a sorted `list`)
- Python custom objects without a registered adapter
- PHP objects that are not plain `stdClass` or arrays (the workflow
  package's serializer rejects them at the boundary; convert to an
  associative array before scheduling the activity or workflow)
- PHP closures and resources (rejected unconditionally)
- PHP `BackedEnum` values (convert to `->value` before scheduling)

A producer that does not adapt one of these values gets a synchronous
`TypeError` (Python) or `WorkflowPayloadDecodeException` (PHP) at the
call site. The error never crosses the worker protocol; the workflow
never advances on an unadapted value. This is intentional: the codec
boundary is the only place where the workflow author can choose how a
language-specific shape is represented in durable history.

## Test surfaces

The round-trip contract is exercised in CI from three places. A change
to any of the three SHOULD be co-landed with a change to the other two
when it crosses category boundaries:

- `sdk-python` — `tests/test_serializer.py` covers Python encode/decode
  for every category and the producer-side rejection of unadapted
  values.
- `sdk-python` — `tests/integration/test_polyglot.py` exercises real
  PHP↔Python interop through a running server and asserts the
  receiving language observes the documented coerced type.
- The sample app (`sample-app`) `polyglot/` smoke runs two scenarios
  end to end against the standalone server: a Python-authored workflow
  on a separate Python image, and a PHP-authored workflow on a real
  Laravel + `durable-workflow/workflow` PHP worker that schedules
  activities handled by a separate Python worker. Both scenarios
  assert that activity arguments and results round-trip with the
  documented codec envelope. The smoke is wired into the sample-app
  `polyglot-validation` GitHub Actions workflow on every push and
  pull request and fails fast if the PHP worker is removed or refuses
  to register on the polyglot task queue.

The sample app's polyglot smoke is a release gate alongside the
sdk-python integration tests: a regression in either is a release
blocker for both packages.

## Operator guidance

Operators of polyglot fleets SHOULD:

- Pin `avro` as the default write codec for new v2 payloads unless a
  namespace has an explicit policy to author JSON envelopes. Keep both
  `avro` and `json` available as universal decode codecs. Expose legacy
  PHP serializer codecs only through the engine-specific codec list when
  old PHP history still needs to drain.
- Treat the `Requires an explicit adapter` set as a workflow-author
  contract, not a runtime fallback. The SDKs deliberately fail closed
  rather than guess at a serialisation for these values.
- Audit search attributes and memos with the same categories. They
  cross the same payload boundary, and the same adapters apply.

A fuller worked example, with side-by-side PHP and Python snippets, is
in the public docs under `polyglot/codec-roundtrip`.
