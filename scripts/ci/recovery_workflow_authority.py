"""Validate the protected component release-recovery workflow tuple."""

from __future__ import annotations

import hashlib
import hmac
import json
from collections.abc import Mapping
from typing import Any

SCHEMA = "durable-workflow.component-release-recovery-authority/v1"
CONTROL_REPOSITORY = "durable-workflow/.github"
AUTHORITY_REF = "main"
AUTHORITY_PATH = "release-recovery/authority.json"
WORKFLOW_PATH = ".github/workflows/release-plan-recovery.yml"
SOURCE_IDENTITY = {
    "repository": CONTROL_REPOSITORY,
    "ref": f"refs/heads/{AUTHORITY_REF}",
    "path": AUTHORITY_PATH,
}


class RecoveryWorkflowAuthorityError(ValueError):
    """The protected recovery-workflow authority is malformed or mismatched."""


def normalized_source_sha256(source: str) -> str:
    return hashlib.sha256(source.replace("\r\n", "\n").encode("utf-8")).hexdigest()


def authority_url() -> str:
    return (
        f"https://api.github.com/repos/{CONTROL_REPOSITORY}/contents/{AUTHORITY_PATH}"
        f"?ref={AUTHORITY_REF}"
    )


def validate_authority(
    value: Any,
    components: Mapping[str, tuple[str, str]],
) -> dict[str, dict[str, str]]:
    if not isinstance(value, dict) or set(value) != {"schema", "source", "workflows"}:
        raise RecoveryWorkflowAuthorityError("recovery workflow authority has an invalid document shape")
    if value.get("schema") != SCHEMA or value.get("source") != SOURCE_IDENTITY:
        raise RecoveryWorkflowAuthorityError("recovery workflow authority has an unexpected protected source")

    workflows = value.get("workflows")
    if not isinstance(workflows, dict) or set(workflows) != set(components):
        raise RecoveryWorkflowAuthorityError("recovery workflow authority does not name the complete component set")

    validated: dict[str, dict[str, str]] = {}
    for name, (repository, default_branch) in components.items():
        entry = workflows.get(name)
        expected_identity = {
            "repository": repository,
            "ref": f"refs/heads/{default_branch}",
            "path": WORKFLOW_PATH,
            "state": "active",
        }
        if not isinstance(entry, dict) or set(entry) != {*expected_identity, "sha256"}:
            raise RecoveryWorkflowAuthorityError(f"{name} recovery workflow authority has an invalid shape")
        if any(entry.get(field) != expected for field, expected in expected_identity.items()):
            raise RecoveryWorkflowAuthorityError(f"{name} recovery workflow authority has a mismatched identity")
        digest = entry.get("sha256")
        if (
            not isinstance(digest, str)
            or len(digest) != 64
            or any(character not in "0123456789abcdef" for character in digest)
        ):
            raise RecoveryWorkflowAuthorityError(f"{name} recovery workflow authority has an invalid SHA-256")
        validated[name] = dict(entry)
    return validated


def decode_authority(
    raw: bytes,
    components: Mapping[str, tuple[str, str]],
) -> dict[str, dict[str, str]]:
    try:
        value = json.loads(raw)
    except (UnicodeDecodeError, json.JSONDecodeError) as error:
        raise RecoveryWorkflowAuthorityError("recovery workflow authority is not valid UTF-8 JSON") from error
    return validate_authority(value, components)


def verify_workflow_source(name: str, source: str, expected_sha256: str) -> str:
    actual_sha256 = normalized_source_sha256(source)
    if not hmac.compare_digest(actual_sha256, expected_sha256):
        raise RecoveryWorkflowAuthorityError(
            f"{name} recovery workflow does not match the protected source identity"
        )
    return actual_sha256
