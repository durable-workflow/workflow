# Original root scope admission

This experimental source API reserves one original native run and its compensation
scope before the application dispatches the workflow. It closes the gap between
ordinary native admission and the first recorded scope-registration activity. It
is not included in the current stable package. The configured StateMachine
examples and complete customer scenarios require their own integration and
qualification.

The host creates a `RootIdentity` with the original `ScopeIdentity`, workflow
class, definition and input fingerprints, routing, codec, worker compatibility,
and complete start options. `RootIdentity::forWorkflow()` is a trusted authoring
helper. Stored identities use `fromArray()`, which treats historical class names
as data and does not resolve or autoload them. The scope generation is the
original business generation. The reserved native run ID is a separate retained
value; do not replace either after a crash or deployment.
Durably retain the original identity before the first reservation attempt. If its
acknowledgement is lost, load with that identity. Rebuilding an identity from a
new deployed definition is not recovery of the original intent.

Construct `RootReservationStore` with mandatory `RootAuthorization` and
`ScopeAuthorization` implementations. The trusted principal must come from the
host. Joint root authorization must bind the scope, owner, generation, definition,
input and start profile as one relationship. Separate permissions for unrelated
parts do not authorize their pairing. Both policy versions are retained and must
remain available for start and closure. Host preparation runs outside the native
storage transaction and outside workflow replay.

1. `reserve($identity, $principal)` retains the original intent and run ID. It
   creates a permanent native instance fence and a `root_reserved` scope row.
   This row is not an opened compensation ledger. Ordinary scope registration,
   inspection, requests and recovery cannot treat it as an empty verified scope.
2. `start($reservation, $principal, $arguments)` admits only the original input,
   definition and profile. The original run, start command and history, genuine
   scope opening, retained admission receipt and initial task commit in one SQL
   transaction. Task dispatch follows commit.
3. `close($reservation, $principal)` locks and rereads the same native fence. If
   closure wins before admission, it permanently forbids admission and returns
   `NeverAdmittedRoot`. Repeating close returns the same closure ID. A later
   start returns that closure, without creating a run or task. If admission wins,
   close returns the original `RootBinding`, even after run-detail pruning.

`load()` recovers the original descriptor without choosing a deployed definition.
Missing lookup results, missing scopes, technical workflow closure and uncertain
native pointers never prove non-admission. A constructed or deserialized receipt
is not authoritative evidence. Reread the store under host authorization before
using a closure outcome in a business decision.

After admission, cancellation must use the genuine original scope and the normal
retained compensation contracts. `RootBinding` proves admission, not completed
forward work or verified compensation. This API does not certify child outcomes,
settle an unresolved root, implement provider cancellation, or grant a StateMachine
transition.

Ordinary start, signal-with-start, retry, redrive, continue-as-new and history
import must not bypass the instance fence. Schema rollback refuses to discard
root reservation metadata while any fence exists. Closed unused fences are
permanent, not run-retention candidates. Existing unreserved workflows keep their
ordinary start behavior.
