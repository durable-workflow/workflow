# Polyglot Codec Round-Trip Contract

This document is the language-neutral contract for which payload values
round-trip cleanly across the PHP and Python SDKs and which require an
explicit codec adapter at the call site. It sits downstream of the SDK
neutrality contract (`docs/architecture/sdk-neutrality.md`) and the
codec-name advertisement rule (`codec_neutrality`), and is enforced by
the platform conformance suite.

The `payload_codec` envelope tag on every wire payload identifies the
codec used to encode the blob. The language-neutral v2 surface advertises
one universal codec:

| Codec | Use |
| --- | --- |
| `avro` | Required for every v2 workflow payload. The blob is a base64-encoded Avro single-object frame containing the fixed recursive `durable_workflow.protocol.Value` schema. JSON remains the HTTP document transport, not a payload codec. |

Legacy PHP v1 history can still name PHP-engine-specific serializers only at
the internal import/drain boundary. They are not v2 SDK choices and are not
advertised as engine-specific or universal codecs:

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

These values have direct branches in the fixed Avro Value schema and
round-trip with identical observable behaviour:

| Wire shape | PHP type | Python type |
| --- | --- | --- |
| `null` | `null` | `None` |
| `boolean` | `bool` | `bool` |
| `integer` | `int` | `int` |
| `double` | `float` | `float` |
| `string` | `string` | `str` |
| `bytes` | `AvroBinaryValue` | `bytes` |
| `ArrayValue` | indexed `array<int, mixed>` | `list[Any]` |
| `MapValue` | associative `array<string, mixed>` or `AvroMapValue` | `dict[str, Any]` |

The Avro codec selects a named branch for every value, so booleans cannot be
inferred as numbers and bytes cannot be inferred as text. New PHP-authored v2
payloads write the default `payload_codec: "avro"` envelope; workers and
control-plane clients exchange JSON HTTP documents whose durable payload
envelopes are explicitly tagged `payload_codec: "avro"`.

For PHP producers, the supported set is exactly `null`, `bool`, `int`, finite
`float`, valid UTF-8 `string`, `AvroBinaryValue`, list arrays, and string-keyed
map arrays (or `AvroMapValue`). Every nested value must come from the same set.
The encoder uses `array_is_list()` to distinguish a list from a map; it does not
treat an object as a map.

### Round-trip with documented coercion

These values decode in both languages but to a different concrete type
on the receiving side. Workflows that need the original concrete type
must adapt the value back at the consumer.

| Producer | Wire shape | Consumer | Coercion |
| --- | --- | --- | --- |
| PHP `int` outside the JS-safe range (above 2^53-1) | Avro `long` | Python `int` | The Avro path retains all signed 64-bit integer values. Avoid routing these values through JSON processors that coerce all numbers to floating point. |
| Python `IntEnum` / `StrEnum` | Avro `LongValue` / `StringValue` | PHP `int`/`string` | The receiver sees the adapted primitive. Re-attach the enum class on the consumer side if it is significant. |
| Python `Decimal` | Avro `StringValue` (via `to_avro_payload_value`) | PHP `string` | The receiver must re-parse to its money/fixed-point type. |
| Python `datetime` / `date` / `time` | Avro `StringValue` containing ISO 8601 text | PHP `string` (parse with `Carbon`/`DateTimeImmutable`) | Time zone is preserved when the producer emits a tz-aware `datetime`; naive datetimes are wire-ambiguous and SHOULD be avoided. |
| Python `UUID` | Avro `StringValue` | PHP `string` | Parse on the consumer with `Ramsey\Uuid\Uuid::fromString()` or equivalent. |
| Empty PHP `array` `[]` | Avro `ArrayValue` | Python `list` | PHP uses `array_is_list()`, so `[]` is a list. Use `AvroMapValue::fromPairs([])` when the intended value is an empty map. |

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
- Python `bytearray` (convert to native `bytes`, which uses Avro `BytesValue`)
- Python `set` / `frozenset` (convert to a sorted `list`)
- Python custom objects without a registered adapter
- PHP `stdClass`, domain objects, and every other arbitrary object (the encoder
  does not inspect object properties or call `jsonSerialize`; explicitly
  convert the object and every nested value to a supported associative array
  before scheduling the activity or workflow)
- PHP closures and resources (rejected unconditionally)
- PHP `BackedEnum` values (convert to `->value` before scheduling)

A producer that does not adapt one of these values gets a synchronous
`TypeError` (Python) or `InvalidArgumentException` with an
`unsupported_value_type` diagnostic (PHP) at the call site. The producer-side
error occurs before an envelope is written and never crosses the worker
protocol; the workflow never advances on an unadapted value.

`WorkflowPayloadDecodeException` describes the other direction: v2 workflow
ingress wraps a consumer-side failure to decode a received command, signal, or
update payload in that exception, retaining the codec and underlying cause for
diagnostics. It is not raised for producer-side adaptation failures. This
separation is intentional: the encode boundary is where a workflow author can
choose how a language-specific shape is represented in durable history, while
the decode exception reports that received history could not be consumed.

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

- Use `avro` as the universal payload codec for every v2 SDK and service.
- Treat the `Requires an explicit adapter` set as a workflow-author
  contract, not a runtime fallback. The SDKs deliberately fail closed
  rather than guess at a serialisation for these values.
- Audit search attributes and memos with the same categories. They
  cross the same payload boundary, and the same adapters apply.

A fuller worked example, with side-by-side PHP and Python snippets, is
in the public docs under `polyglot/codec-roundtrip`.
