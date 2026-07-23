#!/usr/bin/env python3
"""Discover and classify one repository's work for an immutable release plan."""

from __future__ import annotations

import argparse
import contextlib
import datetime as dt
import email.utils
import errno
import hashlib
import http.client
import json
import os
import re
import shutil
import subprocess
import sys
import tarfile
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request
import zipfile
from collections.abc import Callable, Mapping
from dataclasses import dataclass
from pathlib import Path
from typing import Any

from recovery_workflow_authority import (
    RecoveryWorkflowAuthorityError,
    load_qualified_authority,
    verify_workflow_source,
)

SCHEMA = "durable-workflow.release-plan/v1"
PREPARATION_SCHEMA = "durable-workflow.release-preparation/v1"
STATE_SCHEMA = "durable-workflow.component-release-recovery/v1"
CONTROL_REPOSITORY = "durable-workflow/.github"
PLAN_TAG_PREFIX = "release-plan/"
COMPLETION_TAG_PREFIX = "release-candidate/"
FAILURE_TAG_PREFIX = "release-plan-failure/"
CONTINUITY_TAG_PREFIX = "beta-continuity/"
CONTINUITY_EVIDENCE_SCHEMA = "durable-workflow.beta-continuity.evidence/v1"
CONTINUITY_SUPERSESSION_REASON = "missing-post-acceptance-publication-trigger"
SUPERSESSION_ENVIRONMENT = "release-plan-supersession"
SUPERSESSION_WORKFLOW = ".github/workflows/release-plan-supersession.yml"
SUPERSESSION_REASON = "published-version-source-conflict"
SOURCE_MANIFEST_REASON = "source-manifest-version-conflict"
OCCUPIED_SOURCE_MANIFEST_REASON = "occupied-source-manifest-version-conflict"
FOUNDATION_TAG = "beta-candidate/beta-continuity-foundation"
FOUNDATION_COMMIT = "4995052410bd4301c5796ffba54e0b6d2f490ed1"
COMMIT_PATTERN = re.compile(r"^[0-9a-f]{40}$")
PLAN_PATTERN = re.compile(r"^[a-z0-9][a-z0-9._-]{0,55}$")
VERSION_PATTERN = re.compile(
    r"^[0-9]+\.[0-9]+\.[0-9]+(?:[-+][0-9A-Za-z][0-9A-Za-z.-]*)?$"
)
ALPHA_VERSION_PATTERN = re.compile(r"^2\.0\.0-alpha\.[1-9][0-9]*$")
BETA_VERSION_PATTERN = re.compile(r"^2\.0\.0-beta\.[1-9][0-9]*$")
MARKDOWN_MEDIA_TYPE = "text/markdown"
GITHUB_READ_MAX_ATTEMPTS = 5
GITHUB_READ_RETRY_BASE_SECONDS = 2.0
GITHUB_READ_RETRY_MAX_SECONDS = 120.0
GITHUB_READ_REQUEST_TIMEOUT_SECONDS = 30.0
GITHUB_READ_DEADLINE_SECONDS = 600.0
INFRASTRUCTURE_EXIT_CODE = 75

SOURCE_CHANGELOGS = {"workflow", "waterline", "sdk-php", "sdk-python"}
SOURCE_MANIFESTS = {
    "sdk-python": {"path": "pyproject.toml", "package": "durable-workflow"},
    "sdk-rust": {"path": "Cargo.toml", "package": "durable-workflow"},
}
SUPERSESSION_ENVIRONMENT_URL = (
    f"https://github.com/{CONTROL_REPOSITORY}/deployments/activity_log?"
    f"environments_filter={SUPERSESSION_ENVIRONMENT}"
)
SUPERSESSION_ENVIRONMENT_API_URL = (
    f"https://api.github.com/repos/{CONTROL_REPOSITORY}/environments/{SUPERSESSION_ENVIRONMENT}"
)
SOURCE_PRODUCT_TRAINS = {
    "workflow": ("durable-workflow/workflow", "composer.json"),
    "waterline": ("durable-workflow/waterline", "composer.json"),
}


@dataclass(frozen=True)
class Component:
    repository: str
    default_branch: str
    distribution: str
    package: str
    dependencies: tuple[str, ...]
    release_workflow: str | None
    release_tag_input: str | None


COMPONENTS = {
    "workflow": Component(
        "durable-workflow/workflow",
        "v2",
        "composer",
        "durable-workflow/workflow",
        (),
        None,
        None,
    ),
    "sdk-php": Component(
        "durable-workflow/sdk-php",
        "main",
        "composer",
        "durable-workflow/sdk",
        (),
        None,
        None,
    ),
    "waterline": Component(
        "durable-workflow/waterline",
        "v2",
        "composer",
        "durable-workflow/waterline",
        ("workflow", "sdk-php"),
        None,
        None,
    ),
    "server": Component(
        "durable-workflow/server",
        "main",
        "oci",
        "docker.io/durableworkflow/server",
        ("workflow",),
        "release.yml",
        "tag",
    ),
    "cli": Component(
        "durable-workflow/cli",
        "main",
        "github-release",
        "durable-workflow/cli",
        ("server",),
        "release.yml",
        "tag",
    ),
    "sdk-python": Component(
        "durable-workflow/sdk-python",
        "main",
        "pypi",
        "durable-workflow",
        ("server",),
        "publish.yml",
        "release_tag",
    ),
    "sdk-rust": Component(
        "durable-workflow/sdk-rust",
        "main",
        "crates.io",
        "durable-workflow",
        ("server",),
        "release.yml",
        "release_tag",
    ),
}

CLI_ASSETS = {
    "dw.phar",
    "dw-linux-x86_64",
    "dw-linux-aarch64",
    "dw-macos-aarch64",
    "dw-windows-x86_64.exe",
    "dw.rb",
    "install.sh",
    "install.ps1",
    "verify-release.sh",
    "SHA256SUMS",
}


class RecoveryError(RuntimeError):
    """A release plan cannot safely advance."""

    def __init__(self, message: str, phase: str = "preflight") -> None:
        super().__init__(message)
        self.phase = phase


class NotFound(RecoveryError):
    """A public API resource is absent."""


class PublicInfrastructureError(RuntimeError):
    """A bounded set of transient GitHub public-read attempts was exhausted."""

    def __init__(
        self,
        endpoint_class: str,
        attempts: int,
        *,
        reason: str,
        failure: str | None = None,
    ) -> None:
        evidence = [
            "classification=github-read-transient",
            f"endpoint_class={endpoint_class}",
            f"attempts={attempts}",
            f"reason={reason}",
        ]
        if failure is not None:
            evidence.append(failure)
        super().__init__(
            f"GitHub public read transient failure exhausted ({', '.join(evidence)})"
        )


class _TransientGitHubRead(RuntimeError):
    """One GitHub public-read attempt encountered retryable infrastructure."""

    def __init__(self, evidence: str, headers: Mapping[str, str] | None = None) -> None:
        self.evidence = evidence
        self.headers = headers or {}
        super().__init__(evidence)


def canonical_json(value: Any) -> bytes:
    return (
        json.dumps(value, indent=2, sort_keys=True, ensure_ascii=True) + "\n"
    ).encode()


class PublicClient:
    def __init__(
        self,
        token: str | None = None,
        *,
        max_attempts: int = GITHUB_READ_MAX_ATTEMPTS,
        retry_base_seconds: float = GITHUB_READ_RETRY_BASE_SECONDS,
        retry_max_seconds: float = GITHUB_READ_RETRY_MAX_SECONDS,
        request_timeout_seconds: float = GITHUB_READ_REQUEST_TIMEOUT_SECONDS,
        deadline_seconds: float = GITHUB_READ_DEADLINE_SECONDS,
        sleep: Callable[[float], None] = time.sleep,
        now: Callable[[], float] = time.time,
        monotonic: Callable[[], float] = time.monotonic,
    ) -> None:
        if (
            max_attempts < 1
            or retry_base_seconds < 0
            or retry_max_seconds < retry_base_seconds
            or request_timeout_seconds <= 0
            or deadline_seconds <= 0
        ):
            raise ValueError("invalid GitHub public-read retry configuration")
        self.token = token
        self.max_attempts = max_attempts
        self.retry_base_seconds = retry_base_seconds
        self.retry_max_seconds = retry_max_seconds
        self.request_timeout_seconds = request_timeout_seconds
        self.sleep = sleep
        self.now = now
        self.monotonic = monotonic
        self.deadline = monotonic() + deadline_seconds

    @staticmethod
    def _github_endpoint_class(url: str) -> str | None:
        parsed = urllib.parse.urlsplit(url)
        host = (parsed.hostname or "").lower()
        if host == "api.github.com":
            path = parsed.path
            endpoint_classes = (
                ("/releases", "releases-api"),
                ("/git/", "git-api"),
                ("/contents/", "contents-api"),
                ("/commits/", "commits-api"),
                ("/actions/", "actions-api"),
                ("/environments/", "environments-api"),
            )
            for marker, endpoint_class in endpoint_classes:
                if marker in path:
                    return endpoint_class
            if path.startswith("/users/"):
                return "users-api"
            return "repositories-api"
        if (
            host == "github.com"
            or host.endswith(".github.com")
            or host.endswith(".githubusercontent.com")
        ):
            return "github-download"
        return None

    @staticmethod
    def _error_detail(error: urllib.error.HTTPError) -> str:
        try:
            return error.read(1024).decode(errors="replace")
        except OSError:
            return "response body unavailable"

    @staticmethod
    def _is_rate_limited(error: urllib.error.HTTPError, detail: str) -> bool:
        headers = error.headers or {}
        return error.code == 429 or (
            error.code == 403
            and (
                headers.get("Retry-After") is not None
                or headers.get("X-RateLimit-Remaining") == "0"
                or "rate limit" in detail.lower()
            )
        )

    @staticmethod
    def _transport_name(error: BaseException) -> str | None:
        reason = error.reason if isinstance(error, urllib.error.URLError) else error
        if isinstance(
            reason,
            ConnectionError
            | TimeoutError
            | http.client.IncompleteRead
            | http.client.RemoteDisconnected,
        ):
            return type(reason).__name__
        if isinstance(reason, OSError) and reason.errno in {
            errno.ECONNABORTED,
            errno.ECONNRESET,
            errno.EPIPE,
            errno.ETIMEDOUT,
        }:
            return type(reason).__name__
        return None

    def _server_retry_delay(self, headers: Mapping[str, str]) -> float | None:
        delays: list[float] = []
        retry_after = headers.get("Retry-After")
        if retry_after:
            try:
                delays.append(float(retry_after))
            except ValueError:
                try:
                    retry_at = email.utils.parsedate_to_datetime(retry_after)
                except (TypeError, ValueError):
                    pass
                else:
                    if retry_at.tzinfo is None:
                        retry_at = retry_at.replace(tzinfo=dt.UTC)
                    delays.append(retry_at.timestamp() - self.now())
        rate_limit_reset = headers.get("X-RateLimit-Reset")
        if rate_limit_reset:
            with contextlib.suppress(ValueError):
                delays.append(float(rate_limit_reset) - self.now())
        return max((delay for delay in delays if delay > 0), default=None)

    def _retry_delay(self, attempt: int, failure: _TransientGitHubRead) -> float:
        backoff = min(
            self.retry_base_seconds * (2 ** (attempt - 1)), self.retry_max_seconds
        )
        return max(backoff, self._server_retry_delay(failure.headers) or 0)

    def _remaining_time(self) -> float:
        return self.deadline - self.monotonic()

    def _run(
        self,
        url: str,
        operation: Callable[[urllib.response.addinfourl], Any],
        *,
        headers: dict[str, str] | None,
        accept: str | None,
    ) -> Any:
        endpoint_class = self._github_endpoint_class(url)
        attempt_limit = self.max_attempts if endpoint_class is not None else 1
        request_headers = {
            "User-Agent": "durable-workflow-release-recovery/1",
            **(headers or {}),
        }
        if accept:
            request_headers["Accept"] = accept
        if self.token and urllib.parse.urlsplit(url).hostname == "api.github.com":
            request_headers["Authorization"] = f"Bearer {self.token}"
            request_headers["X-GitHub-Api-Version"] = "2022-11-28"

        for attempt in range(1, attempt_limit + 1):
            if endpoint_class is not None and self._remaining_time() <= 0:
                raise PublicInfrastructureError(
                    endpoint_class, attempt - 1, reason="workflow-deadline"
                )
            timeout = (
                min(self.request_timeout_seconds, self._remaining_time())
                if endpoint_class is not None
                else 60
            )
            request = urllib.request.Request(url, headers=request_headers)
            failure: _TransientGitHubRead | None = None
            try:
                response = urllib.request.urlopen(request, timeout=timeout)
                result = operation(response)
                if endpoint_class is not None and self._remaining_time() <= 0:
                    raise PublicInfrastructureError(
                        endpoint_class, attempt, reason="workflow-deadline"
                    )
                return result
            except urllib.error.HTTPError as error:
                detail = self._error_detail(error)
                if endpoint_class is not None and (
                    500 <= error.code <= 599 or self._is_rate_limited(error, detail)
                ):
                    failure = _TransientGitHubRead(
                        f"status={error.code}", error.headers
                    )
                elif error.code == 404:
                    raise NotFound(f"public resource is absent: {url}") from error
                else:
                    raise RecoveryError(
                        f"public request failed ({error.code}) for {url}: {detail}"
                    ) from error
            except (
                urllib.error.URLError,
                ConnectionError,
                TimeoutError,
                http.client.IncompleteRead,
            ) as error:
                transport = self._transport_name(error)
                if endpoint_class is not None and transport is not None:
                    failure = _TransientGitHubRead(f"transport={transport}")
                else:
                    reason = (
                        error.reason
                        if isinstance(error, urllib.error.URLError)
                        else error
                    )
                    raise RecoveryError(
                        f"public request failed for {url}: {reason}"
                    ) from error

            assert endpoint_class is not None and failure is not None
            if attempt == attempt_limit:
                raise PublicInfrastructureError(
                    endpoint_class,
                    attempt,
                    reason="retry-exhausted",
                    failure=failure.evidence,
                )
            delay = self._retry_delay(attempt, failure)
            if delay >= self._remaining_time():
                raise PublicInfrastructureError(
                    endpoint_class,
                    attempt,
                    reason="workflow-deadline",
                    failure=failure.evidence,
                )
            print(
                f"GitHub public read retry: endpoint_class={endpoint_class} "
                f"attempt={attempt}/{attempt_limit} {failure.evidence} delay={delay:g}s",
                file=sys.stderr,
            )
            self.sleep(delay)
        raise AssertionError("GitHub public-read retry loop ended unexpectedly")

    def request(
        self,
        url: str,
        *,
        headers: dict[str, str] | None = None,
        accept: str | None = None,
    ) -> urllib.response.addinfourl:
        return self._run(url, lambda response: response, headers=headers, accept=accept)

    def json(
        self,
        url: str,
        *,
        headers: dict[str, str] | None = None,
        accept: str | None = None,
    ) -> Any:
        def read_json(response: urllib.response.addinfourl) -> Any:
            with response:
                try:
                    return json.load(response)
                except (json.JSONDecodeError, UnicodeDecodeError) as error:
                    raise RecoveryError(
                        f"public endpoint did not return valid JSON: {url}"
                    ) from error

        return self._run(url, read_json, headers=headers, accept=accept)

    def bytes(
        self,
        url: str,
        *,
        headers: dict[str, str] | None = None,
        accept: str | None = None,
    ) -> bytes:
        def read_bytes(response: urllib.response.addinfourl) -> bytes:
            with response:
                return response.read()

        return self._run(url, read_bytes, headers=headers, accept=accept)

    def download(
        self, url: str, path: Path, *, expected_sha256: str | None = None
    ) -> dict[str, Any]:
        def download_once(response: urllib.response.addinfourl) -> tuple[str, int]:
            digest = hashlib.sha256()
            size = 0
            with response, path.open("wb") as destination:
                while chunk := response.read(1024 * 1024):
                    digest.update(chunk)
                    destination.write(chunk)
                    size += len(chunk)
            return digest.hexdigest(), size

        actual, size = self._run(url, download_once, headers=None, accept=None)
        if expected_sha256 and actual != expected_sha256.lower():
            raise RecoveryError(
                f"download digest mismatch for {url}: expected {expected_sha256}, got {actual}"
            )
        return {"url": url, "size": size, "sha256": actual}


def validate_plan(plan: Any) -> None:
    if not isinstance(plan, dict):
        raise RecoveryError("release plan must be a JSON object")
    expected = {
        "schema",
        "plan",
        "channel",
        "foundation",
        "components",
        "beta_authorization",
    }
    if set(plan) != expected or plan.get("schema") != SCHEMA:
        raise RecoveryError(
            "release plan does not satisfy the channel-aware v1 contract"
        )
    if not isinstance(plan["plan"], str) or not PLAN_PATTERN.fullmatch(plan["plan"]):
        raise RecoveryError("release plan has an invalid identity")
    if plan["channel"] not in {"alpha", "beta"}:
        raise RecoveryError("release plan channel must be alpha or beta")
    if plan["foundation"] != {"tag": FOUNDATION_TAG, "commit": FOUNDATION_COMMIT}:
        raise RecoveryError(
            "release plan does not name the proven immutable candidate foundation"
        )
    components = plan["components"]
    if not isinstance(components, dict) or set(components) != set(COMPONENTS):
        raise RecoveryError("release plan must contain the exact seven-component tuple")
    for name, identity in components.items():
        if not isinstance(identity, dict) or set(identity) != {"version", "commit"}:
            raise RecoveryError(
                f"components.{name} must contain only version and commit"
            )
        if not isinstance(identity["version"], str) or not VERSION_PATTERN.fullmatch(
            identity["version"]
        ):
            raise RecoveryError(f"components.{name}.version is not exact SemVer")
        if not isinstance(identity["commit"], str) or not COMMIT_PATTERN.fullmatch(
            identity["commit"]
        ):
            raise RecoveryError(
                f"components.{name}.commit is not a full source identity"
            )
    channel_pattern = (
        ALPHA_VERSION_PATTERN if plan["channel"] == "alpha" else BETA_VERSION_PATTERN
    )
    for name in ("workflow", "waterline"):
        if not channel_pattern.fullmatch(components[name]["version"]):
            raise RecoveryError(
                f"{name} does not have an exact 2.0.0-{plan['channel']}.N identity"
            )
    authorization = plan["beta_authorization"]
    if plan["channel"] == "alpha" and authorization is not None:
        raise RecoveryError("alpha plans cannot claim beta authorization")
    if plan["channel"] == "beta" and (
        not isinstance(authorization, dict)
        or set(authorization) != {"tag", "commit"}
        or not re.fullmatch(
            r"beta-authorization/[a-z0-9][a-z0-9._-]{0,55}",
            str(authorization.get("tag", "")),
        )
        or not COMMIT_PATTERN.fullmatch(str(authorization.get("commit", "")))
    ):
        raise RecoveryError("beta plans require an immutable beta authorization")


def manifest_digest(value: Any) -> str:
    return hashlib.sha256(canonical_json(value)).hexdigest()


def is_immediate_version_successor(previous: str, successor: str) -> bool:
    previous_match = re.fullmatch(r"(\d+)\.(\d+)\.(\d+)(?:-([0-9A-Za-z.-]+))?", previous)
    successor_match = re.fullmatch(r"(\d+)\.(\d+)\.(\d+)(?:-([0-9A-Za-z.-]+))?", successor)
    if previous_match is None or successor_match is None:
        return False
    previous_core = tuple(int(value) for value in previous_match.groups()[:3])
    successor_core = tuple(int(value) for value in successor_match.groups()[:3])
    previous_prerelease = previous_match.group(4)
    successor_prerelease = successor_match.group(4)
    if previous_prerelease is None:
        return successor_prerelease is None and successor_core == (
            previous_core[0],
            previous_core[1],
            previous_core[2] + 1,
        )
    previous_parts = previous_prerelease.rsplit(".", 1)
    successor_parts = (successor_prerelease or "").rsplit(".", 1)
    return (
        successor_core == previous_core
        and len(previous_parts) == 2
        and len(successor_parts) == 2
        and previous_parts[0] == successor_parts[0]
        and previous_parts[1].isdigit()
        and successor_parts[1].isdigit()
        and int(successor_parts[1]) == int(previous_parts[1]) + 1
    )


def conflict_component_names(conflicts: Any) -> list[str]:
    if not isinstance(conflicts, list):
        raise RecoveryError("release plan failure conflicts must be a non-empty list", "plan-discovery")
    names = [conflict.get("component") if isinstance(conflict, dict) else None for conflict in conflicts]
    if (
        not names
        or any(not isinstance(name, str) or name not in COMPONENTS for name in names)
        or len(names) != len(set(names))
    ):
        raise RecoveryError(
            f"conflicting components must be unique names from {sorted(COMPONENTS)}",
            "plan-discovery",
        )
    expected_order = [name for name in COMPONENTS if name in names]
    if names != expected_order:
        raise RecoveryError("conflicting components must follow release-plan component order", "plan-discovery")
    return names


def validate_successor_transition(
    failed_plan: dict[str, Any],
    successor_plan: dict[str, Any],
    conflicts: list[Any],
) -> None:
    validate_plan(failed_plan)
    validate_plan(successor_plan)
    conflict_names = conflict_component_names(conflicts)
    if successor_plan["plan"] == failed_plan["plan"]:
        raise RecoveryError("a superseding release plan must use a new plan identity", "plan-discovery")
    if successor_plan["channel"] != failed_plan["channel"]:
        raise RecoveryError("a superseding release plan cannot change the release channel", "plan-discovery")
    if successor_plan["foundation"] != failed_plan["foundation"]:
        raise RecoveryError("a superseding release plan cannot change the candidate foundation", "plan-discovery")
    for name, identity in failed_plan["components"].items():
        successor_identity = successor_plan["components"][name]
        if name not in conflict_names and successor_identity != identity:
            raise RecoveryError(
                f"superseding release plan changes unaffected component {name}",
                "plan-discovery",
            )
    for conflict in conflicts:
        name = conflict["component"]
        failed_identity = failed_plan["components"][name]
        successor_identity = successor_plan["components"][name]
        if successor_identity == failed_identity:
            raise RecoveryError(
                f"superseding release plan leaves conflict unresolved for {name}",
                "plan-discovery",
            )
        if conflict["reason"] == SUPERSESSION_REASON:
            if successor_identity["commit"] != failed_identity["commit"]:
                raise RecoveryError(
                    f"superseding release plan must retain {name}'s conflicting planned commit",
                    "plan-discovery",
                )
            if not is_immediate_version_successor(
                failed_identity["version"],
                successor_identity["version"],
            ):
                raise RecoveryError(
                    f"superseding release plan must allocate {name}'s immediate next version",
                    "plan-discovery",
                )
        elif conflict["reason"] == SOURCE_MANIFEST_REASON:
            if successor_identity["version"] != failed_identity["version"]:
                raise RecoveryError(
                    f"superseding release plan must retain {name}'s intended version",
                    "plan-discovery",
                )
            if successor_identity["commit"] == failed_identity["commit"]:
                raise RecoveryError(
                    f"superseding release plan must replace {name}'s incompatible source commit",
                    "plan-discovery",
                )
        elif conflict["reason"] == OCCUPIED_SOURCE_MANIFEST_REASON:
            if not is_immediate_version_successor(
                failed_identity["version"],
                successor_identity["version"],
            ):
                raise RecoveryError(
                    f"superseding release plan must allocate {name}'s immediate next version",
                    "plan-discovery",
                )
            if successor_identity["commit"] == failed_identity["commit"]:
                raise RecoveryError(
                    f"superseding release plan must replace {name}'s incompatible tagged source commit",
                    "plan-discovery",
                )
        else:
            raise RecoveryError(
                f"release plan failure has an unsupported conflict reason for {name}",
                "plan-discovery",
            )


def validate_environment_protection_evidence(protection: Any) -> None:
    expected_keys = {
        "custom_branch_policies",
        "deployment_branch_policy",
        "environment_id",
        "environment_url",
        "required_reviewer_rule_ids",
    }
    if not isinstance(protection, dict) or set(protection) != expected_keys:
        raise RecoveryError(
            "release plan failure environment protection evidence has an invalid shape",
            "plan-discovery",
        )
    reviewer_rule_ids = protection["required_reviewer_rule_ids"]
    branch_policy = protection["deployment_branch_policy"]
    custom_policies = protection["custom_branch_policies"]
    if (
        type(protection["environment_id"]) is not int
        or protection["environment_id"] < 1
        or protection["environment_url"] != SUPERSESSION_ENVIRONMENT_URL
        or not isinstance(reviewer_rule_ids, list)
        or not reviewer_rule_ids
        or any(type(rule_id) is not int or rule_id < 1 for rule_id in reviewer_rule_ids)
        or reviewer_rule_ids != sorted(set(reviewer_rule_ids))
    ):
        raise RecoveryError(
            "release plan failure lacks protected-environment reviewer evidence",
            "plan-discovery",
        )
    if branch_policy != {"custom_branch_policies": True, "protected_branches": False}:
        raise RecoveryError(
            "release plan failure lacks the protected environment custom-branch policy",
            "plan-discovery",
        )
    if (
        not isinstance(custom_policies, list)
        or len(custom_policies) != 1
        or not isinstance(custom_policies[0], dict)
        or set(custom_policies[0]) != {"id", "name"}
        or type(custom_policies[0]["id"]) is not int
        or custom_policies[0]["id"] < 1
        or custom_policies[0]["name"] != "main"
    ):
        raise RecoveryError(
            "release plan failure lacks the protected environment custom main-branch policy",
            "plan-discovery",
        )


def validate_environment_approval_evidence(approval: Any, authorization: dict[str, Any]) -> None:
    expected_keys = {"comment", "environments", "run_attempt", "run_id", "state", "user"}
    if not isinstance(approval, dict) or set(approval) != expected_keys:
        raise RecoveryError(
            "release plan failure environment approval evidence has an invalid shape",
            "plan-discovery",
        )
    environments = approval["environments"]
    user = approval["user"]
    protection = authorization["environment_protection"]
    if (
        approval["state"] != "approved"
        or not isinstance(approval["comment"], str)
        or approval["run_id"] != authorization["run_id"]
        or approval["run_attempt"] != authorization["run_attempt"]
        or not isinstance(environments, list)
        or len(environments) != 1
        or not isinstance(environments[0], dict)
        or set(environments[0]) != {"html_url", "id", "name", "node_id", "url"}
    ):
        raise RecoveryError(
            "release plan failure lacks an approved deployment bound to its workflow run",
            "plan-discovery",
        )
    environment = environments[0]
    if (
        environment["id"] != protection["environment_id"]
        or type(environment["id"]) is not int
        or environment["name"] != SUPERSESSION_ENVIRONMENT
        or environment["url"] != SUPERSESSION_ENVIRONMENT_API_URL
        or environment["html_url"] != SUPERSESSION_ENVIRONMENT_URL
        or not isinstance(environment["node_id"], str)
        or not environment["node_id"]
    ):
        raise RecoveryError(
            "release plan failure approval names the wrong protected environment",
            "plan-discovery",
        )
    if not isinstance(user, dict) or set(user) != {"html_url", "id", "login", "node_id", "url"}:
        raise RecoveryError(
            "release plan failure approving user evidence has an invalid shape",
            "plan-discovery",
        )
    login = user["login"]
    if (
        type(user["id"]) is not int
        or user["id"] < 1
        or not isinstance(user["node_id"], str)
        or not user["node_id"]
        or not isinstance(login, str)
        or not re.fullmatch(r"[A-Za-z0-9-]{1,39}", login)
        or user["url"] != f"https://api.github.com/users/{login}"
        or user["html_url"] != f"https://github.com/{login}"
    ):
        raise RecoveryError(
            "release plan failure lacks a durable approving user identity",
            "plan-discovery",
        )


def validate_source_manifest_evidence(
    evidence: Any,
    component_name: str,
    identity: dict[str, str],
    *,
    must_match_version: bool,
) -> None:
    expected_keys = {"declared_version", "package", "path", "sha256", "source_commit", "url"}
    specification = SOURCE_MANIFESTS.get(component_name)
    if (
        specification is None
        or not isinstance(evidence, dict)
        or set(evidence) != expected_keys
        or evidence["path"] != specification["path"]
        or evidence["package"] != specification["package"]
        or evidence["source_commit"] != identity["commit"]
        or not re.fullmatch(r"[0-9a-f]{64}", str(evidence["sha256"]))
        or not isinstance(evidence["declared_version"], str)
        or not VERSION_PATTERN.fullmatch(evidence["declared_version"])
        or evidence["url"]
        != (
            f"https://github.com/{COMPONENTS[component_name].repository}/blob/"
            f"{identity['commit']}/{specification['path']}"
        )
    ):
        raise RecoveryError(
            f"release plan failure has invalid source-manifest evidence for {component_name}",
            "plan-discovery",
        )
    version_matches = evidence["declared_version"] == identity["version"]
    if version_matches is not must_match_version:
        state = "match" if must_match_version else "conflict with"
        raise RecoveryError(
            f"release plan failure source manifest does not {state} {component_name} version allocation",
            "plan-discovery",
        )


def publication_absence_locations(
    component_name: str,
    version: str,
) -> tuple[dict[str, str], dict[str, str]]:
    component = COMPONENTS[component_name]
    encoded_version = urllib.parse.quote(version, safe="")
    release = {
        "api_url": f"https://api.github.com/repos/{component.repository}/releases/tags/{encoded_version}",
        "status": "absent",
        "url": f"https://github.com/{component.repository}/releases/tag/{encoded_version}",
    }
    encoded_package = urllib.parse.quote(component.package, safe="")
    if component.distribution == "pypi":
        distribution = {
            "api_url": f"https://pypi.org/pypi/{encoded_package}/{encoded_version}/json",
            "kind": "pypi",
            "status": "absent",
            "url": f"https://pypi.org/project/{encoded_package}/{encoded_version}/",
        }
    elif component.distribution == "crates.io":
        distribution = {
            "api_url": f"https://crates.io/api/v1/crates/{encoded_package}/{encoded_version}",
            "kind": "crates.io",
            "status": "absent",
            "url": f"https://crates.io/crates/{encoded_package}/{encoded_version}",
        }
    else:
        raise RecoveryError(
            f"{component_name} has no supported source-manifest distribution absence proof",
            "plan-discovery",
        )
    return release, distribution


def validate_occupied_source_manifest_evidence(
    conflict: dict[str, Any],
    component_name: str,
    identity: dict[str, str],
) -> None:
    component = COMPONENTS[component_name]
    source_tag = conflict["source_tag"]
    if (
        not isinstance(source_tag, dict)
        or set(source_tag) != {"commit", "repository", "tag", "tag_object", "url"}
        or source_tag["repository"] != component.repository
        or source_tag["tag"] != identity["version"]
        or source_tag["commit"] != identity["commit"]
        or not COMMIT_PATTERN.fullmatch(str(source_tag["tag_object"]))
        or source_tag["url"] != f"https://github.com/{component.repository}/tree/{identity['version']}"
    ):
        raise RecoveryError(
            f"release plan failure does not prove {component_name}'s occupied planned source tag",
            "plan-discovery",
        )
    expected_release, expected_distribution = publication_absence_locations(component_name, identity["version"])
    if conflict["github_release"] != expected_release:
        raise RecoveryError(
            f"release plan failure lacks {component_name} GitHub Release absence evidence",
            "plan-discovery",
        )
    if conflict["distribution"] != expected_distribution:
        raise RecoveryError(
            f"release plan failure lacks {component_name} distribution absence evidence",
            "plan-discovery",
        )


def canonical_cli_embedded_identity(version: str, commit: str) -> str:
    return f"dw {version.lstrip('v')} (commit {commit[:12]})"


def require_distribution_identity(
    distribution: dict[str, Any],
    component_name: str,
    version: str,
    observed_commit: str,
) -> None:
    component = COMPONENTS[component_name]
    if distribution.get("kind") != component.distribution:
        raise RecoveryError("public distribution evidence has the wrong kind", "plan-discovery")
    if component.distribution == "composer":
        matches = (
            distribution.get("source_reference") == observed_commit
            and distribution.get("dist_reference") == observed_commit
        )
    elif component.distribution == "github-release":
        package_source = distribution.get("package_source")
        matches = (
            isinstance(package_source, dict)
            and set(package_source) == {"commit", "embedded_phar_identity"}
            and package_source.get("commit") == observed_commit
            and package_source.get("embedded_phar_identity")
            == canonical_cli_embedded_identity(version, observed_commit)
        )
        authority = distribution.get("build_attestation_authority")
        exact_tag_authority = {
            "mode": "exact-tag",
            "ref": f"refs/tags/{version}",
            "commit": observed_commit,
        }
        qualified_main_authority = {
            "mode": "qualified-main-workflow",
            "ref": "refs/heads/main",
            "workflow": f"{component.repository}/.github/workflows/release.yml",
        }
        if distribution.get("build_attestations_verified") is not True or authority not in (
            exact_tag_authority,
            qualified_main_authority,
        ):
            raise RecoveryError(
                "public distribution evidence has an untrusted build attestation authority",
                "plan-discovery",
            )
    elif component.distribution == "pypi":
        source = distribution.get("source_identity")
        matches = isinstance(source, dict) and source.get("source_commit") == observed_commit
    elif component.distribution == "crates.io":
        matches = distribution.get("archive_vcs_commit") == observed_commit
    else:
        configs = distribution.get("configs")
        matches = (
            isinstance(configs, list)
            and bool(configs)
            and all(
                isinstance(config, dict)
                and isinstance(config.get("labels"), dict)
                and config["labels"].get("org.opencontainers.image.revision") == observed_commit
                for config in configs
            )
        )
    if not matches:
        raise RecoveryError(
            "public distribution evidence does not bind the observed source commit",
            "plan-discovery",
        )


def validate_conflict_record(
    conflict: Any,
    failed_plan: dict[str, Any],
    successor_plan: dict[str, Any],
) -> None:
    if not isinstance(conflict, dict):
        raise RecoveryError(
            "release plan failure conflict evidence has an invalid shape",
            "plan-discovery",
        )
    component_name = conflict.get("component")
    if component_name not in COMPONENTS:
        raise RecoveryError(
            "release plan failure names an unknown conflicting component",
            "plan-discovery",
        )
    identity = failed_plan["components"][component_name]
    successor_identity = successor_plan["components"][component_name]
    common_identity_matches = (
        conflict.get("version") == identity["version"]
        and conflict.get("planned_commit") == identity["commit"]
    )
    reason = conflict.get("reason")
    if reason == SUPERSESSION_REASON:
        expected_keys = {
            "component",
            "version",
            "planned_commit",
            "observed_commit",
            "reason",
            "github_release",
            "distribution",
        }
        if (
            set(conflict) != expected_keys
            or not common_identity_matches
            or not COMMIT_PATTERN.fullmatch(str(conflict.get("observed_commit", "")))
            or conflict["observed_commit"] == identity["commit"]
        ):
            raise RecoveryError(
                "release plan failure conflict does not prove a different public source identity",
                "plan-discovery",
            )
        release = conflict["github_release"]
        if (
            not isinstance(release, dict)
            or set(release) != {"id", "url"}
            or type(release["id"]) is not int
            or release["id"] < 1
            or not isinstance(release["url"], str)
            or not release["url"].startswith(
                f"https://github.com/{COMPONENTS[component_name].repository}/releases/"
            )
        ):
            raise RecoveryError(
                "release plan failure lacks durable GitHub Release evidence",
                "plan-discovery",
            )
        distribution = conflict["distribution"]
        if not isinstance(distribution, dict):
            raise RecoveryError(
                "release plan failure lacks matching distribution evidence",
                "plan-discovery",
            )
        require_distribution_identity(
            distribution,
            component_name,
            conflict["version"],
            conflict["observed_commit"],
        )
    elif reason == SOURCE_MANIFEST_REASON:
        expected_keys = {
            "component",
            "version",
            "planned_commit",
            "reason",
            "source_manifest",
            "successor_source_manifest",
        }
        if set(conflict) != expected_keys or not common_identity_matches:
            raise RecoveryError(
                "release plan failure manifest conflict evidence has an invalid shape",
                "plan-discovery",
            )
        validate_source_manifest_evidence(
            conflict["source_manifest"],
            component_name,
            identity,
            must_match_version=False,
        )
        validate_source_manifest_evidence(
            conflict["successor_source_manifest"],
            component_name,
            successor_identity,
            must_match_version=True,
        )
    elif reason == OCCUPIED_SOURCE_MANIFEST_REASON:
        expected_keys = {
            "component",
            "version",
            "planned_commit",
            "reason",
            "source_manifest",
            "source_tag",
            "github_release",
            "distribution",
            "successor_source_manifest",
        }
        if set(conflict) != expected_keys or not common_identity_matches:
            raise RecoveryError(
                "release plan failure occupied manifest conflict evidence has an invalid shape",
                "plan-discovery",
            )
        validate_source_manifest_evidence(
            conflict["source_manifest"],
            component_name,
            identity,
            must_match_version=False,
        )
        validate_source_manifest_evidence(
            conflict["successor_source_manifest"],
            component_name,
            successor_identity,
            must_match_version=True,
        )
        validate_occupied_source_manifest_evidence(conflict, component_name, identity)
    else:
        raise RecoveryError(
            f"release plan failure has an unsupported conflict reason for {component_name}",
            "plan-discovery",
        )


def validate_supersession_record(
    record: Any,
    failed_plan: dict[str, Any],
    failed_plan_commit: str,
    successor_plan: dict[str, Any],
) -> None:
    expected = {
        "schema",
        "outcome",
        "failed_plan",
        "conflicts",
        "successor_plan",
        "authorization",
    }
    if not isinstance(record, dict) or set(record) != expected:
        raise RecoveryError(
            f"release plan failure record keys must be exactly {sorted(expected)}",
            "plan-discovery",
        )
    expected_failed = {
        "tag": f"{PLAN_TAG_PREFIX}{failed_plan['plan']}",
        "commit": failed_plan_commit,
        "sha256": manifest_digest(failed_plan),
    }
    if record["schema"] != "durable-workflow.release-plan-failure/v1":
        raise RecoveryError(
            "release plan failure record has an unsupported schema",
            "plan-discovery",
        )
    if record["outcome"] != "terminal-failure" or record["failed_plan"] != expected_failed:
        raise RecoveryError(
            "release plan failure record does not terminate this exact immutable plan",
            "plan-discovery",
        )
    expected_successor = {
        "tag": f"{PLAN_TAG_PREFIX}{successor_plan['plan']}",
        "sha256": manifest_digest(successor_plan),
    }
    if record["successor_plan"] != expected_successor:
        raise RecoveryError(
            "release plan failure record names a different successor plan",
            "plan-discovery",
        )
    conflicts = record["conflicts"]
    conflict_component_names(conflicts)
    for conflict in conflicts:
        validate_conflict_record(conflict, failed_plan, successor_plan)
    validate_successor_transition(failed_plan, successor_plan, conflicts)

    authorization = record["authorization"]
    authorization_keys = {
        "actor",
        "environment",
        "environment_approval",
        "environment_protection",
        "repository",
        "run_attempt",
        "run_id",
        "run_url",
        "workflow_commit",
        "workflow_ref",
    }
    if not isinstance(authorization, dict) or set(authorization) != authorization_keys:
        raise RecoveryError(
            "release plan failure authorization evidence has an invalid shape",
            "plan-discovery",
        )
    protection = authorization["environment_protection"]
    validate_environment_protection_evidence(protection)
    workflow_ref = f"{CONTROL_REPOSITORY}/{SUPERSESSION_WORKFLOW}@refs/heads/main"
    if (
        authorization.get("repository") != CONTROL_REPOSITORY
        or authorization.get("environment") != SUPERSESSION_ENVIRONMENT
        or authorization.get("workflow_ref") != workflow_ref
        or not COMMIT_PATTERN.fullmatch(str(authorization.get("workflow_commit", "")))
        or not re.fullmatch(r"[A-Za-z0-9-]{1,39}", str(authorization.get("actor", "")))
        or type(authorization.get("run_id")) is not int
        or authorization["run_id"] < 1
        or type(authorization.get("run_attempt")) is not int
        or authorization["run_attempt"] < 1
        or authorization.get("run_url")
        != f"https://github.com/{CONTROL_REPOSITORY}/actions/runs/{authorization.get('run_id')}"
    ):
        raise RecoveryError(
            "release plan failure was not authorized by the protected supersession workflow",
            "plan-discovery",
        )
    validate_environment_approval_evidence(authorization["environment_approval"], authorization)


def validate_release_preparation(preparation: Any, plan: dict[str, Any]) -> None:
    if not isinstance(preparation, dict) or set(preparation) != {
        "schema",
        "release_plan",
        "components",
    }:
        raise RecoveryError(
            "release preparation has an invalid top-level shape", "plan-discovery"
        )
    if preparation["schema"] != PREPARATION_SCHEMA or preparation["release_plan"] != {
        "tag": f"{PLAN_TAG_PREFIX}{plan['plan']}",
        "sha256": manifest_digest(plan),
    }:
        raise RecoveryError(
            "release preparation names a different immutable plan", "plan-discovery"
        )
    components = preparation["components"]
    if not isinstance(components, dict) or set(components) != set(COMPONENTS):
        raise RecoveryError(
            "release preparation does not cover the exact component tuple",
            "plan-discovery",
        )
    release_dates: set[str] = set()
    for name, entry in components.items():
        identity = plan["components"][name]
        if not isinstance(entry, dict) or set(entry) != {
            "version",
            "source_commit",
            "release_notes",
        }:
            raise RecoveryError(
                f"release preparation for {name} has an invalid shape", "plan-discovery"
            )
        if (
            entry["version"] != identity["version"]
            or entry["source_commit"] != identity["commit"]
        ):
            raise RecoveryError(
                f"release preparation for {name} names a different planned identity",
                "plan-discovery",
            )
        notes = entry["release_notes"]
        if not isinstance(notes, dict) or set(notes) != {
            "format",
            "heading",
            "markdown",
            "release_date",
            "sha256",
            "source",
        }:
            raise RecoveryError(
                f"release preparation for {name} has invalid release notes",
                "plan-discovery",
            )
        release_date = notes["release_date"]
        try:
            parsed_date = dt.date.fromisoformat(release_date)
        except (TypeError, ValueError) as error:
            raise RecoveryError(
                f"release preparation for {name} has an invalid release date",
                "plan-discovery",
            ) from error
        heading = f"## [{identity['version']}] - {parsed_date.isoformat()}"
        markdown = notes["markdown"]
        if (
            notes["format"] != MARKDOWN_MEDIA_TYPE
            or release_date != parsed_date.isoformat()
            or notes["heading"] != heading
            or not isinstance(markdown, str)
            or not markdown.startswith(f"{heading}\n\n")
            or not markdown.endswith("\n")
            or notes["sha256"] != hashlib.sha256(markdown.encode()).hexdigest()
        ):
            raise RecoveryError(
                f"release preparation for {name} has mismatched versioned note content",
                "plan-discovery",
            )
        source = notes["source"]
        expected_kind = (
            "changelog-unreleased"
            if name in SOURCE_CHANGELOGS
            else "source-commit-message"
        )
        expected_source_url = (
            f"https://github.com/{COMPONENTS[name].repository}/blob/{identity['commit']}/CHANGELOG.md"
            if name in SOURCE_CHANGELOGS
            else f"https://github.com/{COMPONENTS[name].repository}/commit/{identity['commit']}"
        )
        if (
            not isinstance(source, dict)
            or set(source) != {"kind", "sha256", "url"}
            or source["kind"] != expected_kind
            or not re.fullmatch(r"[0-9a-f]{64}", str(source["sha256"]))
            or source["url"] != expected_source_url
        ):
            raise RecoveryError(
                f"release preparation for {name} has invalid note-source evidence",
                "plan-discovery",
            )
        release_dates.add(release_date)
    if len(release_dates) != 1:
        raise RecoveryError(
            "release preparation components do not share one release date",
            "plan-discovery",
        )


def resolve_tag(client: PublicClient, repository: str, tag: str) -> str | None:
    encoded = urllib.parse.quote(tag, safe="")
    try:
        ref = client.json(
            f"https://api.github.com/repos/{repository}/git/ref/tags/{encoded}"
        )
    except NotFound:
        return None
    target = ref.get("object", {})
    seen: set[str] = set()
    while target.get("type") == "tag":
        sha = target.get("sha")
        if not isinstance(sha, str) or sha in seen:
            raise RecoveryError(
                f"invalid annotated tag chain for {repository}@{tag}", "tag-preflight"
            )
        seen.add(sha)
        target = client.json(
            f"https://api.github.com/repos/{repository}/git/tags/{sha}"
        ).get("object", {})
    if target.get("type") != "commit" or not COMMIT_PATTERN.fullmatch(
        str(target.get("sha", ""))
    ):
        raise RecoveryError(
            f"tag {repository}@{tag} does not resolve to a commit", "tag-preflight"
        )
    return str(target["sha"])


def read_record(client: PublicClient, tag: str, commit: str, filename: str) -> Any:
    if resolve_tag(client, CONTROL_REPOSITORY, tag) != commit:
        raise RecoveryError(
            f"immutable record {tag} does not resolve to {commit}", "plan-discovery"
        )
    encoded_filename = urllib.parse.quote(filename, safe="/")
    raw = client.bytes(
        f"https://api.github.com/repos/{CONTROL_REPOSITORY}/contents/{encoded_filename}?ref={commit}",
        accept="application/vnd.github.raw+json",
    )
    try:
        return json.loads(raw)
    except json.JSONDecodeError as error:
        raise RecoveryError(
            f"immutable record {tag}:{filename} is not valid JSON", "plan-discovery"
        ) from error


def read_plan_authority(client: PublicClient, tag: str, commit: str) -> tuple[dict[str, Any], dict[str, Any] | None]:
    plan = read_record(client, tag, commit, "release-plan.json")
    validate_plan(plan)
    if tag != f"{PLAN_TAG_PREFIX}{plan['plan']}":
        raise RecoveryError("release plan tag and document identity differ", "plan-discovery")
    try:
        preparation = read_record(client, tag, commit, "release-preparation.json")
    except NotFound:
        preparation = None
    if preparation is not None:
        validate_release_preparation(preparation, plan)
    return plan, preparation


def validate_release_mirrors(
    client: PublicClient,
    tag: str,
    release: Any,
    plan: dict[str, Any],
    preparation: dict[str, Any] | None,
) -> None:
    if not isinstance(release, dict) or release.get("tag_name") != tag:
        raise RecoveryError(f"release plan {tag} has invalid GitHub Release metadata", "plan-discovery")
    if release.get("draft"):
        raise RecoveryError(f"release plan {tag} is still a draft", "plan-discovery")
    assets_value = release.get("assets")
    if not isinstance(assets_value, list) or not all(isinstance(asset, dict) for asset in assets_value):
        raise RecoveryError(f"release plan {tag} has malformed Release assets", "plan-discovery")
    assets = {asset.get("name"): asset for asset in assets_value}
    if len(assets) != len(assets_value):
        raise RecoveryError(f"release plan {tag} has duplicate Release asset names", "plan-discovery")
    records = [("release-plan.json", plan)]
    if preparation is not None:
        records.append(("release-preparation.json", preparation))
    for filename, value in records:
        asset = assets.get(filename)
        if not isinstance(asset, dict) or not isinstance(asset.get("browser_download_url"), str):
            raise RecoveryError(
                f"release plan {tag} lacks its durable {filename} mirror asset",
                "plan-discovery",
            )
        mirror = client.bytes(asset["browser_download_url"])
        if mirror != canonical_json(value):
            raise RecoveryError(
                f"release plan {tag} {filename} mirror differs from immutable Git authority",
                "plan-discovery",
            )
    if preparation is None and "release-preparation.json" in assets:
        raise RecoveryError(
            f"release plan {tag} release-preparation.json mirror lacks immutable Git authority",
            "plan-discovery",
        )


def immutable_plan_recorded_at(client: PublicClient, commit: str) -> dt.datetime:
    value = client.json(f"https://api.github.com/repos/{CONTROL_REPOSITORY}/git/commits/{commit}")
    committer = value.get("committer") if isinstance(value, dict) else None
    recorded_at = committer.get("date") if isinstance(committer, dict) else None
    try:
        parsed = dt.datetime.fromisoformat(str(recorded_at).replace("Z", "+00:00"))
    except ValueError as error:
        raise RecoveryError("release plan Git commit lacks an immutable recorded-at time", "plan-discovery") from error
    if not isinstance(value, dict) or value.get("sha") != commit or parsed.tzinfo is None or parsed.utcoffset() is None:
        raise RecoveryError("release plan Git commit has invalid immutable metadata", "plan-discovery")
    return parsed.astimezone(dt.UTC)


def list_release_plan_tags(client: PublicClient) -> list[str]:
    url = f"https://api.github.com/repos/{CONTROL_REPOSITORY}/git/matching-refs/tags/{PLAN_TAG_PREFIX}"
    refs = client.json(url)
    if not isinstance(refs, list):
        raise RecoveryError("GitHub did not return the immutable release-plan tag registry", "plan-discovery")
    tags: list[str] = []
    for ref in refs:
        value = ref.get("ref") if isinstance(ref, dict) else None
        tag = value.removeprefix("refs/tags/") if isinstance(value, str) else ""
        if (
            value != f"refs/tags/{tag}"
            or not tag.startswith(PLAN_TAG_PREFIX)
            or not PLAN_PATTERN.fullmatch(tag.removeprefix(PLAN_TAG_PREFIX))
        ):
            raise RecoveryError(
                "GitHub returned a malformed immutable release-plan tag registry entry",
                "plan-discovery",
            )
        tags.append(tag)
    if not tags:
        raise RecoveryError("no public release plan is available", "plan-discovery")
    if len(tags) != len(set(tags)):
        raise RecoveryError("immutable release-plan tag registry contains duplicate authorities", "plan-discovery")
    return tags


def completion_manifest(
    plan: dict[str, Any],
    commit: str,
    preparation: dict[str, Any] | None,
) -> dict[str, Any]:
    result = {
        "schema": "durable-workflow.release-candidate/v1",
        "candidate": plan["plan"],
        "channel": plan["channel"],
        "release_plan": {
            "tag": f"{PLAN_TAG_PREFIX}{plan['plan']}",
            "commit": commit,
            "sha256": manifest_digest(plan),
        },
        "components": plan["components"],
    }
    if preparation is not None:
        result["release_preparation_sha256"] = manifest_digest(preparation)
    return result


def direct_plan_lifecycle(
    client: PublicClient,
    tag: str,
    commit: str,
    plan: dict[str, Any],
    preparation: dict[str, Any] | None,
) -> tuple[str, str | dict[str, Any] | None]:
    completion_tag = f"{COMPLETION_TAG_PREFIX}{plan['channel']}/{plan['plan']}"
    failure_tag = f"{FAILURE_TAG_PREFIX}{plan['plan']}"
    completion_commit = resolve_tag(client, CONTROL_REPOSITORY, completion_tag)
    failure_commit = resolve_tag(client, CONTROL_REPOSITORY, failure_tag)
    if completion_commit is not None and failure_commit is not None:
        raise RecoveryError(
            f"release plan {tag} has conflicting completion and terminal-failure records",
            "plan-discovery",
        )
    if completion_commit is not None:
        completion = read_record(client, completion_tag, completion_commit, "release-candidate.json")
        if completion != completion_manifest(plan, commit, preparation):
            raise RecoveryError(
                f"release plan {tag} has an invalid immutable completion record",
                "plan-discovery",
            )
        return "completed", None
    if failure_commit is not None:
        failure = read_record(client, failure_tag, failure_commit, "release-plan-failure.json")
        successor = read_record(client, failure_tag, failure_commit, "successor-release-plan.json")
        validate_plan(successor)
        validate_supersession_record(failure, plan, commit, successor)
        expected_successor = {
            "tag": f"{PLAN_TAG_PREFIX}{successor['plan']}",
            "sha256": manifest_digest(successor),
        }
        return "superseded", {
            **expected_successor,
            "plan": successor,
        }

    interruption_tag = f"{CONTINUITY_TAG_PREFIX}{plan['plan']}/interrupted"
    interruption_commit = resolve_tag(client, CONTROL_REPOSITORY, interruption_tag)
    if interruption_commit is None:
        return "actionable", None
    evidence = read_record(client, interruption_tag, interruption_commit, "continuity-evidence.json")
    interrupted_plan = read_record(client, interruption_tag, interruption_commit, "release-plan.json")
    digest = manifest_digest(plan)
    if (
        interrupted_plan != plan
        or not isinstance(evidence, dict)
        or evidence.get("schema") != CONTINUITY_EVIDENCE_SCHEMA
        or evidence.get("phase") != "interrupted"
        or evidence.get("outcome") != "intentionally-interrupted"
        or evidence.get("release_plan") != {"tag": tag, "sha256": digest}
        or evidence.get("plan_record") != {"tag": tag, "commit": commit, "sha256": digest}
    ):
        raise RecoveryError(
            f"release plan {tag} has an invalid immutable interruption record",
            "plan-discovery",
        )
    return "interrupted", interruption_tag


def accepted_continuity_supersession(
    client: PublicClient,
    authority: dict[str, Any],
) -> dict[str, str] | None:
    plan = authority["plan"]
    accepted_tag = f"{CONTINUITY_TAG_PREFIX}{plan['plan']}/accepted"
    accepted_commit = resolve_tag(client, CONTROL_REPOSITORY, accepted_tag)
    if accepted_commit is None:
        return None
    evidence = read_record(client, accepted_tag, accepted_commit, "continuity-evidence.json")
    accepted_plan = read_record(client, accepted_tag, accepted_commit, "release-plan.json")
    digest = manifest_digest(plan)
    if (
        accepted_plan != plan
        or not isinstance(evidence, dict)
        or evidence.get("schema") != CONTINUITY_EVIDENCE_SCHEMA
        or evidence.get("phase") != "accepted"
        or evidence.get("outcome") != "accepted"
        or evidence.get("release_plan") != {"tag": authority["tag"], "sha256": digest}
        or evidence.get("candidate_identity") != {"components": plan["components"], "plan_sha256": digest}
    ):
        raise RecoveryError(
            f"release plan {authority['tag']} has an invalid immutable continuity acceptance",
            "plan-discovery",
        )
    superseded = evidence.get("superseded_interruption")
    if superseded is None:
        return None
    if (
        not isinstance(superseded, dict)
        or set(superseded) != {"commit", "evidence_sha256", "plan_sha256", "reason", "tag"}
        or superseded.get("reason") != CONTINUITY_SUPERSESSION_REASON
        or not COMMIT_PATTERN.fullmatch(str(superseded.get("commit", "")))
        or not re.fullmatch(r"[0-9a-f]{64}", str(superseded.get("evidence_sha256", "")))
        or not re.fullmatch(r"[0-9a-f]{64}", str(superseded.get("plan_sha256", "")))
        or not str(superseded.get("tag", "")).startswith(CONTINUITY_TAG_PREFIX)
    ):
        raise RecoveryError(
            f"release plan {authority['tag']} has an invalid superseded interruption identity",
            "plan-discovery",
        )
    return dict(superseded)


def select_implicit_plan_authority(client: PublicClient) -> dict[str, Any]:
    authorities: list[dict[str, Any]] = []
    for tag in list_release_plan_tags(client):
        commit = resolve_tag(client, CONTROL_REPOSITORY, tag)
        if commit is None:
            raise RecoveryError(f"release plan tag {tag} is absent", "plan-discovery")
        plan, preparation = read_plan_authority(client, tag, commit)
        lifecycle, successor = direct_plan_lifecycle(client, tag, commit, plan, preparation)
        authorities.append(
            {
                "tag": tag,
                "commit": commit,
                "recorded_at": immutable_plan_recorded_at(client, commit),
                "plan": plan,
                "preparation": preparation,
                "lifecycle": lifecycle,
                "successor": successor,
            }
        )

    authorities.sort(key=lambda item: item["recorded_at"])
    if len({item["recorded_at"] for item in authorities}) != len(authorities):
        raise RecoveryError(
            "release plans have ambiguous immutable Git recorded-at authority",
            "plan-discovery",
        )
    by_tag = {item["tag"]: item for item in authorities}
    continuity_successors: dict[str, list[str]] = {}
    for successor in authorities:
        superseded = accepted_continuity_supersession(client, successor)
        if superseded is None:
            continue
        interruption_tag = superseded["tag"]
        matches = [
            item for item in authorities if item["lifecycle"] == "interrupted" and item["successor"] == interruption_tag
        ]
        if len(matches) != 1:
            raise RecoveryError(
                f"continuity successor {successor['tag']} names an unknown or ambiguous interruption",
                "plan-discovery",
            )
        interrupted = matches[0]
        interruption_commit = resolve_tag(client, CONTROL_REPOSITORY, interruption_tag)
        interruption_evidence = read_record(
            client,
            interruption_tag,
            superseded["commit"],
            "continuity-evidence.json",
        )
        if (
            interruption_commit != superseded["commit"]
            or manifest_digest(interruption_evidence) != superseded["evidence_sha256"]
            or manifest_digest(interrupted["plan"]) != superseded["plan_sha256"]
            or successor["recorded_at"] <= interrupted["recorded_at"]
        ):
            raise RecoveryError(
                f"continuity successor {successor['tag']} has conflicting interruption authority",
                "plan-discovery",
            )
        continuity_successors.setdefault(interrupted["tag"], []).append(successor["tag"])

    for interrupted_tag, successor_tags in continuity_successors.items():
        interrupted = by_tag[interrupted_tag]
        interrupted["lifecycle"] = "superseded"
        successor_tag = min(
            successor_tags,
            key=lambda tag: by_tag[tag]["recorded_at"],
        )
        successor = by_tag[successor_tag]
        interrupted["successor"] = {
            "tag": successor_tag,
            "sha256": manifest_digest(successor["plan"]),
            "plan": successor["plan"],
        }

    for authority in authorities:
        successor_identity = authority["successor"]
        if authority["lifecycle"] != "superseded" or successor_identity is None:
            continue
        if not isinstance(successor_identity, dict):
            raise RecoveryError(
                f"superseded release plan {authority['tag']} has a malformed successor identity",
                "plan-discovery",
            )
        successor_tag = successor_identity.get("tag")
        successor = by_tag.get(successor_tag)
        if successor is None:
            if authority is authorities[-1]:
                raise RecoveryError(
                    f"latest release plan {authority['tag']} is superseded but its successor is not recorded",
                    "plan-discovery",
                )
            raise RecoveryError(
                f"superseded release plan {authority['tag']} has an incomplete successor authority",
                "plan-discovery",
            )
        expected_successor_identity = {
            "tag": successor["tag"],
            "sha256": manifest_digest(successor["plan"]),
            "plan": successor["plan"],
        }
        if successor_identity != expected_successor_identity:
            raise RecoveryError(
                f"superseded release plan {authority['tag']} has a conflicting successor identity",
                "plan-discovery",
            )
        if successor["recorded_at"] <= authority["recorded_at"]:
            raise RecoveryError(
                f"superseded release plan {authority['tag']} names a non-successor Git authority",
                "plan-discovery",
            )

    nonterminal_older = [item for item in authorities[:-1] if item["lifecycle"] in {"actionable", "interrupted"}]
    if nonterminal_older:
        raise RecoveryError(
            f"release plan authority is ambiguous: {nonterminal_older[0]['tag']} remains "
            f"{nonterminal_older[0]['lifecycle']} before {authorities[-1]['tag']}",
            "plan-discovery",
        )
    selected = authorities[-1]
    if selected["lifecycle"] == "superseded":
        raise RecoveryError(
            f"latest release plan {selected['tag']} is superseded and cannot be recovered",
            "plan-discovery",
        )
    return selected


def discover_plan(
    client: PublicClient, requested_tag: str | None, component_name: str
) -> tuple[str, str, dict[str, Any], dict[str, Any] | None]:
    if component_name not in COMPONENTS:
        raise RecoveryError(f"unknown release component: {component_name}", "plan-discovery")
    if requested_tag:
        tag = requested_tag
        if not tag.startswith(PLAN_TAG_PREFIX):
            raise RecoveryError(f"release plan tag must start with {PLAN_TAG_PREFIX}", "plan-discovery")
        try:
            release = client.json(
                f"https://api.github.com/repos/{CONTROL_REPOSITORY}/releases/tags/{urllib.parse.quote(tag, safe='')}"
            )
        except NotFound as error:
            raise RecoveryError(f"release plan {tag} has no durable GitHub Release", "plan-discovery") from error
        commit = resolve_tag(client, CONTROL_REPOSITORY, tag)
        if commit is None:
            raise RecoveryError(f"release plan tag {tag} is absent", "plan-discovery")
        plan, preparation = read_plan_authority(client, tag, commit)
    else:
        selected = select_implicit_plan_authority(client)
        tag = selected["tag"]
        commit = selected["commit"]
        plan = selected["plan"]
        preparation = selected["preparation"]
        try:
            release = client.json(
                f"https://api.github.com/repos/{CONTROL_REPOSITORY}/releases/tags/{urllib.parse.quote(tag, safe='')}"
            )
        except NotFound as error:
            raise RecoveryError(f"release plan {tag} has no durable GitHub Release", "plan-discovery") from error
    validate_release_mirrors(client, tag, release, plan, preparation)
    if preparation is None:
        try:
            verify_component(client, component_name, plan["components"][component_name])
        except NotFound as error:
            raise RecoveryError(
                f"release plan {tag} lacks immutable release-preparation.json; "
                "only completed legacy releases may recover without it",
                "plan-discovery",
            ) from error
    return tag, commit, plan, preparation

_QUALIFIED_AUTHORITY_CONSTRUCTOR = object()


@dataclass(frozen=True, init=False)
class QualifiedRecoveryWorkflowAuthority:
    """Workflow identities read from one successfully qualified authority revision."""

    workflows: Mapping[str, Mapping[str, str]]
    source: Mapping[str, Any]

    def __init__(
        self,
        workflows: Mapping[str, Mapping[str, str]],
        source: Mapping[str, Any],
        *,
        _constructor: object,
    ) -> None:
        if _constructor is not _QUALIFIED_AUTHORITY_CONSTRUCTOR:
            raise RecoveryWorkflowAuthorityError(
                "recovery workflow authority was not produced by qualified loading"
            )
        object.__setattr__(self, "workflows", workflows)
        object.__setattr__(self, "source", source)

    def workflow(self, name: str) -> Mapping[str, str]:
        try:
            return self.workflows[name]
        except KeyError as error:
            raise RecoveryWorkflowAuthorityError(
                f"{name} recovery workflow is absent from the qualified authority"
            ) from error


def load_recovery_workflow_authority(
    client: PublicClient,
) -> QualifiedRecoveryWorkflowAuthority:
    identities = {
        name: (component.repository, component.default_branch)
        for name, component in COMPONENTS.items()
    }
    try:
        workflows, source = load_qualified_authority(client, identities)
    except RecoveryWorkflowAuthorityError as error:
        raise RecoveryError(str(error), "default-branch-preflight") from error
    return QualifiedRecoveryWorkflowAuthority(
        workflows,
        source,
        _constructor=_QUALIFIED_AUTHORITY_CONSTRUCTOR,
    )


def verify_recovery_workflow_source(
    authority: QualifiedRecoveryWorkflowAuthority,
    name: str,
    source: str,
) -> str:
    try:
        expected = authority.workflow(name)
        return verify_workflow_source(name, source, expected["sha256"])
    except RecoveryWorkflowAuthorityError as error:
        raise RecoveryError(str(error), "default-branch-preflight") from error


def select_publication_run(
    release_tag: str,
    release_commit: str,
    runs: Any,
) -> dict[str, Any]:
    if not VERSION_PATTERN.fullmatch(release_tag) or not COMMIT_PATTERN.fullmatch(
        release_commit
    ):
        raise RecoveryError(
            "publication run selection requires an exact release identity",
            "publication",
        )
    if not isinstance(runs, list):
        raise RecoveryError(
            "publication run metadata must be a JSON array", "publication"
        )

    exact_runs: list[dict[str, Any]] = []
    for run in runs:
        if not isinstance(run, dict) or run.get("headBranch") != release_tag:
            continue
        if run.get("headSha") != release_commit:
            raise RecoveryError(
                f"publication run {run.get('databaseId')} for {release_tag} is bound to a different source commit",
                "publication",
            )
        if not isinstance(run.get("databaseId"), int) or not isinstance(
            run.get("status"), str
        ):
            raise RecoveryError("publication run metadata is incomplete", "publication")
        exact_runs.append(run)

    selected = next((run for run in exact_runs if run["status"] != "completed"), None)
    action = "wait"
    if selected is None:
        selected = next(
            (
                run
                for run in exact_runs
                if run["status"] == "completed" and run.get("conclusion") == "success"
            ),
            None,
        )
        action = "complete"
    if selected is None:
        selected = next(
            (run for run in exact_runs if run["status"] == "completed"), None
        )
        action = "rerun"
    if selected is None:
        return {
            "action": "dispatch",
            "run_id": None,
            "status": None,
            "conclusion": None,
        }
    return {
        "action": action,
        "run_id": selected["databaseId"],
        "status": selected["status"],
        "conclusion": selected.get("conclusion"),
    }


def verify_plan_authority(
    client: PublicClient, plan: dict[str, Any]
) -> tuple[dict[str, str], dict[str, dict[str, Any]]]:
    foundation = read_record(
        client, FOUNDATION_TAG, FOUNDATION_COMMIT, "candidate.json"
    )
    if foundation.get("candidate") != "beta-continuity-foundation":
        raise RecoveryError(
            "immutable candidate foundation has an unexpected identity",
            "plan-preflight",
        )
    authority = load_recovery_workflow_authority(client)
    branches: dict[str, str] = {}
    recovery_workflows: dict[str, dict[str, Any]] = {}
    for name, component in COMPONENTS.items():
        repository = client.json(f"https://api.github.com/repos/{component.repository}")
        actual = repository.get("default_branch")
        if actual != component.default_branch:
            raise RecoveryError(
                f"{component.repository} default branch is {actual!r}; recovery requires {component.default_branch!r}",
                "default-branch-preflight",
            )
        branches[name] = str(actual)
        expected = authority.workflow(name)
        expected_path = expected["path"]
        workflow = client.json(
            f"https://api.github.com/repos/{component.repository}/actions/workflows/release-plan-recovery.yml"
        )
        if (
            workflow.get("path") != expected_path
            or workflow.get("state") != expected["state"]
        ):
            raise RecoveryError(
                f"{component.repository} does not expose an active {expected_path} on its default branch",
                "default-branch-preflight",
            )
        source = client.bytes(
            f"https://api.github.com/repos/{component.repository}/contents/{expected_path}"
            f"?ref={component.default_branch}",
            accept="application/vnd.github.raw+json",
        ).decode("utf-8")
        source_sha256 = verify_recovery_workflow_source(authority, name, source)
        recovery_workflows[name] = {
            "authority": authority.source,
            "default_branch": component.default_branch,
            "path": expected_path,
            "sha256": source_sha256,
            "state": workflow["state"],
            "workflow_id": workflow.get("id"),
            "url": workflow.get("html_url"),
        }
    authorization = plan["beta_authorization"]
    if authorization is not None:
        record = read_record(
            client,
            authorization["tag"],
            authorization["commit"],
            "beta-authorization.json",
        )
        expected = {
            "schema": "durable-workflow.beta-authorization/v1",
            "channel": "beta",
            "candidate": plan["plan"],
            "components": plan["components"],
        }
        if record != expected:
            raise RecoveryError(
                "beta authorization names a different candidate or component tuple",
                "channel-authorization",
            )
    return branches, recovery_workflows


def require_source_tag(
    client: PublicClient, name: str, identity: dict[str, str]
) -> str:
    component = COMPONENTS[name]
    source = resolve_tag(client, component.repository, identity["version"])
    if source is None:
        raise NotFound(
            f"source tag {component.repository}@{identity['version']} is not present",
            "source-tag",
        )
    if source != identity["commit"]:
        raise RecoveryError(
            f"source tag {component.repository}@{identity['version']} points to {source}, not {identity['commit']}",
            "source-tag",
        )
    return source


def verify_github_release(
    client: PublicClient, name: str, version: str
) -> dict[str, Any]:
    component = COMPONENTS[name]
    encoded = urllib.parse.quote(version, safe="")
    try:
        release = client.json(
            f"https://api.github.com/repos/{component.repository}/releases/tags/{encoded}"
        )
    except NotFound as error:
        raise NotFound(
            f"GitHub Release {component.repository}@{version} is absent",
            "github-release",
        ) from error
    if release.get("draft") or release.get("tag_name") != version:
        raise RecoveryError(
            f"GitHub Release {component.repository}@{version} is not public",
            "github-release",
        )
    return {"id": release.get("id"), "url": release.get("html_url")}


def verify_composer(
    client: PublicClient, component: Component, version: str, commit: str
) -> dict[str, Any]:
    encoded = "/".join(
        urllib.parse.quote(part, safe="") for part in component.package.split("/")
    )
    url = f"https://repo.packagist.org/p2/{encoded}.json"
    payload = client.json(url)
    releases = payload.get("packages", {}).get(component.package, [])
    release = next(
        (
            item
            for item in releases
            if str(item.get("version", "")).lstrip("v") == version.lstrip("v")
        ),
        None,
    )
    if release is None:
        raise NotFound(
            f"Packagist does not expose {component.package}@{version}",
            "registry-publication",
        )
    source = release.get("source", {}).get("reference")
    dist = release.get("dist", {}).get("reference")
    if source != commit or dist != commit:
        raise RecoveryError(
            f"Packagist identity for {component.package}@{version} is {source}/{dist}, not {commit}",
            "registry-publication",
        )
    return {
        "kind": "composer",
        "registry": url,
        "source_reference": source,
        "dist_reference": dist,
    }


def oci_json(
    client: PublicClient, url: str, token: str, accept: str
) -> tuple[Any, str | None]:
    response = client.request(
        url, headers={"Authorization": f"Bearer {token}"}, accept=accept
    )
    with response:
        return json.load(response), response.headers.get("Docker-Content-Digest")


def verify_oci(
    client: PublicClient, component: Component, version: str, commit: str
) -> dict[str, Any]:
    repository = component.package.split("/", 1)[1]
    token_url = (
        "https://auth.docker.io/token?service=registry.docker.io&scope="
        + urllib.parse.quote(f"repository:{repository}:pull")
    )
    token = client.json(token_url).get("token")
    if not token:
        raise RecoveryError(
            f"Docker Hub did not grant public pull access to {component.package}:{version}"
        )
    accept = ", ".join(
        (
            "application/vnd.oci.image.index.v1+json",
            "application/vnd.docker.distribution.manifest.list.v2+json",
            "application/vnd.oci.image.manifest.v1+json",
            "application/vnd.docker.distribution.manifest.v2+json",
        )
    )
    url = f"https://registry-1.docker.io/v2/{repository}/manifests/{urllib.parse.quote(version, safe='')}"
    try:
        manifest, digest = oci_json(client, url, str(token), accept)
    except NotFound as error:
        raise NotFound(
            f"Docker Hub does not expose {component.package}:{version}",
            "registry-publication",
        ) from error
    if not re.fullmatch(r"sha256:[0-9a-f]{64}", str(digest or "")):
        raise RecoveryError(
            f"Docker Hub image {component.package}:{version} has no immutable digest"
        )
    descriptors = manifest.get("manifests")
    if not isinstance(descriptors, list):
        raise RecoveryError(
            f"Docker Hub image {component.package}:{version} is not multi-platform"
        )
    platforms: set[str] = set()
    for descriptor in descriptors:
        platform = descriptor.get("platform", {})
        label = f"{platform.get('os')}/{platform.get('architecture')}"
        if label not in {"linux/amd64", "linux/arm64"}:
            continue
        child, child_digest = oci_json(
            client,
            f"https://registry-1.docker.io/v2/{repository}/manifests/{descriptor['digest']}",
            str(token),
            accept,
        )
        if child_digest != descriptor["digest"]:
            raise RecoveryError(
                f"Docker Hub platform digest changed for {component.package}:{version}"
            )
        config_digest = child.get("config", {}).get("digest")
        config = client.json(
            f"https://registry-1.docker.io/v2/{repository}/blobs/{config_digest}",
            headers={"Authorization": f"Bearer {token}"},
        )
        labels = config.get("config", {}).get("Labels") or {}
        if labels.get("org.opencontainers.image.revision") != commit:
            raise RecoveryError(
                f"Docker Hub image {component.package}:{version} names a different source commit"
            )
        if labels.get("dev.durable-workflow.release.tag") != version:
            raise RecoveryError(
                f"Docker Hub image {component.package}:{version} names a different release tag"
            )
        platforms.add(label)
    if platforms != {"linux/amd64", "linux/arm64"}:
        raise RecoveryError(
            f"Docker Hub image {component.package}:{version} lacks required Linux platforms"
        )
    return {
        "kind": "oci",
        "image": f"{component.package}:{version}",
        "digest": digest,
        "platforms": sorted(platforms),
    }


def archive_files(path: Path, *, zipped: bool = False) -> dict[str, bytes]:
    files: dict[str, bytes] = {}
    if zipped:
        with zipfile.ZipFile(path) as archive:
            for member in archive.infolist():
                if not member.is_dir():
                    files[member.filename] = archive.read(member)
        return files
    with tarfile.open(path, "r:*") as archive:
        for member in archive.getmembers():
            if (
                member.isfile()
                and (extracted := archive.extractfile(member)) is not None
            ):
                files[member.name] = extracted.read()
    return files


def strip_root(files: dict[str, bytes]) -> dict[str, bytes]:
    return {
        relative: content
        for name, content in files.items()
        if (separator := name.partition("/"))[1] and (relative := separator[2])
    }


def verify_pypi(
    client: PublicClient, component: Component, version: str, commit: str
) -> dict[str, Any]:
    package = urllib.parse.quote(component.package, safe="")
    encoded_version = urllib.parse.quote(version, safe="")
    url = f"https://pypi.org/pypi/{package}/{encoded_version}/json"
    try:
        payload = client.json(url)
    except NotFound as error:
        raise NotFound(
            f"PyPI does not expose {component.package}=={version}",
            "registry-publication",
        ) from error
    files = [item for item in payload.get("urls", []) if not item.get("yanked")]
    sdist = next((item for item in files if item.get("packagetype") == "sdist"), None)
    wheels = [item for item in files if item.get("packagetype") == "bdist_wheel"]
    if sdist is None or not wheels:
        raise RecoveryError(
            f"PyPI release {component.package}=={version} lacks a wheel or source archive"
        )
    with tempfile.TemporaryDirectory(prefix="release-recovery-pypi-") as temporary:
        directory = Path(temporary)
        source_path = directory / "source.tar.gz"
        sdist_path = directory / str(sdist["filename"])
        client.download(
            f"https://github.com/{component.repository}/archive/{commit}.tar.gz",
            source_path,
        )
        client.download(
            sdist["url"],
            sdist_path,
            expected_sha256=sdist.get("digests", {}).get("sha256"),
        )
        source_files = strip_root(archive_files(source_path))
        sdist_files = strip_root(archive_files(sdist_path))
        compared = 0
        for name, content in sdist_files.items():
            if ".egg-info/" in name or name.endswith(("/PKG-INFO", "PKG-INFO")):
                continue
            if name == "setup.cfg" and name not in source_files:
                continue
            if source_files.get(name) != content:
                raise RecoveryError(
                    f"PyPI source file {name} differs from source commit {commit}"
                )
            compared += 1
        if not compared:
            raise RecoveryError(
                f"PyPI release {component.package}=={version} has no comparable source files"
            )
    return {"kind": "pypi", "registry": url, "source_files_compared": compared}


def verify_crate(
    client: PublicClient, component: Component, version: str, commit: str
) -> dict[str, Any]:
    package = urllib.parse.quote(component.package, safe="")
    encoded_version = urllib.parse.quote(version, safe="")
    url = f"https://crates.io/api/v1/crates/{package}/{encoded_version}"
    try:
        payload = client.json(url)
    except NotFound as error:
        raise NotFound(
            f"crates.io does not expose {component.package}@{version}",
            "registry-publication",
        ) from error
    published = payload.get("version", {})
    if published.get("num") != version or published.get("yanked"):
        raise RecoveryError(
            f"crates.io release {component.package}@{version} is not active"
        )
    checksum = published.get("checksum")
    with tempfile.TemporaryDirectory(prefix="release-recovery-crate-") as temporary:
        archive_path = Path(temporary) / f"{component.package}-{version}.crate"
        client.download(
            f"https://crates.io/api/v1/crates/{package}/{encoded_version}/download",
            archive_path,
            expected_sha256=checksum,
        )
        with tarfile.open(archive_path, "r:gz") as archive:
            members = [
                member
                for member in archive.getmembers()
                if member.name.endswith("/.cargo_vcs_info.json")
            ]
            if (
                len(members) != 1
                or (extracted := archive.extractfile(members[0])) is None
            ):
                raise RecoveryError("published crate has no unique source identity")
            vcs = json.load(extracted)
    if vcs.get("git", {}).get("sha1") != commit or vcs.get("git", {}).get(
        "dirty", False
    ):
        raise RecoveryError(
            f"crates.io archive for {component.package}@{version} names a different source commit"
        )
    return {
        "kind": "crates.io",
        "registry": url,
        "checksum": checksum,
        "source_commit": commit,
    }


def parse_checksums(raw: bytes) -> dict[str, str]:
    checksums: dict[str, str] = {}
    try:
        lines = raw.decode("utf-8").splitlines()
    except UnicodeDecodeError as error:
        raise RecoveryError(
            "CLI SHA256SUMS is not valid UTF-8", "registry-publication"
        ) from error
    for line in lines:
        match = re.fullmatch(r"([0-9a-fA-F]{64})\s+[*]?([^/\s]+)", line.strip())
        if match:
            checksums[match.group(2)] = match.group(1).lower()
    return checksums


def verify_cli(
    client: PublicClient, component: Component, version: str, commit: str
) -> dict[str, Any]:
    encoded = urllib.parse.quote(version, safe="")
    try:
        release = client.json(
            f"https://api.github.com/repos/{component.repository}/releases/tags/{encoded}"
        )
    except NotFound as error:
        raise NotFound(
            f"CLI GitHub Release {version} is absent", "registry-publication"
        ) from error
    assets = {asset.get("name"): asset for asset in release.get("assets", [])}
    missing = CLI_ASSETS - set(assets)
    if release.get("draft") or release.get("tag_name") != version or missing:
        raise RecoveryError(
            f"CLI GitHub Release {version} is incomplete; missing assets: {sorted(missing)}"
        )

    checksum_asset = assets["SHA256SUMS"]
    checksum_raw = client.bytes(checksum_asset["browser_download_url"])
    checksums = parse_checksums(checksum_raw)
    downloadable = sorted(CLI_ASSETS - {"SHA256SUMS"})
    missing_checksums = set(downloadable) - set(checksums)
    if missing_checksums:
        raise RecoveryError(
            f"CLI SHA256SUMS does not cover every public release asset; missing: {sorted(missing_checksums)}",
            "registry-publication",
        )

    verified_assets: list[dict[str, Any]] = []
    signer_workflow = f"{component.repository}/.github/workflows/release.yml"
    attestation_modes = [
        (
            "exact-tag",
            ["--source-ref", f"refs/tags/{version}", "--source-digest", commit],
            {"mode": "exact-tag", "ref": f"refs/tags/{version}", "commit": commit},
        ),
        (
            "qualified-main-workflow",
            ["--source-ref", "refs/heads/main", "--signer-workflow", signer_workflow],
            {
                "mode": "qualified-main-workflow",
                "ref": "refs/heads/main",
                "workflow": signer_workflow,
            },
        ),
    ]
    selected_attestation_mode: tuple[str, list[str], dict[str, str]] | None = None
    with tempfile.TemporaryDirectory(prefix="release-recovery-cli-") as temporary:
        directory = Path(temporary)
        downloaded_paths: list[Path] = []
        for name in downloadable:
            asset = assets[name]
            asset_path = directory / name
            result = client.download(
                asset["browser_download_url"],
                asset_path,
                expected_sha256=checksums[name],
            )
            result.update({"name": name, "asset_id": asset.get("id")})
            verified_assets.append(result)
            downloaded_paths.append(asset_path)

        checksum_path = directory / "SHA256SUMS"
        checksum_path.write_bytes(checksum_raw)
        verified_assets.append(
            {
                "name": "SHA256SUMS",
                "asset_id": checksum_asset.get("id"),
                "url": checksum_asset["browser_download_url"],
                "size": len(checksum_raw),
                "sha256": hashlib.sha256(checksum_raw).hexdigest(),
            }
        )
        downloaded_paths.append(checksum_path)

        if shutil.which("gh") is None:
            raise RecoveryError(
                "GitHub CLI is required to verify CLI release attestations",
                "registry-publication",
            )
        for asset_path in downloaded_paths:
            base_arguments = [
                "gh",
                "attestation",
                "verify",
                str(asset_path),
                "--repo",
                component.repository,
            ]
            candidates = (
                attestation_modes
                if selected_attestation_mode is None
                else [selected_attestation_mode]
            )
            failures: list[str] = []
            for mode in candidates:
                process = subprocess.run(
                    [*base_arguments, *mode[1]],
                    check=False,
                    text=True,
                    capture_output=True,
                )
                if process.returncode == 0:
                    selected_attestation_mode = mode
                    break
                failures.append(f"{mode[0]}: {process.stderr.strip()}")
            else:
                raise RecoveryError(
                    f"CLI build attestation failed for {asset_path.name}: {'; '.join(failures)}",
                    "registry-publication",
                )

        assert selected_attestation_mode is not None
        if shutil.which("php") is None:
            raise RecoveryError(
                "PHP is required to verify CLI release source metadata",
                "registry-publication",
            )
        phar_version = subprocess.run(
            ["php", str(directory / "dw.phar"), "--version"],
            check=False,
            text=True,
            capture_output=True,
            env={"PATH": os.environ.get("PATH", os.defpath)},
        )
        expected_identity = f"{version} (commit {commit[:12]},"
        if phar_version.returncode or expected_identity not in phar_version.stdout:
            raise RecoveryError(
                f"CLI PHAR for {version} does not embed planned source commit {commit}",
                "registry-publication",
            )

    return {
        "kind": "github-release",
        "id": release.get("id"),
        "url": release.get("html_url"),
        "build_attestations_verified": True,
        "build_attestation_authority": selected_attestation_mode[2],
        "package_source": {
            "commit": commit,
            "embedded_phar_identity": phar_version.stdout.strip(),
        },
        "assets": verified_assets,
    }


VERIFIERS = {
    "composer": verify_composer,
    "oci": verify_oci,
    "pypi": verify_pypi,
    "crates.io": verify_crate,
    "github-release": verify_cli,
}


def verify_component(
    client: PublicClient, name: str, identity: dict[str, str]
) -> dict[str, Any]:
    component = COMPONENTS[name]
    require_source_tag(client, name, identity)
    distribution = VERIFIERS[component.distribution](
        client, component, identity["version"], identity["commit"]
    )
    github_release = (
        distribution
        if component.distribution == "github-release"
        else verify_github_release(client, name, identity["version"])
    )
    return {
        "version": identity["version"],
        "commit": identity["commit"],
        "distribution": distribution,
        "github_release": github_release,
    }


def verify_distribution(
    client: PublicClient, name: str, identity: dict[str, str]
) -> dict[str, Any]:
    component = COMPONENTS[name]
    require_source_tag(client, name, identity)
    distribution = VERIFIERS[component.distribution](
        client, component, identity["version"], identity["commit"]
    )
    return {
        "version": identity["version"],
        "commit": identity["commit"],
        "distribution": distribution,
    }


def write_output(path: Path | None, values: dict[str, str]) -> None:
    if path is None:
        return
    with path.open("a", encoding="utf-8") as output:
        for key, value in values.items():
            output.write(f"{key}={value}\n")


def base_state(
    component: str, tag: str | None = None, plan: dict[str, Any] | None = None
) -> dict[str, Any]:
    return {
        "schema": STATE_SCHEMA,
        "component": component,
        "release_plan_tag": tag,
        "plan": plan.get("plan") if plan else None,
        "channel": plan.get("channel") if plan else None,
        "observed_at": dt.datetime.now(dt.UTC)
        .replace(microsecond=0)
        .isoformat()
        .replace("+00:00", "Z"),
    }


def scheduled_continuity_pause(
    client: PublicClient, plan: dict[str, Any]
) -> dict[str, str] | None:
    accepted_tag = f"{CONTINUITY_TAG_PREFIX}{plan['plan']}/accepted"
    accepted_commit = resolve_tag(client, CONTROL_REPOSITORY, accepted_tag)
    if accepted_commit is None:
        return None
    accepted_plan = read_record(
        client, accepted_tag, accepted_commit, "release-plan.json"
    )
    validate_plan(accepted_plan)
    if canonical_json(accepted_plan) != canonical_json(plan):
        raise RecoveryError(
            "continuity acceptance record names a different release plan",
            "continuity-gate",
        )
    resumed_tag = f"{CONTINUITY_TAG_PREFIX}{plan['plan']}/resumed"
    resumed_commit = resolve_tag(client, CONTROL_REPOSITORY, resumed_tag)
    if resumed_commit is not None:
        resumed_plan = read_record(
            client, resumed_tag, resumed_commit, "release-plan.json"
        )
        validate_plan(resumed_plan)
        if canonical_json(resumed_plan) != canonical_json(plan):
            raise RecoveryError(
                "continuity resume record names a different release plan",
                "continuity-gate",
            )
        return None
    return {
        "accepted_tag": accepted_tag,
        "accepted_commit": accepted_commit,
        "resumed_tag": resumed_tag,
    }


def source_product_train_evidence(
    client: PublicClient,
    component_name: str,
    identity: dict[str, str],
) -> dict[str, str]:
    specification = SOURCE_PRODUCT_TRAINS.get(component_name)
    if specification is None:
        raise RecoveryError(
            f"{component_name} has no source-bound product-train authority"
        )
    package, path = specification
    raw = client.bytes(
        f"https://api.github.com/repos/{COMPONENTS[component_name].repository}/contents/"
        f"{path}?ref={identity['commit']}",
        accept="application/vnd.github.raw+json",
    )
    try:
        manifest = json.loads(raw)
    except (UnicodeDecodeError, json.JSONDecodeError) as error:
        raise RecoveryError(
            f"{component_name} source product-train authority is not valid UTF-8 JSON",
            "source-identity",
        ) from error
    if not isinstance(manifest, dict):
        raise RecoveryError(
            f"{component_name} source product-train authority is not a JSON object",
            "source-identity",
        )
    extra = manifest.get("extra")
    durable_metadata = (
        extra.get("durable-workflow", {}) if isinstance(extra, dict) else {}
    )
    declared_train = (
        durable_metadata.get("product-train")
        if isinstance(durable_metadata, dict)
        else None
    )
    if manifest.get("name") != package or declared_train != identity["version"]:
        raise RecoveryError(
            f"{component_name} source declares product train {declared_train or '<missing>'}, "
            f"not planned version {identity['version']}",
            "source-identity",
        )
    return {
        "package": package,
        "path": path,
        "product_train": declared_train,
        "source_commit": identity["commit"],
        "sha256": hashlib.sha256(raw).hexdigest(),
    }


def resolve_component(
    client: PublicClient,
    component_name: str,
    tag: str,
    record_commit: str,
    plan: dict[str, Any],
    preparation: dict[str, Any] | None,
) -> tuple[dict[str, Any], dict[str, str]]:
    if component_name not in COMPONENTS:
        raise RecoveryError(f"unknown release component: {component_name}")
    branches, recovery_workflows = verify_plan_authority(client, plan)
    component = COMPONENTS[component_name]
    identity = plan["components"][component_name]
    prepared_identity = None
    if preparation is not None:
        validate_release_preparation(preparation, plan)
        prepared_identity = preparation["components"][component_name]
    upstream: dict[str, Any] = {}
    for dependency in component.dependencies:
        try:
            upstream[dependency] = verify_component(
                client, dependency, plan["components"][dependency]
            )
        except NotFound as error:
            raise RecoveryError(
                f"{component_name} is waiting for upstream {dependency}: {error}",
                "upstream-publication",
            ) from error

    existing_tag = resolve_tag(client, component.repository, identity["version"])
    if existing_tag is not None and existing_tag != identity["commit"]:
        raise RecoveryError(
            f"existing version tag {component.repository}@{identity['version']} "
            f"points to {existing_tag}, not {identity['commit']}",
            "tag-preflight",
        )
    completed: dict[str, Any] | None = None
    if existing_tag is not None:
        with contextlib.suppress(NotFound):
            completed = verify_component(client, component_name, identity)
    if completed is not None:
        action = "skip"
    else:
        if preparation is None:
            raise RecoveryError(
                f"release plan {tag} lacks release preparation required before publishing {component_name}",
                "plan-discovery",
            )
        if existing_tag is None:
            with contextlib.suppress(NotFound):
                VERIFIERS[component.distribution](
                    client,
                    component,
                    identity["version"],
                    identity["commit"],
                )
        action = "publish"
    source_train = None
    if (
        action == "publish"
        and plan["channel"] == "beta"
        and component_name in SOURCE_PRODUCT_TRAINS
    ):
        source_train = source_product_train_evidence(client, component_name, identity)
    state = base_state(component_name, tag, plan)
    state.update(
        {
            "phase": "complete" if action == "skip" else "publication",
            "outcome": "verified" if action == "skip" else "ready",
            "plan_record_commit": record_commit,
            "default_branches": branches,
            "recovery_workflows": recovery_workflows,
            "upstream": upstream,
            "source_tag": {
                "status": "present" if existing_tag else "absent",
                "commit": existing_tag,
            },
            "declared_identity": identity,
            "public_evidence": completed,
            "resume_action": (
                "No action is required; repeated recovery verifies and skips this component"
                if action == "skip"
                else f"Run {component.repository} Actions workflow Release plan recovery for {tag}"
            ),
        }
    )
    authority_evidence = next(iter(recovery_workflows.values()), {}).get("authority")
    if authority_evidence is not None:
        state["recovery_workflow_authority"] = authority_evidence
    if prepared_identity is not None:
        state["release_preparation"] = {
            "record_commit": record_commit,
            "record_sha256": manifest_digest(preparation),
            "release_date": prepared_identity["release_notes"]["release_date"],
            "release_notes_sha256": prepared_identity["release_notes"]["sha256"],
            "source": prepared_identity["release_notes"]["source"],
        }
    if source_train is not None:
        state["source_product_train"] = source_train
    outputs = {
        "action": action,
        "plan": str(plan["plan"]),
        "channel": str(plan["channel"]),
        "plan_tag": tag,
        "plan_record_commit": record_commit,
        "version": str(identity["version"]),
        "commit": str(identity["commit"]),
        "default_branch": component.default_branch,
        "release_workflow": component.release_workflow or "",
        "release_tag_input": component.release_tag_input or "",
    }
    if prepared_identity is not None:
        outputs.update(
            {
                "release_date": str(prepared_identity["release_notes"]["release_date"]),
                "release_notes_sha256": str(
                    prepared_identity["release_notes"]["sha256"]
                ),
            }
        )
    return state, outputs


def verify_with_retry(
    client: PublicClient,
    component_name: str,
    plan: dict[str, Any],
    attempts: int,
    sleep_seconds: int,
    registry_only: bool = False,
) -> dict[str, Any]:
    last_error: RecoveryError | None = None
    for attempt in range(1, attempts + 1):
        try:
            verifier = verify_distribution if registry_only else verify_component
            return verifier(client, component_name, plan["components"][component_name])
        except NotFound as error:
            last_error = error
            if attempt < attempts:
                print(
                    f"waiting for public artifact ({attempt}/{attempts}): {error}",
                    file=sys.stderr,
                )
                time.sleep(sleep_seconds)
    assert last_error is not None
    raise last_error


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    subparsers = parser.add_subparsers(dest="command", required=True)
    resolve = subparsers.add_parser("resolve")
    resolve.add_argument("--component", required=True, choices=sorted(COMPONENTS))
    resolve.add_argument("--plan-tag")
    resolve.add_argument("--plan-output", required=True, type=Path)
    resolve.add_argument("--preparation-output", required=True, type=Path)
    resolve.add_argument("--evidence", required=True, type=Path)
    resolve.add_argument("--github-output", type=Path)
    resolve.add_argument("--allow-empty", action="store_true")

    verify = subparsers.add_parser("verify")
    verify.add_argument("--component", required=True, choices=sorted(COMPONENTS))
    verify.add_argument("--plan", required=True, type=Path)
    verify.add_argument("--attempts", type=int, default=1)
    verify.add_argument("--sleep", type=int, default=0)
    verify.add_argument("--registry-only", action="store_true")
    verify.add_argument("--evidence", required=True, type=Path)

    select_run = subparsers.add_parser("select-publication-run")
    select_run.add_argument("--release-tag", required=True)
    select_run.add_argument("--release-commit", required=True)
    select_run.add_argument("--runs", required=True, type=Path)

    args = parser.parse_args()
    token = os.environ.get("GITHUB_TOKEN") or os.environ.get("GH_TOKEN")
    client = PublicClient(token)
    try:
        if args.command == "select-publication-run":
            try:
                runs = json.loads(args.runs.read_bytes())
            except (OSError, json.JSONDecodeError) as error:
                raise RecoveryError(
                    f"cannot read publication run metadata: {error}", "publication"
                ) from error
            selection = select_publication_run(
                args.release_tag, args.release_commit, runs
            )
            print(
                "\t".join(
                    str(selection.get(field) or "")
                    for field in ("action", "run_id", "status", "conclusion")
                )
            )
        elif args.command == "resolve":
            tag: str | None = args.plan_tag
            record_commit: str | None = None
            plan: dict[str, Any] | None = None
            try:
                tag, record_commit, plan, preparation = discover_plan(
                    client, args.plan_tag, args.component
                )
                args.plan_output.write_bytes(canonical_json(plan))
                if preparation is not None:
                    args.preparation_output.write_bytes(canonical_json(preparation))
                continuity_pause = (
                    scheduled_continuity_pause(client, plan)
                    if args.plan_tag is None
                    else None
                )
                if continuity_pause is not None:
                    paused = base_state(args.component, tag, plan)
                    paused.update(
                        {
                            "phase": "continuity-gate",
                            "outcome": "paused",
                            "plan_record_commit": record_commit,
                            "continuity": continuity_pause,
                            "resume_action": (
                                f"Wait for {continuity_pause['resumed_tag']} or explicitly recover exact plan {tag}"
                            ),
                        }
                    )
                    args.evidence.write_bytes(canonical_json(paused))
                    write_output(
                        args.github_output,
                        {
                            "action": "none",
                            "plan": str(plan["plan"]),
                            "channel": str(plan["channel"]),
                            "plan_tag": tag,
                            "plan_record_commit": record_commit,
                        },
                    )
                    return 0
                state, outputs = resolve_component(
                    client,
                    args.component,
                    tag,
                    record_commit,
                    plan,
                    preparation,
                )
                args.evidence.write_bytes(canonical_json(state))
                write_output(args.github_output, outputs)
            except RecoveryError as error:
                if (
                    args.allow_empty
                    and error.phase == "plan-discovery"
                    and str(error) == "no public release plan is available"
                ):
                    idle = base_state(args.component)
                    idle.update(
                        {
                            "phase": "plan-discovery",
                            "outcome": "idle",
                            "reason": str(error),
                            "resume_action": "No action is required; scheduled discovery will check again",
                        }
                    )
                    args.evidence.write_bytes(canonical_json(idle))
                    write_output(args.github_output, {"action": "none"})
                    return 0
                failure = base_state(args.component, tag, plan)
                if record_commit is not None:
                    failure["plan_record_commit"] = record_commit
                failure.update(
                    {
                        "phase": error.phase,
                        "outcome": "failed",
                        "reason": str(error),
                        "durable_evidence": {
                            "release_plan": tag,
                            "source_tag": f"https://github.com/{COMPONENTS[args.component].repository}/releases",
                            "actions": f"https://github.com/{COMPONENTS[args.component].repository}/actions",
                        },
                        "resume_action": (
                            f"Run {COMPONENTS[args.component].repository} Actions workflow "
                            f"Release plan recovery{f' for {tag}' if tag else ''}"
                        ),
                    }
                )
                args.evidence.write_bytes(canonical_json(failure))
                raise
        else:
            if args.attempts < 1 or args.sleep < 0:
                raise RecoveryError(
                    "retry attempts must be positive and sleep must be non-negative"
                )
            try:
                plan = json.loads(args.plan.read_bytes())
            except (OSError, json.JSONDecodeError) as error:
                raise RecoveryError(
                    f"cannot read canonical release plan: {error}"
                ) from error
            validate_plan(plan)
            try:
                public = verify_with_retry(
                    client,
                    args.component,
                    plan,
                    args.attempts,
                    args.sleep,
                    registry_only=args.registry_only,
                )
                state = base_state(
                    args.component, f"{PLAN_TAG_PREFIX}{plan['plan']}", plan
                )
                state.update(
                    {
                        "phase": "complete",
                        "outcome": "verified",
                        "public_evidence": public,
                        "resume_action": "No action is required",
                    }
                )
                args.evidence.write_bytes(canonical_json(state))
            except RecoveryError as error:
                state = base_state(
                    args.component, f"{PLAN_TAG_PREFIX}{plan['plan']}", plan
                )
                state.update(
                    {
                        "phase": error.phase,
                        "outcome": "failed",
                        "reason": str(error),
                        "resume_action": (
                            f"Run {COMPONENTS[args.component].repository} Actions workflow Release plan recovery "
                            f"for {PLAN_TAG_PREFIX}{plan['plan']}"
                        ),
                    }
                )
                args.evidence.write_bytes(canonical_json(state))
                raise
    except PublicInfrastructureError as error:
        print(f"release recovery infrastructure failed: {error}", file=sys.stderr)
        return INFRASTRUCTURE_EXIT_CODE
    except RecoveryError as error:
        print(f"release recovery error: {error}", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
