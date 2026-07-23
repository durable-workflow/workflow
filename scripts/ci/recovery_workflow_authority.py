"""Resolve and validate the qualified component recovery-workflow authority."""

from __future__ import annotations

import hashlib
import hmac
import json
from collections.abc import Mapping
from typing import Any

SCHEMA = "durable-workflow.component-release-recovery-authority/v2"
CONTROL_REPOSITORY = "durable-workflow/.github"
AUTHORITY_REF = "main"
AUTHORITY_PATH = "release-recovery/authority.json"
QUALIFICATION_WORKFLOW = ".github/workflows/beta-candidate.yml"
QUALIFICATION_EVENT = "push"
QUALIFICATION_REF_PATH = f"{QUALIFICATION_WORKFLOW}@{AUTHORITY_REF}"
WORKFLOW_PATH = ".github/workflows/release-plan-recovery.yml"
SOURCE_IDENTITY = {
    "repository": CONTROL_REPOSITORY,
    "ref": f"refs/heads/{AUTHORITY_REF}",
    "path": AUTHORITY_PATH,
    "qualification": {
        "workflow": QUALIFICATION_WORKFLOW,
        "event": QUALIFICATION_EVENT,
    },
}


class RecoveryWorkflowAuthorityError(ValueError):
    """The protected recovery-workflow authority is malformed or mismatched."""


def normalized_source_sha256(source: str) -> str:
    return hashlib.sha256(source.replace("\r\n", "\n").encode("utf-8")).hexdigest()


def authority_ref_url() -> str:
    return f"https://api.github.com/repos/{CONTROL_REPOSITORY}/commits/{AUTHORITY_REF}"


def authority_url(commit: str) -> str:
    return (
        f"https://api.github.com/repos/{CONTROL_REPOSITORY}/contents/{AUTHORITY_PATH}"
        f"?ref={commit}"
    )


def qualification_runs_url(commit: str) -> str:
    workflow = QUALIFICATION_WORKFLOW.rsplit("/", 1)[-1]
    return (
        f"https://api.github.com/repos/{CONTROL_REPOSITORY}/actions/workflows/{workflow}/runs"
        f"?branch={AUTHORITY_REF}&event={QUALIFICATION_EVENT}&head_sha={commit}&per_page=100"
    )


def validate_authority_commit(value: Any) -> str:
    commit = value.get("sha") if isinstance(value, dict) else None
    if (
        not isinstance(commit, str)
        or len(commit) != 40
        or any(character not in "0123456789abcdef" for character in commit)
    ):
        raise RecoveryWorkflowAuthorityError("recovery workflow authority ref has an invalid commit")
    return commit


def _qualification_evidence(run: dict[str, Any], commit: str) -> dict[str, Any]:
    run_id = run.get("id")
    run_attempt = run.get("run_attempt")
    if (
        not isinstance(run_id, int)
        or isinstance(run_id, bool)
        or run_id < 1
        or not isinstance(run_attempt, int)
        or isinstance(run_attempt, bool)
        or run_attempt < 1
    ):
        raise RecoveryWorkflowAuthorityError(
            "recovery workflow authority qualification has an invalid run identity"
        )
    return {
        "workflow": QUALIFICATION_WORKFLOW,
        "path": run["path"],
        "event": QUALIFICATION_EVENT,
        "head_branch": AUTHORITY_REF,
        "head_sha": commit,
        "run_id": run_id,
        "run_attempt": run_attempt,
        "status": "completed",
        "conclusion": "success",
        "url": f"https://github.com/{CONTROL_REPOSITORY}/actions/runs/{run_id}",
    }


def validate_authority_qualification(value: Any, commit: str) -> dict[str, Any]:
    runs = value.get("workflow_runs") if isinstance(value, dict) else None
    if not isinstance(runs, list):
        raise RecoveryWorkflowAuthorityError(
            "recovery workflow authority qualification response has an invalid shape"
        )

    candidates = [
        run
        for run in runs
        if isinstance(run, dict)
        and run.get("path") in (QUALIFICATION_WORKFLOW, QUALIFICATION_REF_PATH)
        and run.get("event") == QUALIFICATION_EVENT
        and run.get("head_branch") == AUTHORITY_REF
    ]
    if not candidates:
        raise RecoveryWorkflowAuthorityError(
            "recovery workflow authority qualification is absent for the resolved commit"
        )
    if any(run.get("head_sha") != commit for run in candidates):
        raise RecoveryWorkflowAuthorityError(
            "recovery workflow authority qualification is bound to another commit"
        )

    successful = [
        run
        for run in candidates
        if run.get("status") == "completed" and run.get("conclusion") == "success"
    ]
    if successful:
        return _qualification_evidence(successful[0], commit)
    if any(run.get("status") != "completed" for run in candidates):
        raise RecoveryWorkflowAuthorityError(
            "recovery workflow authority qualification is pending for the resolved commit"
        )
    if any(run.get("conclusion") == "cancelled" for run in candidates):
        raise RecoveryWorkflowAuthorityError(
            "recovery workflow authority qualification was cancelled for the resolved commit"
        )
    raise RecoveryWorkflowAuthorityError(
        "recovery workflow authority qualification failed for the resolved commit"
    )


def qualified_source_identity(
    raw: bytes,
    commit: str,
    qualification: dict[str, Any],
) -> dict[str, Any]:
    return {
        "repository": CONTROL_REPOSITORY,
        "ref": f"refs/heads/{AUTHORITY_REF}",
        "commit": commit,
        "path": AUTHORITY_PATH,
        "sha256": hashlib.sha256(raw).hexdigest(),
        "qualification": qualification,
    }


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


def load_qualified_authority(
    client: Any,
    components: Mapping[str, tuple[str, str]],
) -> tuple[dict[str, dict[str, str]], dict[str, Any]]:
    commit = validate_authority_commit(client.json(authority_ref_url()))
    qualification = validate_authority_qualification(
        client.json(qualification_runs_url(commit)),
        commit,
    )
    raw = client.bytes(authority_url(commit), accept="application/vnd.github.raw+json")
    workflows = decode_authority(raw, components)
    return workflows, qualified_source_identity(raw, commit, qualification)


def verify_workflow_source(name: str, source: str, expected_sha256: str) -> str:
    actual_sha256 = normalized_source_sha256(source)
    if not hmac.compare_digest(actual_sha256, expected_sha256):
        raise RecoveryWorkflowAuthorityError(
            f"{name} recovery workflow does not match the protected source identity"
        )
    return actual_sha256
