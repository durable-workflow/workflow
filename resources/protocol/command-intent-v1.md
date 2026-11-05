# Command intent source vectors

`command-intent-v1-vectors.json` records Core's existing `CommandIntent`
encoding and digest behavior from #877. `CommandIntentVectorTest` loads every
case and compares it with the actual implementation. This is a source contract
for the shared command receipt work in durable-workflow/.github#216 and
durable-workflow/server#934. It does not advertise a native receipt capability.

## Typed representation

The fixture uses tagged values so JSON cannot erase Avro distinctions:

| Tag | Representation |
| --- | --- |
| `null` | No value member. |
| `bool` | JSON Boolean `value`. |
| `int` | Canonical signed 64-bit decimal string `value`. |
| `double` | Sixteen lowercase hex `bits`, big-endian IEEE 754 binary64. |
| `string` | UTF-8 `value`, with no Unicode normalization. |
| `bytes` | Canonical padded `base64`. |
| `array` | Ordered `items`. |
| `map` | Unique string-keyed `[key, value]` pairs in `entries`. |

`canonical_base64` contains the exact canonical bytes, including arbitrary
binary values and string byte lengths. Map keys sort by UTF-8 bytes. Array
order remains significant. Integer/double, bytes/text, empty array/map,
composed/decomposed Unicode and positive/negative zero remain distinct.
Command digests cover operation kind, command name, argument list and explicit
target scope/run. Instance-scoped intent does not acquire an explicit run fence
from an unused run argument. Namespace, instance identity and the caller key
belong to receipt lookup scope; they are not extra fields silently added to
Core's existing digest.

## Native input boundaries

`native_input` separates ordinary finite Avro values from two Core conventions:

- Core has canonical tokens for non-finite doubles. Native ingress currently
  refuses those values. The tokens do not authorize a new wire value.
- Core's sole `__dw_intent` map member substitutes its declared string as a
  protected-value token. It is not authenticated plaintext proof. An untrusted
  map or ciphertext cannot acquire verified native intent by presenting that
  marker. No encryption transform or protected-input admission is implemented
  by these vectors.

Core's object serialization, DateTime, backed-enum and Stringable fallbacks are
embedded conventions outside this typed Avro fixture. A portable caller must
map an application value into an already supported typed value; this file does
not invent an object wire encoding.

`native_argument_cases` binds the existing native request-root mapping at
Server `d8eb3bc1a5368752be823784521a9a09ec44de71`: an Avro array is the argument
list, while a nonempty outer map projects values in sorted key order. Empty
maps and scalar roots refuse. Nested maps remain map values. The PHP test
checks this source mapping against the expected canonical arguments; it does
not execute or qualify the native adapter.

## Receipt and external-payload authority

Server owns the companion admission, concurrency, closure, continuation,
pruning and read-later cases linked to these canonical IDs. The native source
does not yet implement the complete receipt authority. Canonical value equality
alone does not prove that a command was admitted or retained.

A registered external payload needs its verified codec, reference, size/hash
and typed datum bound to retained intent. A URI alone is insufficient. The
reviewed binding must allow authorized recovery of an existing receipt without
fetching an original object that has become unavailable. Changed, corrupt,
foreign or unsupported input cannot prove equality or authorize new admission.
Provider `external_storage` remains unsupported by the native path. This
artifact supplies no external fetch, transform, new receipt TTL or schema.

A genuine historical receipt with unverifiable intent is distinct from a
missing or pruned receipt. Neither unavailable intent nor missing admission
permits automatic resend. Read-later retains its separate authorization and
pending-versus-completed-null semantics. Those outcomes must be qualified by
the owning backend and installed consumer cohort before capability publication.
