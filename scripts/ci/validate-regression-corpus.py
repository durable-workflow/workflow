#!/usr/bin/env python3
"""Validate immutable replay and payload-codec regression evidence."""

from __future__ import annotations

import argparse
import base64
import binascii
import fnmatch
import hashlib
import json
import math
import os
import re
import shutil
import subprocess
import sys
import tempfile
from collections import Counter
from collections.abc import Mapping, Sequence
from dataclasses import dataclass
from functools import lru_cache
from pathlib import Path
from typing import Any

POLICY_SCHEMA = "durable-workflow.regression-corpus-policy/v1"
CODEC_SCHEMA = "durable-workflow.codec-regression/v1"
REPLAY_SCHEMA = "durable-workflow.replay-regression/v1"
GOLDEN_HISTORY_SCHEMA = "durable-workflow.golden-history.v1"
MALFORMED_SERVICE_RESPONSE_ENVELOPE = "malformed_service_response_envelope"
SEARCH_ATTRIBUTE_TYPE_IDENTITY_MISMATCH = "search_attribute_type_identity_mismatch"
UNSUPPORTED_PAYLOAD_CODEC = "unsupported_payload_codec"
PHP_GOLDEN_REPLAY_WORKFLOW = "Tests\\Fixtures\\V2\\TestGoldenReplayWorkflow"
PHP_REPLAY_CONSUMERS = {
    "embedded-history-import",
    "query-state-replayer",
    "workflow-executor",
    "workflow-fiber-runner",
}
PHP_GOLDEN_HISTORY_FAMILIES = {
    "activity",
    "saga-compensation",
    "signal-update",
    "version-marker",
    "wait-condition",
}
SUPPORTED_FORMATS = {
    "avro-value-golden-v1",
    "codec-regression-v1",
    "golden-history-v1",
    "replay-regression-v1",
}
SUPPORTED_CATEGORIES = {"codec", "replay"}
SUPPORTED_BINDINGS = {"php", "python", "rust"}
OFFICIAL_BINDING_FIXTURE_SELECTORS = {
    "php": {
        (
            "codec",
            "resources/protocol/avro-value-v1-golden.json",
            "avro-value-golden-v1",
        ),
        (
            "codec",
            "tests/Fixtures/V2/CodecRegression/*.json",
            "codec-regression-v1",
        ),
        (
            "replay",
            "tests/Fixtures/V2/GoldenHistory/*.json",
            "golden-history-v1",
        ),
        (
            "replay",
            "tests/Fixtures/V2/ReplayRegression/*.json",
            "replay-regression-v1",
        ),
    },
}
OFFICIAL_BINDING_CONSUMERS = {
    (
        "php",
        "codec",
        "resources/protocol/avro-value-v1-golden.json",
        "avro-value-golden-v1",
    ): (
        "php",
        "vendor/bin/phpunit",
        "--colors=never",
        "--filter",
        "testCanonicalSchemaFingerprintAndGoldenBytes|"
        "testSharedTrailingBytesFrameIsRejected|"
        "testSharedAlternateMapOrdersDecodeToTheSameNestedValue",
        "tests/Unit/Serializers/AvroValueProtocolTest.php",
    ),
    (
        "php",
        "codec",
        "tests/Fixtures/V2/CodecRegression/*.json",
        "codec-regression-v1",
    ): (
        "php",
        "vendor/bin/phpunit",
        "--colors=never",
        "--filter",
        "testCheckedInCodecRegressionCorpusUsesTheOfficialBinding|"
        "testEncodeRejectExecutionDoesNotReadOptionalWire",
        "tests/Unit/Serializers/AvroValueProtocolTest.php",
    ),
    (
        "php",
        "replay",
        "tests/Fixtures/V2/GoldenHistory/*.json",
        "golden-history-v1",
    ): (
        "php",
        "vendor/bin/phpunit",
        "--colors=never",
        "--testsuite",
        "feature",
        "--filter",
        "testPhpGoldenHistoryReplayContract",
        "tests/Feature/V2/V2GoldenHistoryReplayTest.php",
    ),
    (
        "php",
        "replay",
        "tests/Fixtures/V2/ReplayRegression/*.json",
        "replay-regression-v1",
    ): (
        "php",
        "vendor/bin/phpunit",
        "--colors=never",
        "tests/Unit/V2/ReplayRegressionCorpusTest.php",
        "tests/Feature/V2/V2EmbeddedReplayRegressionCorpusTest.php",
    ),
}
OFFICIAL_BINDING_CONSUMER_SUPPORT = {
    OFFICIAL_BINDING_CONSUMERS[
        (
            "php",
            "codec",
            "tests/Fixtures/V2/CodecRegression/*.json",
            "codec-regression-v1",
        )
    ]: (
        "tests/Unit/Serializers/AvroValueProtocolTest.php",
    ),
    OFFICIAL_BINDING_CONSUMERS[
        (
            "php",
            "replay",
            "tests/Fixtures/V2/ReplayRegression/*.json",
            "replay-regression-v1",
        )
    ]: (
        "tests/Feature/V2/V2EmbeddedReplayRegressionCorpusTest.php",
        "tests/Fixtures/V2/TestConstructorInjectionActivity.php",
        "tests/Fixtures/V2/TestConstructorInjectionWorkflow.php",
        "tests/Fixtures/V2/TestPortableLocalActivityIdentityWorkflow.php",
        "tests/Fixtures/V2/TestSequentialChildReplayWorkflow.php",
        "tests/Fixtures/V2/TestServiceResponseReplayWorkflow.php",
        "tests/Fixtures/V2/TestSignalResumedParallelWorkflow.php",
        "tests/Unit/V2/ReplayRegressionCorpusTest.php",
    ),
}
FAILURE_EVENT_FALLBACK_MESSAGES = {
    "ActivityFailed": "Activity failed",
    "ActivityCancelled": "Activity cancelled",
    "ActivityTimedOut": "Activity timed out",
    "ChildRunFailed": "Child workflow failed",
    "ChildRunCancelled": "Child workflow cancelled",
    "ChildRunTerminated": "Child workflow terminated",
    "ServiceCallFailed": "Service operation failed.",
    "ServiceCallCancelled": "Service operation cancelled.",
}
REPLAY_EVENT_PAYLOAD_FIELDS = {
    "WorkflowStarted": {"compatibility", "workflow_type"},
    "ActivityScheduled": set(),
    "ActivityStarted": set(),
    "ActivityHeartbeatRecorded": set(),
    "ActivityRetryScheduled": set(),
    "ActivityCompleted": {"payload_codec", "result"},
    "ActivityFailed": set(),
    "ActivityCancelled": set(),
    "ActivityTimedOut": set(),
    "TimerScheduled": {"timer_kind"},
    "TimerCancelled": {"timer_kind"},
    "TimerFired": {"signal_name", "signal_wait_id", "timer_kind"},
    "ConditionWaitOpened": {
        "condition_definition_fingerprint",
        "condition_key",
        "condition_wait_id",
        "condition_wait_occurrence_id",
    },
    "ConditionWaitSatisfied": {
        "condition_definition_fingerprint",
        "condition_key",
        "condition_wait_id",
        "condition_wait_occurrence_id",
    },
    "ConditionWaitTimedOut": {
        "condition_definition_fingerprint",
        "condition_key",
        "condition_wait_id",
        "condition_wait_occurrence_id",
    },
    "SignalWaitOpened": {"signal_name", "signal_wait_id"},
    "SignalReceived": {
        "arguments",
        "payload_codec",
        "signal_name",
        "signal_wait_id",
    },
    "SignalApplied": {
        "arguments",
        "payload_codec",
        "signal_name",
        "signal_wait_id",
        "value",
    },
    "ChildWorkflowScheduled": set(),
    "ChildRunStarted": set(),
    "ChildRunCompleted": {"output", "payload_codec"},
    "ChildRunFailed": set(),
    "ChildRunCancelled": set(),
    "ChildRunTerminated": set(),
    "ServiceCallStarted": {
        "endpoint_name",
        "operation_mode",
        "operation_name",
        "outcome",
        "payload_codec",
        "response_payload",
        "service_call",
        "service_call_id",
        "service_name",
        "status",
        "wait_for",
    },
    "ServiceCallCompleted": {
        "endpoint_name",
        "operation_mode",
        "operation_name",
        "outcome",
        "payload_codec",
        "response_payload",
        "service_call",
        "service_call_id",
        "service_name",
        "status",
        "wait_for",
    },
    "ServiceCallFailed": set(),
    "ServiceCallCancelled": set(),
    "SideEffectRecorded": {"payload_codec", "result"},
    "VersionMarkerRecorded": {"change_id", "version"},
    "SearchAttributesUpserted": {"attributes", "attribute_types"},
    "UpdateAccepted": {
        "arguments",
        "payload_codec",
        "update_id",
        "update_name",
    },
    "UpdateApplied": {
        "arguments",
        "payload_codec",
        "update_id",
        "update_name",
    },
}
PHP_NUMERIC_STRING = re.compile(
    r"[+-]?(?:(?:[0-9]+(?:\.[0-9]*)?)|(?:\.[0-9]+))(?:[eE][+-]?[0-9]+)?"
)
PHP_INTEGER_STRING = re.compile(r"[+-]?[0-9]+")
PHP_INT_MIN = -(2**63)
PHP_INT_MAX = 2**63 - 1
PHP_REPLAY_DECODED_FIELDS = {
    "ActivityCompleted": ("result",),
    "SideEffectRecorded": ("result",),
    "SignalReceived": ("arguments",),
    "SignalApplied": ("value", "arguments"),
    "UpdateAccepted": ("arguments",),
    "UpdateApplied": ("arguments",),
    "ChildRunCompleted": ("output",),
    "ServiceCallStarted": ("response_payload",),
    "ServiceCallCompleted": ("response_payload",),
}
PHP_GOLDEN_VALUE_FIELDS = {"result", "value", "arguments"}
PHP_CODEC_NAMES = {"avro"}
PHP_CODEC_ALIASES: dict[str, str] = {}
PHP_REPLAY_PAYLOAD_DECODER = Path(__file__).with_name("decode-replay-payload.php")
RUNTIME_DEPENDENCY_PATHS = ("vendor",)
ZERO_COMMIT = re.compile(r"^0+$")
LEGACY_MALFORMED_WIRE_REPAIRS = {
    "%%%": "JSUl",
}


class CorpusError(RuntimeError):
    """The regression-corpus contract is not satisfied."""


@dataclass(frozen=True)
class Evidence:
    category: str
    identity: str
    path: str
    protocol_version: str
    semantic_digest: str
    supersedes: tuple[str, ...] = ()


def _canonical_digest(value: Any) -> str:
    encoded = json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(",", ":")).encode()
    return hashlib.sha256(encoded).hexdigest()


def _object(value: Any, context: str) -> Mapping[str, Any]:
    if not isinstance(value, Mapping):
        raise CorpusError(f"{context} must be an object")
    return value


def _list(value: Any, context: str, *, nonempty: bool = False) -> Sequence[Any]:
    if not isinstance(value, Sequence) or isinstance(value, str | bytes):
        raise CorpusError(f"{context} must be an array")
    if nonempty and not value:
        raise CorpusError(f"{context} must not be empty")
    return value


def _string(value: Any, context: str) -> str:
    if not isinstance(value, str) or not value:
        raise CorpusError(f"{context} must be a non-empty string")
    return value


def _boolean(value: Any, context: str) -> bool:
    if not isinstance(value, bool):
        raise CorpusError(f"{context} must be a boolean")
    return value


def _nullable_string(value: Any, context: str) -> str | None:
    if value is None:
        return None
    return _string(value, context)


def _unique_strings(value: Any, context: str, *, allowed: set[str] | None = None) -> tuple[str, ...]:
    values = tuple(_string(item, f"{context}[]") for item in _list(value, context, nonempty=True))
    if len(values) != len(set(values)):
        raise CorpusError(f"{context} contains duplicates")
    if allowed is not None and not set(values) <= allowed:
        raise CorpusError(f"{context} contains unsupported values: {sorted(set(values) - allowed)}")
    return values


def _json(content: bytes, path: str) -> Mapping[str, Any]:
    try:
        value = json.loads(content)
    except (UnicodeDecodeError, json.JSONDecodeError) as error:
        raise CorpusError(f"{path} is not valid UTF-8 JSON: {error}") from error
    return _object(value, path)


def _canonical_base64(
    value: str,
    context: str,
) -> str:
    try:
        decoded = base64.b64decode(value, validate=True)
    except (binascii.Error, ValueError) as error:
        raise CorpusError(f"{context} is not canonical base64") from error
    canonical = base64.b64encode(decoded).decode("ascii")
    if value != canonical:
        raise CorpusError(f"{context} is not canonical base64")
    return canonical


def _canonical_wire_replacement(value: str) -> str | None:
    """Return the only permitted canonical replacement for a legacy wire."""

    try:
        decoded = base64.b64decode(value, validate=True)
    except (binascii.Error, ValueError):
        return LEGACY_MALFORMED_WIRE_REPAIRS.get(value)

    canonical = base64.b64encode(decoded).decode("ascii")
    return canonical if canonical != value else None


def _avro_golden_migration(base_content: bytes, current_content: bytes) -> bool:
    """Allow one-way repairs of legacy malformed-frame wire metadata."""

    try:
        base_document = json.loads(base_content)
        current_document = json.loads(current_content)
    except (UnicodeDecodeError, json.JSONDecodeError):
        return False
    if not isinstance(base_document, dict) or not isinstance(current_document, dict):
        return False
    base_frames = base_document.get("malformed_frames")
    current_frames = current_document.get("malformed_frames")
    if not isinstance(base_frames, list) or not isinstance(current_frames, list):
        return False
    if len(base_frames) != len(current_frames):
        return False

    migrated = False
    for index, (base_frame, current_frame) in enumerate(
        zip(base_frames, current_frames, strict=True)
    ):
        if not isinstance(base_frame, dict) or not isinstance(current_frame, dict):
            return False
        base_wire = base_frame.get("wire_base64")
        current_wire = current_frame.get("wire_base64")
        if base_wire != current_wire:
            if not isinstance(base_wire, str) or not isinstance(current_wire, str):
                return False
            if current_wire != _canonical_wire_replacement(base_wire):
                return False
            try:
                _canonical_base64(
                    current_wire,
                    f"current.malformed_frames[{index}].wire_base64",
                )
            except CorpusError:
                return False
            base_frame["wire_base64"] = current_wire
            migrated = True

        base_name = base_frame.get("name")
        current_name = current_frame.get("name")
        if base_name != current_name:
            if (
                base_name != "invalid_base64"
                or current_name != "decoded_non_magic_bytes"
                or current_wire != "JSUl"
                or base_frame.get("error") != "invalid_payload_framing"
                or current_frame.get("error") != "invalid_payload_framing"
            ):
                return False
            base_frame["name"] = current_name
            migrated = True

    return migrated and base_document == current_document


def _contains_json_payload_codec(value: Any) -> bool:
    if isinstance(value, dict):
        for key, item in value.items():
            if key in {"codec", "payload_codec"} and item == "json":
                return True
            if _contains_json_payload_codec(item):
                return True
    elif isinstance(value, list):
        return any(_contains_json_payload_codec(item) for item in value)
    return False


def _contains_payload_codec(value: Any, codec: str) -> bool:
    if isinstance(value, dict):
        return any(
            (key in {"codec", "payload_codec"} and item == codec)
            or _contains_payload_codec(item, codec)
            for key, item in value.items()
        )
    if isinstance(value, list):
        return any(_contains_payload_codec(item, codec) for item in value)
    return False


def _replay_codec_migration_shape(document: Mapping[str, Any]) -> Mapping[str, Any]:
    """Project a codec migration pair onto its unchanged replay behavior."""

    normalized = json.loads(json.dumps(document))
    normalized.pop("id", None)
    workflow = normalized.get("workflow")
    if isinstance(workflow, dict) and "payload_codec" in workflow:
        workflow["payload_codec"] = "<payload-codec>"
    history = normalized.get("history")
    if isinstance(history, list):
        for event in history:
            if not isinstance(event, dict):
                continue
            event_type = event.get("event_type")
            payload = event.get("payload")
            if not isinstance(event_type, str) or not isinstance(payload, dict):
                continue
            if "payload_codec" in payload:
                payload["payload_codec"] = "<payload-codec>"
            for field in PHP_REPLAY_DECODED_FIELDS.get(event_type, ()):
                field_value = payload.get(field)
                if isinstance(field_value, dict) and (
                    "codec" in field_value or "blob" in field_value
                ):
                    payload[field] = "<encoded-payload>"
                elif "payload_codec" in payload and field in payload:
                    payload[field] = "<encoded-payload>"
    command_sequence = normalized.get("command_sequence")
    if isinstance(command_sequence, list):
        for step in command_sequence:
            if not isinstance(step, dict):
                continue
            for command in step.get("commands", []):
                if isinstance(command, dict) and "payload_codec" in command:
                    command["payload_codec"] = "<payload-codec>"
    return normalized


def _is_prerelease_json_to_avro_replay_migration(
    previous_content: bytes,
    current_content: bytes,
    path: str,
) -> bool:
    """Permit only behavior-equivalent in-place removal of prerelease JSON payloads."""

    previous = _json(previous_content, path)
    current = _json(current_content, path)
    previous_failure = previous.get("expected_failure")
    current_workflow = current.get("workflow")
    if (
        previous.get("fixture_schema") != REPLAY_SCHEMA
        or current.get("fixture_schema") != REPLAY_SCHEMA
        or previous.get("id") != current.get("id")
        or not isinstance(current_workflow, dict)
        or current_workflow.get("payload_codec") != "avro"
        or not _contains_json_payload_codec(previous)
        or _contains_json_payload_codec(current)
        or not _contains_payload_codec(current, "avro")
        or (
            isinstance(previous_failure, dict)
            and previous_failure.get("type") == UNSUPPORTED_PAYLOAD_CODEC
        )
    ):
        return False
    return _replay_codec_migration_shape(previous) == _replay_codec_migration_shape(
        current
    )


def _replay_semantic(
    *,
    workflow_type: str,
    workflow_input: Any,
    workflow_codec: str,
    replay_namespace: str | None,
    history: Any,
    command_sequence: Any,
    expected: Mapping[str, Any],
    history_import_metadata: Mapping[str, Any] | None = None,
) -> Mapping[str, Any]:
    """Project every replay representation onto consumer-executed values."""

    semantic = {
        "workflow": {
            "type": workflow_type,
            "input": workflow_input,
            "payload_codec": workflow_codec,
            "namespace": replay_namespace,
        },
        "history": history,
        "command_sequence": command_sequence,
        "expected": expected,
    }
    if history_import_metadata is not None:
        semantic["history_import_metadata"] = history_import_metadata
    return semantic


def _consumer_payload(
    value: Mapping[str, Any],
    context: str,
    *,
    event_type: str,
    default_codec: str,
    golden_values: bool,
    expected_failure: str | None = None,
    observed_failures: list[str] | None = None,
) -> Mapping[str, Any]:
    """Return the payload values that reach the replayed workflow."""

    payload = dict(value)
    decoded_fields: set[str] = set()
    for field in PHP_REPLAY_DECODED_FIELDS.get(event_type, ()):
        value_field = f"{field}_value"
        if golden_values and field in PHP_GOLDEN_VALUE_FIELDS and value_field in payload:
            payload[field] = _canonical_php_value(
                payload.pop(value_field),
                f"{context}.{value_field}",
            )
            decoded_fields.add(field)
            continue
        if field not in payload:
            continue

        field_value = payload[field]
        if (
            field == "response_payload"
            and event_type in {"ServiceCallStarted", "ServiceCallCompleted"}
            and not isinstance(field_value, str)
            and not _looks_like_service_response_envelope(field_value)
        ):
            payload[field] = _canonical_php_value(field_value, f"{context}.{field}")
            decoded_fields.add(field)
            continue

        if not isinstance(field_value, (Mapping, str)):
            payload[field] = _canonical_php_value(None, f"{context}.{field}")
            decoded_fields.add(field)
            continue

        service_response_envelope = (
            field == "response_payload"
            and event_type in {"ServiceCallStarted", "ServiceCallCompleted"}
            and _looks_like_service_response_envelope(field_value)
        )
        try:
            payload[field] = _decoded_php_replay_field(
                field_value,
                payload,
                f"{context}.{field}",
                default_codec,
            )
        except CorpusError as error:
            malformed_service_response = (
                service_response_envelope
                and expected_failure == MALFORMED_SERVICE_RESPONSE_ENVELOPE
            )
            unsupported_codec = (
                expected_failure == UNSUPPORTED_PAYLOAD_CODEC
                and "unsupported payload codec" in str(error)
            )
            if not (malformed_service_response or unsupported_codec) or observed_failures is None:
                raise
            observed_failures.append(expected_failure)
            payload[field] = {
                "expected_failure": expected_failure,
                "envelope": _canonical_php_value(field_value, f"{context}.{field}"),
            }
        decoded_fields.add(field)

    if decoded_fields:
        payload.pop("payload_codec", None)
    return payload


def _decoded_php_replay_field(
    field_value: Mapping[str, Any] | str,
    event_payload: Mapping[str, Any],
    context: str,
    default_codec: str,
) -> Mapping[str, str]:
    """Decode one replay payload field with the PHP consumer's codec precedence."""

    envelope_codec = None
    if isinstance(field_value, Mapping):
        envelope_codec = _declared_php_codec(field_value, "codec", context)
        if "blob" not in field_value or not isinstance(field_value["blob"], str):
            raise CorpusError(f"{context} is not a decodable inline payload envelope")
        blob = field_value["blob"]
    else:
        blob = field_value

    event_codec = _declared_php_codec(event_payload, "payload_codec", context.rsplit(".", 1)[0])
    if event_codec is not None and envelope_codec is not None and event_codec != envelope_codec:
        raise CorpusError(
            f"{context} declares conflicting payload codecs "
            f"{event_codec!r} and {envelope_codec!r}"
        )
    codec = event_codec or envelope_codec
    if codec is None:
        codec = _canonical_php_codec(default_codec, f"{context} fallback codec")

    return _decode_php_payload(codec, blob, context)


def _canonical_php_codec(value: str, context: str) -> str:
    """Canonicalize the codec aliases accepted by the PHP replay consumer."""

    canonical = PHP_CODEC_ALIASES.get(value.lstrip("\\"), value)
    if canonical not in PHP_CODEC_NAMES:
        raise CorpusError(
            f"unsupported_payload_codec: {context} declares unsupported payload codec {value!r}"
        )
    return canonical


def _declared_php_codec(
    value: Mapping[str, Any],
    field: str,
    context: str,
) -> str | None:
    """Return a validated explicit codec declaration, when present."""

    if field not in value:
        return None
    codec = value[field]
    if not isinstance(codec, str) or codec == "":
        raise CorpusError(f"{context}.{field} must be a non-empty payload codec")
    return _canonical_php_codec(codec, f"{context}.{field}")


def _looks_like_service_response_envelope(value: Any) -> bool:
    """Match the encoded service-response shapes recognized by the PHP runner."""

    return isinstance(value, Mapping) and (
        isinstance(value.get("blob"), str)
        or isinstance(value.get("external_storage"), Mapping)
    )


def _php_payload_identity(request: Mapping[str, Any], context: str) -> Mapping[str, str]:
    """Project a value through PHP and preserve its exact decoded PHP type."""

    result = _php_payload_consumer(
        json.dumps(request, ensure_ascii=False),
    )
    if result.returncode != 0:
        detail = result.stderr.strip() or result.stdout.strip() or "unknown decoder failure"
        raise CorpusError(f"{context} cannot be decoded by the PHP payload consumer: {detail}")
    identity = result.stdout.strip()
    try:
        base64.b64decode(identity, validate=True)
    except (binascii.Error, ValueError) as error:
        raise CorpusError(
            f"{context} produced an invalid PHP payload identity"
        ) from error
    return {"php_serialized_base64": identity}


@lru_cache(maxsize=None)
def _php_payload_consumer(request: str) -> subprocess.CompletedProcess[str]:
    """Invoke the PHP identity oracle once for each distinct codec input."""

    result = subprocess.run(
        ["php", str(PHP_REPLAY_PAYLOAD_DECODER)],
        input=request,
        text=True,
        capture_output=True,
        check=False,
    )
    return result


def _decode_php_payload(codec: str, blob: str, context: str) -> Mapping[str, str]:
    """Decode one raw blob or inline envelope with the official PHP codec."""

    return _php_payload_identity(
        {"operation": "decode", "codec": codec, "blob": blob},
        context,
    )


def _canonical_php_value(value: Any, context: str) -> Mapping[str, str]:
    """Represent an already-decoded JSON fixture value as PHP observes it."""

    return _php_payload_identity(
        {"operation": "value", "value": value},
        context,
    )


def _consumer_sequence(
    event: Mapping[str, Any],
    payload: Mapping[str, Any],
    *,
    golden_position: int | None,
) -> int | None:
    """Resolve sequence aliases in the same precedence order as the consumers."""

    for value in (
        payload.get("sequence"),
        payload.get("workflow_sequence"),
        golden_position,
        event.get("sequence"),
    ):
        normalized = _php_int_value(value)
        if normalized is not None:
            return normalized
    return None


def _php_int_value(value: Any) -> int | None:
    """Match the runner's integer-or-numeric-string coercion."""

    if isinstance(value, bool):
        return None
    if isinstance(value, int):
        return value if PHP_INT_MIN <= value <= PHP_INT_MAX else None
    if not isinstance(value, str):
        return None
    numeric = value.strip()
    if not PHP_NUMERIC_STRING.fullmatch(numeric):
        return None

    if PHP_INTEGER_STRING.fullmatch(numeric):
        negative = numeric.startswith("-")
        digits = numeric.lstrip("+-").lstrip("0") or "0"
        limit = str(-PHP_INT_MIN if negative else PHP_INT_MAX)
        if len(digits) > len(limit) or (len(digits) == len(limit) and digits > limit):
            return PHP_INT_MIN if negative else PHP_INT_MAX
        normalized = int(digits)
        return -normalized if negative else normalized

    parsed_float = float(numeric)
    if not math.isfinite(parsed_float):
        return 0
    normalized = int(parsed_float)
    return min(max(normalized, PHP_INT_MIN), PHP_INT_MAX)


def _php_array(value: Any) -> bool:
    return isinstance(value, Mapping) or (
        isinstance(value, Sequence) and not isinstance(value, str | bytes)
    )


def _php_array_items(value: Any) -> Sequence[Any]:
    if isinstance(value, Mapping):
        items = value.values()
    elif _php_array(value):
        items = value
    else:
        return []
    return [item for item in items if _php_array(item)]


def _consumer_failure_payload(
    event_type: str,
    payload: Mapping[str, Any],
) -> Mapping[str, Any]:
    """Resolve failure aliases and invalid values exactly as the PHP runner."""

    raw_exception = payload.get("exception")
    exception = raw_exception if isinstance(raw_exception, Mapping) else {}

    outer_class = payload.get("exception_class")
    fallback_class = (
        outer_class
        if isinstance(outer_class, str) and outer_class != ""
        else "RuntimeException"
    )
    outer_message = payload.get("message")
    fallback_message = (
        outer_message
        if isinstance(outer_message, str) and outer_message != ""
        else FAILURE_EVENT_FALLBACK_MESSAGES[event_type]
    )
    fallback_code = _php_int_value(payload.get("code"))
    if fallback_code is None:
        fallback_code = 0

    exception_class = exception.get("class")
    exception_type = exception.get("type")
    exception_message = exception.get("message")
    exception_code = exception.get("code")
    resolved: dict[str, Any] = {
        "class": (
            exception_class if isinstance(exception_class, str) else fallback_class
        ),
        "type": (
            exception_type
            if isinstance(exception_type, str)
            else (
                payload.get("exception_type")
                if isinstance(payload.get("exception_type"), str)
                else None
            )
        ),
        "message": (
            exception_message
            if isinstance(exception_message, str)
            else fallback_message
        ),
        "code": (
            exception_code
            if isinstance(exception_code, int)
            and not isinstance(exception_code, bool)
            and PHP_INT_MIN <= exception_code <= PHP_INT_MAX
            else fallback_code
        ),
    }

    optional_string = exception.get("file")
    if isinstance(optional_string, str) and optional_string != "":
        resolved["file"] = optional_string
    optional_int = exception.get("line")
    if (
        isinstance(optional_int, int)
        and not isinstance(optional_int, bool)
        and PHP_INT_MIN <= optional_int <= PHP_INT_MAX
    ):
        resolved["line"] = optional_int
    for field in ("trace", "properties"):
        if field in exception:
            resolved[field] = _php_array_items(exception[field])
    if "details" in exception:
        resolved["details"] = exception["details"]
    if isinstance(exception.get("non_retryable"), bool):
        resolved["non_retryable"] = exception["non_retryable"]
    details_codec = exception.get("details_payload_codec")
    if isinstance(details_codec, str) and details_codec != "":
        resolved["details_payload_codec"] = details_codec
    for field in ("diagnostics", "runtime_diagnostics"):
        if _php_array(exception.get(field)):
            resolved[field] = exception[field]
    return resolved


def _consumer_event_payload(
    event_type: str,
    payload: Mapping[str, Any],
) -> Mapping[str, Any]:
    """Keep only payload values the cold replay consumer observes."""

    if event_type in FAILURE_EVENT_FALLBACK_MESSAGES:
        return {"exception": _consumer_failure_payload(event_type, payload)}
    fields = REPLAY_EVENT_PAYLOAD_FIELDS.get(event_type, set())
    return {field: payload[field] for field in fields if field in payload}


def _consumer_history(
    value: Any,
    context: str,
    *,
    default_codec: str,
    golden_values: bool,
    expected_failure: str | None = None,
    observed_failures: list[str] | None = None,
) -> tuple[Sequence[Mapping[str, Any]], str | None]:
    """Normalize stored history into the values observed by replay execution."""

    history = _list(value, context, nonempty=True)
    normalized: list[Mapping[str, Any]] = []
    found_workflow_started = False
    started_namespace = None
    history_namespace = None
    for index, raw_event in enumerate(history):
        event_context = f"{context}[{index}]"
        event = _object(raw_event, event_context)
        event_type = _string(event.get("event_type"), f"{event_context}.event_type")
        payload = _consumer_payload(
            _object(event.get("payload"), f"{event_context}.payload"),
            f"{event_context}.payload",
            event_type=event_type,
            default_codec=default_codec,
            golden_values=golden_values,
            expected_failure=expected_failure,
            observed_failures=observed_failures,
        )
        if event_type == "WorkflowStarted" and not found_workflow_started:
            found_workflow_started = True
            raw_namespace = payload.get("namespace")
            if isinstance(raw_namespace, str) and raw_namespace:
                started_namespace = raw_namespace
        if not golden_values and history_namespace is None:
            raw_namespace = event.get("namespace")
            if isinstance(raw_namespace, str) and raw_namespace:
                history_namespace = raw_namespace
        canonical_event: dict[str, Any] = {
            "event_type": event_type,
            "payload": payload,
        }
        sequence = _consumer_sequence(
            event,
            payload,
            golden_position=index + 1 if golden_values else None,
        )
        payload = dict(payload)
        payload.pop("sequence", None)
        payload.pop("workflow_sequence", None)
        canonical_event["payload"] = _consumer_event_payload(event_type, payload)
        if sequence is not None:
            canonical_event["sequence"] = sequence

        # Golden-history event ids, timestamps, and namespaces are ignored when
        # its consumer creates history rows. UpdateApplied ids prevent applying
        # the same event twice; other event ids do not affect replay.
        if not golden_values:
            event_id = event.get("id")
            if event_type == "UpdateApplied" and isinstance(event_id, str) and event_id:
                canonical_event["id"] = event_id
        normalized.append(canonical_event)
    return normalized, started_namespace or history_namespace


def _semantic_codec_value(
    value: Mapping[str, Any],
    context: str,
    *,
    wire_backed: bool,
) -> Mapping[str, Any]:
    """Normalize tagged codec values independently of their fixture format."""

    kind = _string(value.get("type"), f"{context}.type")
    if kind == "null":
        return {"type": kind}
    if kind == "boolean":
        raw_boolean = value.get("value")
        if not isinstance(raw_boolean, bool):
            raise CorpusError(f"{context}.value must be a boolean")
        return {"type": kind, "value": raw_boolean}
    if kind == "long":
        raw_long = value.get("value")
        if isinstance(raw_long, bool) or not isinstance(raw_long, int | str):
            raise CorpusError(f"{context}.value must be an integer string")
        try:
            parsed_long = int(raw_long)
        except ValueError as error:
            raise CorpusError(f"{context}.value must be an integer string") from error
        if not -(2**63) <= parsed_long < 2**63:
            raise CorpusError(f"{context}.value must fit a signed 64-bit integer")
        return {"type": kind, "value": str(parsed_long)}
    if kind == "double":
        raw_double = value.get("value")
        if isinstance(raw_double, bool) or not isinstance(
            raw_double, int | float | str
        ):
            raise CorpusError(f"{context}.value must be a number or numeric string")
        try:
            parsed_double = float(raw_double)
        except ValueError as error:
            raise CorpusError(
                f"{context}.value must be a number or numeric string"
            ) from error
        if math.isnan(parsed_double):
            canonical_double = "nan"
        elif math.isinf(parsed_double):
            canonical_double = "-infinity" if parsed_double < 0 else "infinity"
        else:
            canonical_double = parsed_double.hex()
        return {"type": kind, "value": canonical_double}
    if kind == "bytes":
        aliases = [field for field in ("base64", "value_base64") if field in value]
        if not aliases:
            raise CorpusError(f"{context} must include base64 bytes")
        canonical_bytes: set[str] = set()
        for field in aliases:
            encoded = value[field]
            if not isinstance(encoded, str):
                raise CorpusError(f"{context}.{field} must be a string")
            normalized = _canonical_base64(encoded, f"{context}.{field}")
            if not isinstance(normalized, str):
                raise CorpusError(f"{context}.{field} must contain valid base64")
            canonical_bytes.add(normalized)
        if len(canonical_bytes) != 1:
            raise CorpusError(f"{context} contains conflicting base64 byte values")
        return {"type": kind, "base64": canonical_bytes.pop()}
    if kind == "string":
        raw_string = value.get("value")
        if not isinstance(raw_string, str):
            raise CorpusError(f"{context}.value must be a string")
        return {"type": kind, "value": raw_string}
    if kind == "array":
        if wire_backed:
            return {"type": kind}
        items = _list(value.get("items"), f"{context}.items")
        return {
            "type": kind,
            "items": [
                _semantic_codec_value(
                    _object(item, f"{context}.items[{index}]"),
                    f"{context}.items[{index}]",
                    wire_backed=False,
                )
                for index, item in enumerate(items)
            ],
        }
    if kind == "map":
        if wire_backed:
            return {"type": kind}
        entries = _list(value.get("entries"), f"{context}.entries")
        canonical_entries: dict[str, Mapping[str, Any]] = {}
        for index, raw_entry in enumerate(entries):
            entry_context = f"{context}.entries[{index}]"
            entry = _object(raw_entry, entry_context)
            key = entry.get("key")
            if not isinstance(key, str):
                raise CorpusError(f"{entry_context}.key must be a string")
            if key in canonical_entries:
                raise CorpusError(f"{context}.entries contains duplicate key {key!r}")
            canonical_entries[key] = _semantic_codec_value(
                _object(entry.get("value"), f"{entry_context}.value"),
                f"{entry_context}.value",
                wire_backed=False,
            )
        return {
            "type": kind,
            "entries": [
                {"key": key, "value": canonical_entries[key]}
                for key in sorted(canonical_entries)
            ],
        }
    raise CorpusError(f"{context}.type is unsupported")


def _codec_semantic(
    *,
    value: Mapping[str, Any] | None,
    wire_base64: str | Mapping[str, str] | Sequence[str | Mapping[str, str]] | None,
    operation: str,
    error: str | None,
) -> Mapping[str, Any]:
    """Return one format-neutral identity for payload-codec evidence."""

    semantic = {
        "value": value,
        "failure_policy": {"operation": operation, "error": error},
    }
    if operation != "encode_reject":
        semantic["wire"] = wire_base64
    return semantic


def _fixture_evidence(
    *,
    category: str,
    identity: str,
    path: str,
    protocol_version: str,
    semantic_value: Any,
    supersedes: tuple[str, ...] = (),
) -> Evidence:
    return Evidence(
        category=category,
        identity=identity,
        path=path,
        protocol_version=protocol_version,
        semantic_digest=_canonical_digest(semantic_value),
        supersedes=supersedes,
    )


def _codec_fixture(document: Mapping[str, Any], path: str, binding: str | None) -> list[Evidence]:
    _string(document.get("$schema"), f"{path}.$schema")
    if document.get("fixture_schema") != CODEC_SCHEMA:
        raise CorpusError(f"{path} must declare fixture_schema={CODEC_SCHEMA}")
    identity = _string(document.get("id"), f"{path}.id")
    protocol = _object(document.get("protocol"), f"{path}.protocol")
    _string(protocol.get("codec"), f"{path}.protocol.codec")
    _string(protocol.get("schema"), f"{path}.protocol.schema")
    version = _string(protocol.get("version"), f"{path}.protocol.version")
    _nullable_string(protocol.get("fingerprint"), f"{path}.protocol.fingerprint")
    bindings = _unique_strings(
        document.get("bindings"),
        f"{path}.bindings",
        allowed=SUPPORTED_BINDINGS,
    )
    if binding is not None and binding not in bindings:
        raise CorpusError(f"{path} does not name this repository's {binding} binding")

    value = _object(document.get("value"), f"{path}.value")
    framing = _object(document.get("framing"), f"{path}.framing")
    _string(framing.get("encoding"), f"{path}.framing.encoding")
    wire = _nullable_string(framing.get("wire_base64"), f"{path}.framing.wire_base64")
    policy = _object(document.get("failure_policy"), f"{path}.failure_policy")
    operation = _string(policy.get("operation"), f"{path}.failure_policy.operation")
    if operation not in {"round_trip", "decode_reject", "encode_reject"}:
        raise CorpusError(f"{path}.failure_policy.operation is unsupported")
    error = _nullable_string(policy.get("error"), f"{path}.failure_policy.error")
    if operation in {"round_trip", "decode_reject"} and wire is None:
        raise CorpusError(f"{path} must include wire_base64 for {operation}")
    if operation == "round_trip" and error is not None:
        raise CorpusError(f"{path} round-trip evidence cannot declare an error")
    if operation != "round_trip" and error is None:
        raise CorpusError(f"{path} rejection evidence must declare its stable error policy")
    canonical_wire = (
        _canonical_base64(wire, f"{path}.framing.wire_base64")
        if wire is not None
        else None
    )

    supersedes = tuple(
        _string(item, f"{path}.supersedes[]")
        for item in _list(document.get("supersedes", []), f"{path}.supersedes")
    )
    if len(supersedes) != len(set(supersedes)) or identity in supersedes:
        raise CorpusError(f"{path}.supersedes is invalid")
    semantic = _codec_semantic(
        value=(
            _semantic_codec_value(
                value,
                f"{path}.value",
                wire_backed=operation == "round_trip",
            )
            if operation in {"round_trip", "encode_reject"}
            else None
        ),
        wire_base64=canonical_wire,
        operation=operation,
        error=error,
    )
    return [
        _fixture_evidence(
            category="codec",
            identity=identity,
            path=path,
            protocol_version=version,
            semantic_value=semantic,
            supersedes=supersedes,
        )
    ]


def _replay_expected(
    value: Any,
    context: str,
    *,
    allow_resume: bool = False,
) -> Mapping[str, Any]:
    """Return the observable step shape, independent of assertion subsets.

    ReplayRegressionCorpusTest compares each declared command recursively as a
    subset of the command emitted by WorkflowFiberRunner. Adding another field
    that already exists on that emitted command strengthens the assertion but
    does not change execution. Completion, result, command order/type, and
    resume values are the consumer-observed step identity; command payload
    values are determined by the workflow/input/history/resume execution
    inputs already included in the replay semantic.
    """

    expected = _object(value, context)
    required = {"completed", "result", "commands"}
    allowed = required | ({"resume_with"} if allow_resume else set())
    if not required <= set(expected) or not set(expected) <= allowed:
        raise CorpusError(f"{context} must contain exactly {sorted(required)}")
    completed = _boolean(expected["completed"], f"{context}.completed")
    commands = _list(expected["commands"], f"{context}.commands")
    canonical_commands = []
    for index, raw_command in enumerate(commands):
        command = _object(raw_command, f"{context}.commands[{index}]")
        if not command:
            raise CorpusError(f"{context}.commands[{index}] must not be empty")
        canonical_commands.append(
            {
                "type": _string(
                    command.get("type"),
                    f"{context}.commands[{index}].type",
                )
            }
        )
    canonical: dict[str, Any] = {
        "completed": completed,
        "result": expected["result"],
        "commands": canonical_commands,
    }
    if "resume_with" in expected:
        canonical["resume_with"] = expected["resume_with"]
    return canonical


def _replay_expected_failure(value: Any, context: str) -> Mapping[str, str]:
    """Return the one fail-closed replay outcome supported by this corpus format."""

    expected = _object(value, context)
    required = {"type", "exception"}
    if set(expected) != required:
        raise CorpusError(f"{context} must contain exactly {sorted(required)}")
    failure_type = _string(expected["type"], f"{context}.type")
    if failure_type not in {
        MALFORMED_SERVICE_RESPONSE_ENVELOPE,
        SEARCH_ATTRIBUTE_TYPE_IDENTITY_MISMATCH,
        UNSUPPORTED_PAYLOAD_CODEC,
    }:
        raise CorpusError(f"{context}.type is unsupported")

    return {
        "type": failure_type,
        "exception": _string(expected["exception"], f"{context}.exception"),
    }


def _replay_fixture(
    document: Mapping[str, Any],
    path: str,
    binding: str | None,
    *,
    allow_legacy_json: bool = False,
) -> list[Evidence]:
    _string(document.get("$schema"), f"{path}.$schema")
    if document.get("fixture_schema") != REPLAY_SCHEMA:
        raise CorpusError(f"{path} must declare fixture_schema={REPLAY_SCHEMA}")
    identity = _string(document.get("id"), f"{path}.id")
    protocol_version = _string(document.get("protocol_version"), f"{path}.protocol_version")
    bindings = _unique_strings(
        document.get("bindings"),
        f"{path}.bindings",
        allowed=SUPPORTED_BINDINGS,
    )
    if binding is not None and binding not in bindings:
        raise CorpusError(f"{path} does not name this repository's {binding} binding")
    consumers = _unique_strings(
        document.get("consumers", ["workflow-fiber-runner"]),
        f"{path}.consumers",
        allowed=PHP_REPLAY_CONSUMERS,
    )
    if "workflow-fiber-runner" not in consumers:
        raise CorpusError(
            f"{path}.consumers must include workflow-fiber-runner for the "
            "replay-regression-v1 consumer"
        )
    raw_history_import_metadata = document.get("history_import_metadata")
    history_import_metadata = None
    if raw_history_import_metadata is not None:
        history_import_metadata = _object(
            raw_history_import_metadata,
            f"{path}.history_import_metadata",
        )
        required_metadata_fields = {"memo", "search_attributes"}
        if set(history_import_metadata) != required_metadata_fields:
            raise CorpusError(
                f"{path}.history_import_metadata must contain exactly "
                f"{sorted(required_metadata_fields)}"
            )
        for field in sorted(required_metadata_fields):
            _object(
                history_import_metadata[field],
                f"{path}.history_import_metadata.{field}",
            )
        if "embedded-history-import" not in consumers:
            raise CorpusError(
                f"{path}.history_import_metadata requires the embedded-history-import consumer"
            )
    elif "embedded-history-import" in consumers:
        raise CorpusError(
            f"{path}.consumers declares embedded-history-import without history_import_metadata"
        )
    workflow = _object(document.get("workflow"), f"{path}.workflow")
    required_workflow_fields = {"type", "arguments", "payload_codec"}
    if set(workflow) != required_workflow_fields:
        raise CorpusError(
            f"{path}.workflow must contain exactly {sorted(required_workflow_fields)}"
        )
    _string(workflow.get("type"), f"{path}.workflow.type")
    _list(workflow.get("arguments"), f"{path}.workflow.arguments")
    _string(workflow.get("payload_codec"), f"{path}.workflow.payload_codec")
    history = document.get("history")
    commands = document.get("command_sequence")
    if (history is None) == (commands is None):
        raise CorpusError(
            f"{path} must include exactly one of history or command_sequence"
        )
    has_expected = "expected" in document
    has_expected_failure = "expected_failure" in document
    if has_expected == has_expected_failure:
        raise CorpusError(f"{path} must include exactly one of expected or expected_failure")
    expected_failure = (
        _replay_expected_failure(document["expected_failure"], f"{path}.expected_failure")
        if has_expected_failure
        else None
    )
    if expected_failure is not None and commands is not None:
        raise CorpusError(f"{path}.expected_failure requires history replay input")
    legacy_json_rejection = _contains_json_payload_codec(document)
    if legacy_json_rejection and not allow_legacy_json and (
        expected_failure is None
        or expected_failure["type"] != UNSUPPORTED_PAYLOAD_CODEC
    ):
        raise CorpusError(
            f"{path} JSON-tagged replay evidence must declare "
            "expected_failure.type=unsupported_payload_codec"
        )
    effective_failure = (
        UNSUPPORTED_PAYLOAD_CODEC
        if legacy_json_rejection
        else expected_failure["type"] if expected_failure is not None else None
    )
    observed_failures: list[str] = []
    replay_namespace = None
    if history is not None:
        canonical_history, replay_namespace = _consumer_history(
            history,
            f"{path}.history",
            default_codec=workflow["payload_codec"],
            golden_values=False,
            expected_failure=effective_failure,
            observed_failures=observed_failures,
        )
    else:
        canonical_history = []
    canonical_steps = None
    if commands is not None:
        steps = _list(commands, f"{path}.command_sequence", nonempty=True)
        canonical_steps = []
        for index, raw_step in enumerate(steps):
            step = _object(raw_step, f"{path}.command_sequence[{index}]")
            allowed_step_fields = {"completed", "result", "commands", "resume_with"}
            if not set(step) <= allowed_step_fields:
                raise CorpusError(
                    f"{path}.command_sequence[{index}] contains unsupported fields"
                )
            canonical_steps.append(
                _replay_expected(
                    step,
                    f"{path}.command_sequence[{index}]",
                    allow_resume=True,
                )
            )
            if index < len(steps) - 1 and "resume_with" not in step:
                raise CorpusError(
                    f"{path}.command_sequence[{index}] must provide resume_with for the next step"
                )
            if index == len(steps) - 1 and "resume_with" in step:
                raise CorpusError(
                    f"{path}.command_sequence[{index}] has an unused resume_with value"
                )
    if legacy_json_rejection:
        if history is not None and observed_failures != [UNSUPPORTED_PAYLOAD_CODEC]:
            raise CorpusError(
                f"{path} must exercise exactly one unsupported_payload_codec rejection"
            )
        expected = {"failure": {"type": UNSUPPORTED_PAYLOAD_CODEC}}
    elif expected_failure is not None:
        parser_failure = expected_failure["type"] == MALFORMED_SERVICE_RESPONSE_ENVELOPE
        if parser_failure and observed_failures != [expected_failure["type"]]:
            raise CorpusError(
                f"{path}.expected_failure must match exactly one fail-closed payload"
            )
        if not parser_failure and observed_failures:
            raise CorpusError(
                f"{path}.expected_failure unexpectedly failed while decoding replay payloads"
            )
        expected: Mapping[str, Any] = {"failure": expected_failure}
    else:
        expected = _replay_expected(document.get("expected"), f"{path}.expected")
    supersedes = tuple(
        _string(item, f"{path}.supersedes[]")
        for item in _list(document.get("supersedes", []), f"{path}.supersedes")
    )
    if len(supersedes) != len(set(supersedes)) or identity in supersedes:
        raise CorpusError(f"{path}.supersedes is invalid")
    semantic = _replay_semantic(
        workflow_type=workflow["type"],
        workflow_input=workflow["arguments"],
        workflow_codec=workflow["payload_codec"],
        replay_namespace=replay_namespace,
        history=canonical_history,
        command_sequence=canonical_steps,
        expected=expected,
        history_import_metadata=history_import_metadata,
    )
    return [
        _fixture_evidence(
            category="replay",
            identity=identity,
            path=path,
            protocol_version=protocol_version,
            semantic_value=semantic,
            supersedes=supersedes,
        )
    ]


def _avro_golden_fixture(document: Mapping[str, Any], path: str) -> list[Evidence]:
    _string(document.get("schema"), f"{path}.schema")
    _string(document.get("fingerprint"), f"{path}.fingerprint")
    version = "avro-value-v1"
    evidence: list[Evidence] = []
    sections = {
        "case": _list(document.get("cases"), f"{path}.cases", nonempty=True),
        "malformed": _list(document.get("malformed_frames"), f"{path}.malformed_frames", nonempty=True),
        "alternate": _list(document.get("alternate_map_orders"), f"{path}.alternate_map_orders", nonempty=True),
    }
    for section, entries in sections.items():
        for index, raw_entry in enumerate(entries):
            entry = _object(raw_entry, f"{path}.{section}[{index}]")
            name = _string(entry.get("name"), f"{path}.{section}[{index}].name")
            wire = entry.get("wire_base64")
            semantic_wire: str | Mapping[str, str] | Sequence[str | Mapping[str, str]]
            semantic_value: Mapping[str, Any] | None = None
            if section == "alternate":
                wire_values = _unique_strings(
                    wire,
                    f"{path}.{section}[{index}].wire_base64",
                )
                semantic_wire = [
                    _canonical_base64(
                        wire_value,
                        f"{path}.{section}[{index}].wire_base64[]",
                    )
                    for wire_value in wire_values
                ]
            elif section == "case":
                wire_value = _string(wire, f"{path}.{section}[{index}].wire_base64")
                semantic_wire = _canonical_base64(
                    wire_value,
                    f"{path}.{section}[{index}].wire_base64",
                )
                kind = _string(entry.get("kind"), f"{path}.{section}[{index}].kind")
                canonical_value: dict[str, Any] = {"type": kind}
                if "value" in entry:
                    canonical_value["value"] = entry["value"]
                if "value_base64" in entry:
                    canonical_value["value_base64"] = entry["value_base64"]
                semantic_value = _semantic_codec_value(
                    canonical_value,
                    f"{path}.{section}[{index}]",
                    wire_backed=True,
                )
            elif not isinstance(wire, str):
                raise CorpusError(f"{path}.{section}[{index}].wire_base64 must be a string")
            else:
                semantic_wire = _canonical_base64(
                    wire,
                    f"{path}.{section}[{index}].wire_base64",
                )

            operation = "decode_reject" if section == "malformed" else "round_trip"
            error = (
                _string(entry.get("error"), f"{path}.{section}[{index}].error")
                if section == "malformed"
                else None
            )
            semantic = _codec_semantic(
                value=semantic_value,
                wire_base64=semantic_wire,
                operation=operation,
                error=error,
            )
            evidence.append(
                _fixture_evidence(
                    category="codec",
                    identity=f"{version}:{section}:{name}",
                    path=path,
                    protocol_version=version,
                    semantic_value=semantic,
                )
            )
    return evidence


def _golden_history_fixture(
    document: Mapping[str, Any],
    path: str,
    *,
    require_single_case: bool,
) -> list[Evidence]:
    if document.get("fixture_schema") != GOLDEN_HISTORY_SCHEMA:
        raise CorpusError(f"{path} must declare fixture_schema={GOLDEN_HISTORY_SCHEMA}")
    source = _object(document.get("source"), f"{path}.source")
    runtime = _string(source.get("runtime"), f"{path}.source.runtime")
    _string(source.get("package"), f"{path}.source.package")
    version = _string(source.get("version"), f"{path}.source.version")
    protocol_version = _string(
        source.get("worker_protocol_version"),
        f"{path}.source.worker_protocol_version",
    )
    cases = _list(document.get("cases"), f"{path}.cases", nonempty=True)
    if require_single_case and len(cases) != 1:
        raise CorpusError(
            f"new golden-history fixture {path} must contain exactly one minimal case"
        )
    evidence: list[Evidence] = []
    for index, raw_case in enumerate(cases):
        case = _object(raw_case, f"{path}.cases[{index}]")
        name = _string(case.get("name"), f"{path}.cases[{index}].name")
        family = _string(case.get("family"), f"{path}.cases[{index}].family")
        if family not in PHP_GOLDEN_HISTORY_FAMILIES:
            raise CorpusError(f"{path}.cases[{index}].family is unsupported")
        history, replay_namespace = _consumer_history(
            case.get("history"),
            f"{path}.cases[{index}].history",
            default_codec="avro",
            golden_values=True,
        )
        expected_state = _object(
            case.get("expected_state"),
            f"{path}.cases[{index}].expected_state",
        )
        scenario = _string(case.get("scenario"), f"{path}.cases[{index}].scenario")
        semantic = _replay_semantic(
            workflow_type=PHP_GOLDEN_REPLAY_WORKFLOW,
            workflow_input=[scenario],
            workflow_codec="avro",
            replay_namespace=replay_namespace,
            history=history,
            command_sequence=None,
            expected={
                "completed": True,
                "result": expected_state,
                "commands": [{"type": "complete_workflow"}],
            },
        )
        evidence.append(
            _fixture_evidence(
                category="replay",
                identity=f"{runtime}@{version}:{name}",
                path=path,
                protocol_version=protocol_version,
                semantic_value=semantic,
            )
        )
    return evidence


def _run(command: Sequence[str], root: Path, *, check: bool = True) -> str:
    result = subprocess.run(
        command,
        cwd=root,
        check=False,
        capture_output=True,
        text=True,
    )
    if check and result.returncode != 0:
        detail = result.stderr.strip() or result.stdout.strip()
        raise CorpusError(f"{' '.join(command)} failed: {detail}")
    return result.stdout


def _policy(document: Mapping[str, Any], path: str) -> Mapping[str, Any]:
    _string(document.get("$schema"), f"{path}.$schema")
    if document.get("schema") != POLICY_SCHEMA:
        raise CorpusError(f"{path} must declare schema={POLICY_SCHEMA}")
    _string(document.get("repository"), f"{path}.repository")
    binding = document.get("binding")
    if binding is not None and binding not in SUPPORTED_BINDINGS:
        raise CorpusError(f"{path}.binding is unsupported")
    official_selectors = (
        OFFICIAL_BINDING_FIXTURE_SELECTORS.get(binding)
        if isinstance(binding, str)
        else None
    )
    categories = _object(document.get("categories"), f"{path}.categories")
    if not categories or not set(categories) <= SUPPORTED_CATEGORIES:
        raise CorpusError(f"{path}.categories must contain only replay and/or codec")
    for name, raw_category in categories.items():
        category = _object(raw_category, f"{path}.categories.{name}")
        fixtures = _list(category.get("fixtures"), f"{path}.categories.{name}.fixtures", nonempty=True)
        for index, raw_fixture in enumerate(fixtures):
            fixture = _object(raw_fixture, f"{path}.categories.{name}.fixtures[{index}]")
            fixture_glob = _string(
                fixture.get("glob"),
                f"{path}.categories.{name}.fixtures[{index}].glob",
            )
            fixture_format = _string(
                fixture.get("format"),
                f"{path}.categories.{name}.fixtures[{index}].format",
            )
            if fixture_format not in SUPPORTED_FORMATS:
                raise CorpusError(f"{path}.categories.{name}.fixtures[{index}].format is unsupported")
            if not fixture_format.startswith(name) and not (
                name == "codec" and fixture_format == "avro-value-golden-v1"
            ) and not (name == "replay" and fixture_format == "golden-history-v1"):
                raise CorpusError(f"{path}.categories.{name} contains a fixture for another category")
            if (
                official_selectors is not None
                and (name, fixture_glob, fixture_format) not in official_selectors
            ):
                raise CorpusError(
                    f"{path}.categories.{name}.fixtures[{index}] is not bound to "
                    f"this repository's official {binding} consumer"
                )
        guards = _list(category.get("guards"), f"{path}.categories.{name}.guards", nonempty=True)
        for index, raw_guard in enumerate(guards):
            guard = _object(raw_guard, f"{path}.categories.{name}.guards[{index}]")
            _string(guard.get("glob"), f"{path}.categories.{name}.guards[{index}].glob")
            patterns = guard.get("content_patterns")
            if patterns is not None:
                for pattern in _unique_strings(
                    patterns,
                    f"{path}.categories.{name}.guards[{index}].content_patterns",
                ):
                    try:
                        re.compile(pattern)
                    except re.error as error:
                        raise CorpusError(f"invalid guard regex {pattern!r}: {error}") from error
    return document


def _require_policy_extension(
    base_policy: Mapping[str, Any],
    current_policy: Mapping[str, Any],
    path: str,
) -> None:
    for field in ("repository", "binding"):
        if current_policy.get(field) != base_policy.get(field):
            raise CorpusError(f"{path}.{field} cannot change from the base policy")

    base_categories = _object(base_policy["categories"], "base categories")
    current_categories = _object(current_policy["categories"], "current categories")
    for category_name, raw_base_category in base_categories.items():
        if category_name not in current_categories:
            raise CorpusError(f"{path}.categories.{category_name} cannot be removed from the base policy")
        base_category = _object(raw_base_category, f"base categories.{category_name}")
        current_category = _object(
            current_categories[category_name],
            f"current categories.{category_name}",
        )
        for selector_type in ("fixtures", "guards"):
            base_selectors = _list(
                base_category[selector_type],
                f"base categories.{category_name}.{selector_type}",
            )
            current_selectors = _list(
                current_category[selector_type],
                f"current categories.{category_name}.{selector_type}",
            )
            for base_selector in base_selectors:
                if base_selector not in current_selectors:
                    raise CorpusError(
                        f"{path}.categories.{category_name}.{selector_type} cannot remove "
                        "or change a base selector"
                    )


def _tracked_worktree_files(root: Path) -> dict[str, bytes]:
    paths = _run(
        ["git", "ls-files", "-z", "--cached", "--others", "--exclude-standard"],
        root,
    ).split("\0")
    return {
        path: (root / path).read_bytes()
        for path in paths
        if path and (root / path).is_file()
    }


def _ref_files(root: Path, ref: str) -> dict[str, bytes]:
    paths = _run(["git", "ls-tree", "-r", "--name-only", "-z", ref], root).split("\0")
    return {
        path: _run(["git", "show", f"{ref}:{path}"], root).encode()
        for path in paths
        if path
    }


def _matches(path: str, pattern: str) -> bool:
    return fnmatch.fnmatchcase(path, pattern)


def _official_consumer(
    policy: Mapping[str, Any],
    category_name: str,
    path: str,
) -> tuple[str, tuple[str, ...]]:
    binding = policy.get("binding")
    if not isinstance(binding, str):
        raise CorpusError(
            f"{path} cannot prove a counterfactual without an official binding consumer"
        )
    category = _object(policy["categories"][category_name], f"categories.{category_name}")
    for raw_fixture in _list(category["fixtures"], f"categories.{category_name}.fixtures"):
        fixture = _object(raw_fixture, f"categories.{category_name}.fixtures[]")
        fixture_glob = _string(fixture["glob"], "fixture.glob")
        if not _matches(path, fixture_glob):
            continue
        fixture_format = _string(fixture["format"], "fixture.format")
        command = OFFICIAL_BINDING_CONSUMERS.get(
            (binding, category_name, fixture_glob, fixture_format)
        )
        if command is None:
            raise CorpusError(
                f"{path} has no registered official {binding} consumer command"
            )
        return f"{binding} {fixture_format}", command
    raise CorpusError(f"{path} is not selected by the {category_name} fixture policy")


def _materialize_consumer_tree(
    source_root: Path,
    target_root: Path,
    files: Mapping[str, bytes],
) -> None:
    for path, content in files.items():
        target = target_root / path
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_bytes(content)

    for dependency_path in RUNTIME_DEPENDENCY_PATHS:
        source = source_root / dependency_path
        target = target_root / dependency_path
        if target.exists() or not source.is_dir():
            continue
        shutil.copytree(
            source,
            target,
            copy_function=os.link,
            symlinks=True,
        )


def _consumer_result(
    source_root: Path,
    files: Mapping[str, bytes],
    command: Sequence[str],
) -> subprocess.CompletedProcess[str]:
    with tempfile.TemporaryDirectory(
        prefix=".regression-corpus-consumer-",
        dir=source_root,
    ) as temporary:
        consumer_root = Path(temporary)
        _materialize_consumer_tree(source_root, consumer_root, files)
        return subprocess.run(
            command,
            cwd=consumer_root,
            check=False,
            capture_output=True,
            text=True,
        )


def _consumer_failure_detail(result: subprocess.CompletedProcess[str]) -> str:
    detail = result.stderr.strip() or result.stdout.strip() or "no diagnostic output"
    return detail[-2000:]


def _base_consumer_files(
    base_files: Mapping[str, bytes],
    current_files: Mapping[str, bytes],
    command: tuple[str, ...],
) -> dict[str, bytes]:
    files = dict(base_files)
    for path in OFFICIAL_BINDING_CONSUMER_SUPPORT.get(command, ()):
        if path in current_files:
            files[path] = current_files[path]
    return files


def _require_counterfactual_evidence(
    root: Path,
    policy: Mapping[str, Any],
    base_files: Mapping[str, bytes],
    current_files: Mapping[str, bytes],
    current_evidence: Sequence[Evidence],
    added_fixture_paths: set[str],
    related_categories: set[str],
) -> dict[str, int]:
    verified = {category: 0 for category in related_categories}
    baseline_consumers: set[tuple[str, ...]] = set()

    for category_name in sorted(related_categories):
        evidence_paths = sorted(
            path
            for path in added_fixture_paths
            if any(
                item.path == path and item.category == category_name
                for item in current_evidence
            )
        )
        for path in evidence_paths:
            consumer_name, command = _official_consumer(policy, category_name, path)
            if command not in baseline_consumers:
                baseline_files = _base_consumer_files(base_files, current_files, command)
                baseline = _consumer_result(root, baseline_files, command)
                if baseline.returncode != 0:
                    raise CorpusError(
                        f"official {consumer_name} consumer does not pass at the base "
                        f"revision without new evidence: {_consumer_failure_detail(baseline)}"
                    )
                baseline_consumers.add(command)

            isolated_head_files = dict(current_files)
            for other_path in added_fixture_paths - {path}:
                isolated_head_files.pop(other_path, None)
            head = _consumer_result(root, isolated_head_files, command)
            if head.returncode != 0:
                raise CorpusError(
                    f"new {category_name} evidence {path} does not pass the official "
                    f"{consumer_name} consumer at the current revision: "
                    f"{_consumer_failure_detail(head)}"
                )

            base_with_evidence = _base_consumer_files(base_files, current_files, command)
            base_with_evidence[path] = current_files[path]
            counterfactual = _consumer_result(root, base_with_evidence, command)
            if counterfactual.returncode == 0:
                raise CorpusError(
                    f"new {category_name} evidence {path} passes the official "
                    f"{consumer_name} consumer at both base and current revisions; "
                    "it does not prove the guarded regression"
                )
            verified[category_name] += 1

        if evidence_paths and verified[category_name] == 0:
            raise CorpusError(
                f"new {category_name} evidence includes no fixture that fails the "
                "official consumer at the base revision and passes at the current revision"
            )

    return verified


def _inventory(
    policy: Mapping[str, Any],
    files: Mapping[str, bytes],
    *,
    new_paths: set[str] | None = None,
    allow_legacy_json: bool = False,
) -> list[Evidence]:
    binding = policy.get("binding")
    evidence: list[Evidence] = []
    selected_paths: set[str] = set()
    for category_name, raw_category in _object(policy["categories"], "categories").items():
        category = _object(raw_category, f"categories.{category_name}")
        for raw_fixture in _list(category["fixtures"], f"categories.{category_name}.fixtures"):
            fixture = _object(raw_fixture, f"categories.{category_name}.fixtures[]")
            pattern = _string(fixture["glob"], "fixture.glob")
            fixture_format = _string(fixture["format"], "fixture.format")
            for path in sorted(candidate for candidate in files if _matches(candidate, pattern)):
                if path in selected_paths:
                    raise CorpusError(f"fixture path {path} is selected more than once")
                selected_paths.add(path)
                document = _json(files[path], path)
                if fixture_format == "codec-regression-v1":
                    parsed = _codec_fixture(document, path, binding if isinstance(binding, str) else None)
                elif fixture_format == "replay-regression-v1":
                    parsed = _replay_fixture(
                        document,
                        path,
                        binding if isinstance(binding, str) else None,
                        allow_legacy_json=allow_legacy_json,
                    )
                elif fixture_format == "avro-value-golden-v1":
                    parsed = _avro_golden_fixture(document, path)
                else:
                    parsed = _golden_history_fixture(
                        document,
                        path,
                        require_single_case=new_paths is not None and path in new_paths,
                    )
                if any(item.category != category_name for item in parsed):
                    raise CorpusError(f"{path} produced evidence for the wrong category")
                evidence.extend(parsed)

    identities = Counter(item.identity for item in evidence)
    repeated_identities = sorted(identity for identity, count in identities.items() if count > 1)
    if repeated_identities:
        raise CorpusError(f"duplicate fixture identities: {repeated_identities}")
    semantics = Counter((item.category, item.semantic_digest) for item in evidence)
    duplicate_semantics = sorted(key for key, count in semantics.items() if count > 1)
    if duplicate_semantics:
        paths = {
            key: sorted(item.path for item in evidence if (item.category, item.semantic_digest) == key)
            for key in duplicate_semantics
        }
        raise CorpusError(f"duplicate semantic fixtures: {paths}")
    return evidence


def _fixture_paths(policy: Mapping[str, Any], files: Mapping[str, bytes]) -> set[str]:
    return {
        path
        for raw_category in _object(policy["categories"], "categories").values()
        for raw_fixture in _list(
            _object(raw_category, "category")["fixtures"],
            "category.fixtures",
        )
        for path in files
        if _matches(path, _string(_object(raw_fixture, "fixture")["glob"], "fixture.glob"))
    }


def _changed_paths(root: Path, base_ref: str) -> tuple[set[str], set[str]]:
    output = _run(["git", "diff", "--name-status", "--find-renames", base_ref, "--"], root)
    changed: set[str] = set()
    added: set[str] = set()
    for line in output.splitlines():
        parts = line.split("\t")
        status = parts[0]
        paths = parts[1:]
        if not paths:
            continue
        changed.update(paths)
        if status.startswith("A"):
            added.add(paths[-1])
    untracked = {
        path
        for path in _run(
            ["git", "ls-files", "--others", "--exclude-standard"],
            root,
        ).splitlines()
        if path
    }
    return changed | untracked, added | untracked


def _guard_matches(
    root: Path,
    base_ref: str,
    changed: set[str],
    raw_guard: Any,
) -> bool:
    guard = _object(raw_guard, "guard")
    matching = sorted(path for path in changed if _matches(path, _string(guard["glob"], "guard.glob")))
    if not matching:
        return False
    patterns = guard.get("content_patterns")
    if patterns is None:
        return True
    diff = _run(["git", "diff", "--unified=0", base_ref, "--", *matching], root)
    untracked = set(
        _run(["git", "ls-files", "--others", "--exclude-standard"], root).splitlines()
    )
    for path in matching:
        if path in untracked and (root / path).is_file():
            diff += "\n" + (root / path).read_text(encoding="utf-8", errors="replace")
    changed_content = "\n".join(
        line[1:]
        for line in diff.splitlines()
        if line.startswith(("+", "-")) and not line.startswith(("+++", "---"))
    )
    return any(re.search(pattern, changed_content) for pattern in patterns)


def validate(
    root: Path,
    policy_path: Path,
    base_ref: str | None,
    *,
    enforce_counterfactual: bool = True,
) -> dict[str, Any]:
    policy_file = (policy_path if policy_path.is_absolute() else root / policy_path).resolve()
    try:
        policy_relative_path = policy_file.relative_to(root).as_posix()
    except ValueError as error:
        raise CorpusError("policy must be inside the repository root") from error
    policy = _policy(_json(policy_file.read_bytes(), str(policy_path)), str(policy_path))
    current_files = _tracked_worktree_files(root)
    changed: set[str] = set()
    added_paths: set[str] = set()
    base_files: dict[str, bytes] = {}
    base_evidence: list[Evidence] = []
    if base_ref and not ZERO_COMMIT.fullmatch(base_ref):
        _run(["git", "rev-parse", "--verify", f"{base_ref}^{{commit}}"], root)
        changed, added_paths = _changed_paths(root, base_ref)
        base_files = _ref_files(root, base_ref)
        raw_base_policy = base_files.get(policy_relative_path)
        base_policy = (
            _policy(_json(raw_base_policy, policy_relative_path), policy_relative_path)
            if raw_base_policy is not None
            else policy
        )
        if raw_base_policy is not None:
            _require_policy_extension(base_policy, policy, str(policy_path))
        for path in _fixture_paths(base_policy, base_files):
            current_content = current_files.get(path)
            if current_content != base_files[path] and current_content is not None:
                if _avro_golden_migration(
                    base_files[path],
                    current_content,
                ) or _is_prerelease_json_to_avro_replay_migration(
                    base_files[path],
                    current_content,
                    path,
                ):
                    base_files[path] = current_content
                    continue
            if current_content != base_files[path]:
                raise CorpusError(f"immutable fixture file {path} was changed, moved, or removed")
        base_evidence = _inventory(base_policy, base_files, allow_legacy_json=True)
    current_evidence = _inventory(policy, current_files, new_paths=added_paths)

    current_by_id = {item.identity: item for item in current_evidence}
    base_by_id = {item.identity: item for item in base_evidence}
    for identity, previous in base_by_id.items():
        current = current_by_id.get(identity)
        if current is None:
            raise CorpusError(f"immutable fixture {identity} was removed")
        if current.path != previous.path or current.semantic_digest != previous.semantic_digest:
            raise CorpusError(f"immutable fixture {identity} was changed; append a superseding fixture instead")
    for item in current_evidence:
        for superseded in item.supersedes:
            previous = current_by_id.get(superseded)
            if previous is None:
                raise CorpusError(f"{item.identity} supersedes unknown fixture {superseded}")
            if previous.category != item.category or previous.protocol_version == item.protocol_version:
                raise CorpusError(
                    f"{item.identity} must supersede evidence in the same category at an older protocol version"
                )

    counts: dict[str, dict[str, int | bool]] = {}
    related_categories: set[str] = set()
    for category_name, raw_category in _object(policy["categories"], "categories").items():
        current_count = sum(item.category == category_name for item in current_evidence)
        base_count = sum(item.category == category_name for item in base_evidence)
        new_fixture_evidence = sum(
            item.category == category_name and item.path in added_paths
            for item in current_evidence
        )
        related = False
        if base_ref and not ZERO_COMMIT.fullmatch(base_ref):
            category = _object(raw_category, f"categories.{category_name}")
            related = any(
                _guard_matches(root, base_ref, changed, guard)
                for guard in _list(category["guards"], f"categories.{category_name}.guards")
            )
            if related:
                related_categories.add(category_name)
            if related and current_count <= base_count:
                raise CorpusError(
                    f"{category_name} implementation changed but its corpus did not grow "
                    f"(base={base_count}, current={current_count})"
                )
            if related and new_fixture_evidence == 0:
                raise CorpusError(
                    f"{category_name} implementation changed but corpus growth has no "
                    "newly added fixture evidence"
                )
        counts[category_name] = {
            "base": base_count,
            "current": current_count,
            "new_fixture_evidence": new_fixture_evidence,
            "related_change": related,
        }

    counterfactual_counts = {category: 0 for category in counts}
    if related_categories and enforce_counterfactual:
        added_fixture_paths = added_paths & _fixture_paths(policy, current_files)
        counterfactual_counts.update(
            _require_counterfactual_evidence(
                root,
                policy,
                base_files,
                current_files,
                current_evidence,
                added_fixture_paths,
                related_categories,
            )
        )
    for category_name, count in counterfactual_counts.items():
        counts[category_name]["counterfactual_fixture_paths"] = count

    return {
        "schema": POLICY_SCHEMA,
        "repository": policy["repository"],
        "base_ref": base_ref,
        "changed_paths": len(changed),
        "counterfactual_enforced": enforce_counterfactual,
        "counts": counts,
        "status": "pass",
    }


def main(argv: Sequence[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--root", type=Path, default=Path.cwd())
    parser.add_argument("--policy", type=Path, default=Path("regression-corpus-policy.json"))
    parser.add_argument("--base-ref")
    parser.add_argument(
        "--skip-counterfactual",
        action="store_true",
        help="run structural inventory checks without invoking binding consumers",
    )
    args = parser.parse_args(argv)
    try:
        result = validate(
            args.root.resolve(),
            args.policy,
            args.base_ref,
            enforce_counterfactual=not args.skip_counterfactual,
        )
    except (CorpusError, OSError) as error:
        print(f"regression corpus validation failed: {error}", file=sys.stderr)
        return 1
    print(json.dumps(result, indent=2, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
