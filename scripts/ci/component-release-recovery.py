#!/usr/bin/env python3
"""Discover and classify one repository's work for an immutable release plan."""

from __future__ import annotations

import argparse
import contextlib
import datetime as dt
import hashlib
import hmac
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
from dataclasses import dataclass
from pathlib import Path
from typing import Any

SCHEMA = "durable-workflow.release-plan/v1"
STATE_SCHEMA = "durable-workflow.component-release-recovery/v1"
CONTROL_REPOSITORY = "durable-workflow/.github"
PLAN_TAG_PREFIX = "release-plan/"
FOUNDATION_TAG = "beta-candidate/beta-continuity-foundation"
FOUNDATION_COMMIT = "4995052410bd4301c5796ffba54e0b6d2f490ed1"
COMMIT_PATTERN = re.compile(r"^[0-9a-f]{40}$")
PLAN_PATTERN = re.compile(r"^[a-z0-9][a-z0-9._-]{0,55}$")
VERSION_PATTERN = re.compile(r"^[0-9]+\.[0-9]+\.[0-9]+(?:[-+][0-9A-Za-z][0-9A-Za-z.-]*)?$")
ALPHA_VERSION_PATTERN = re.compile(r"^2\.0\.0-alpha\.[1-9][0-9]*$")
BETA_VERSION_PATTERN = re.compile(r"^2\.0\.0-beta\.[1-9][0-9]*$")

# SHA-256 of durable-workflow/sdk-rust's release recovery workflow at main
# commit 31e87f4aa13a7fd255fd277a62c43c96ee1532ab. The verifier normalizes only
# CRLF line endings to LF before hashing. Exact source identity is the bounded
# security contract because arbitrary shell execution cannot be proven safe by
# source-pattern matching.
SDK_RUST_RELEASE_RECOVERY_SHA256 = "d04219e3ffcc1c12a7a223efc832abee56c371b9dc60a7ef5a44a156b2ab4f64"


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
    "workflow": Component("durable-workflow/workflow", "v2", "composer", "durable-workflow/workflow", (), None, None),
    "sdk-php": Component("durable-workflow/sdk-php", "main", "composer", "durable-workflow/sdk", (), None, None),
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
        "durable-workflow/cli", "main", "github-release", "durable-workflow/cli", ("server",), "release.yml", "tag"
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


def canonical_json(value: Any) -> bytes:
    return (json.dumps(value, indent=2, sort_keys=True, ensure_ascii=True) + "\n").encode()


class PublicClient:
    def __init__(self, token: str | None = None) -> None:
        self.token = token

    def request(
        self,
        url: str,
        *,
        headers: dict[str, str] | None = None,
        accept: str | None = None,
    ) -> urllib.response.addinfourl:
        request_headers = {"User-Agent": "durable-workflow-release-recovery/1", **(headers or {})}
        if accept:
            request_headers["Accept"] = accept
        if self.token and urllib.parse.urlsplit(url).hostname == "api.github.com":
            request_headers["Authorization"] = f"Bearer {self.token}"
            request_headers["X-GitHub-Api-Version"] = "2022-11-28"
        request = urllib.request.Request(url, headers=request_headers)
        try:
            return urllib.request.urlopen(request, timeout=60)
        except urllib.error.HTTPError as error:
            if error.code == 404:
                raise NotFound(f"public resource is absent: {url}") from error
            detail = error.read(1024).decode(errors="replace")
            raise RecoveryError(f"public request failed ({error.code}) for {url}: {detail}") from error
        except urllib.error.URLError as error:
            raise RecoveryError(f"public request failed for {url}: {error.reason}") from error

    def json(self, url: str, *, headers: dict[str, str] | None = None, accept: str | None = None) -> Any:
        with self.request(url, headers=headers, accept=accept) as response:
            try:
                return json.load(response)
            except (json.JSONDecodeError, UnicodeDecodeError) as error:
                raise RecoveryError(f"public endpoint did not return valid JSON: {url}") from error

    def bytes(self, url: str, *, headers: dict[str, str] | None = None, accept: str | None = None) -> bytes:
        with self.request(url, headers=headers, accept=accept) as response:
            return response.read()

    def download(self, url: str, path: Path, *, expected_sha256: str | None = None) -> dict[str, Any]:
        digest = hashlib.sha256()
        size = 0
        with self.request(url) as response, path.open("wb") as destination:
            while chunk := response.read(1024 * 1024):
                digest.update(chunk)
                destination.write(chunk)
                size += len(chunk)
        actual = digest.hexdigest()
        if expected_sha256 and actual != expected_sha256.lower():
            raise RecoveryError(f"download digest mismatch for {url}: expected {expected_sha256}, got {actual}")
        return {"url": url, "size": size, "sha256": actual}


def validate_plan(plan: Any) -> None:
    if not isinstance(plan, dict):
        raise RecoveryError("release plan must be a JSON object")
    expected = {"schema", "plan", "channel", "foundation", "components", "beta_authorization"}
    if set(plan) != expected or plan.get("schema") != SCHEMA:
        raise RecoveryError("release plan does not satisfy the channel-aware v1 contract")
    if not isinstance(plan["plan"], str) or not PLAN_PATTERN.fullmatch(plan["plan"]):
        raise RecoveryError("release plan has an invalid identity")
    if plan["channel"] not in {"alpha", "beta"}:
        raise RecoveryError("release plan channel must be alpha or beta")
    if plan["foundation"] != {"tag": FOUNDATION_TAG, "commit": FOUNDATION_COMMIT}:
        raise RecoveryError("release plan does not name the proven immutable candidate foundation")
    components = plan["components"]
    if not isinstance(components, dict) or set(components) != set(COMPONENTS):
        raise RecoveryError("release plan must contain the exact seven-component tuple")
    for name, identity in components.items():
        if not isinstance(identity, dict) or set(identity) != {"version", "commit"}:
            raise RecoveryError(f"components.{name} must contain only version and commit")
        if not isinstance(identity["version"], str) or not VERSION_PATTERN.fullmatch(identity["version"]):
            raise RecoveryError(f"components.{name}.version is not exact SemVer")
        if not isinstance(identity["commit"], str) or not COMMIT_PATTERN.fullmatch(identity["commit"]):
            raise RecoveryError(f"components.{name}.commit is not a full source identity")
    channel_pattern = ALPHA_VERSION_PATTERN if plan["channel"] == "alpha" else BETA_VERSION_PATTERN
    for name in ("workflow", "waterline"):
        if not channel_pattern.fullmatch(components[name]["version"]):
            raise RecoveryError(f"{name} does not have an exact 2.0.0-{plan['channel']}.N identity")
    authorization = plan["beta_authorization"]
    if plan["channel"] == "alpha" and authorization is not None:
        raise RecoveryError("alpha plans cannot claim beta authorization")
    if plan["channel"] == "beta" and (
        not isinstance(authorization, dict)
        or set(authorization) != {"tag", "commit"}
        or not re.fullmatch(r"beta-authorization/[a-z0-9][a-z0-9._-]{0,55}", str(authorization.get("tag", "")))
        or not COMMIT_PATTERN.fullmatch(str(authorization.get("commit", "")))
    ):
        raise RecoveryError("beta plans require an immutable beta authorization")


def resolve_tag(client: PublicClient, repository: str, tag: str) -> str | None:
    encoded = urllib.parse.quote(tag, safe="")
    try:
        ref = client.json(f"https://api.github.com/repos/{repository}/git/ref/tags/{encoded}")
    except NotFound:
        return None
    target = ref.get("object", {})
    seen: set[str] = set()
    while target.get("type") == "tag":
        sha = target.get("sha")
        if not isinstance(sha, str) or sha in seen:
            raise RecoveryError(f"invalid annotated tag chain for {repository}@{tag}", "tag-preflight")
        seen.add(sha)
        target = client.json(f"https://api.github.com/repos/{repository}/git/tags/{sha}").get("object", {})
    if target.get("type") != "commit" or not COMMIT_PATTERN.fullmatch(str(target.get("sha", ""))):
        raise RecoveryError(f"tag {repository}@{tag} does not resolve to a commit", "tag-preflight")
    return str(target["sha"])


def read_record(client: PublicClient, tag: str, commit: str, filename: str) -> Any:
    if resolve_tag(client, CONTROL_REPOSITORY, tag) != commit:
        raise RecoveryError(f"immutable record {tag} does not resolve to {commit}", "plan-discovery")
    encoded_filename = urllib.parse.quote(filename, safe="/")
    raw = client.bytes(
        f"https://api.github.com/repos/{CONTROL_REPOSITORY}/contents/{encoded_filename}?ref={commit}",
        accept="application/vnd.github.raw+json",
    )
    try:
        return json.loads(raw)
    except json.JSONDecodeError as error:
        raise RecoveryError(f"immutable record {tag}:{filename} is not valid JSON", "plan-discovery") from error


def discover_plan(client: PublicClient, requested_tag: str | None) -> tuple[str, str, dict[str, Any]]:
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
    else:
        releases = client.json(f"https://api.github.com/repos/{CONTROL_REPOSITORY}/releases?per_page=100")
        release = next(
            (
                item
                for item in releases
                if not item.get("draft") and str(item.get("tag_name", "")).startswith(PLAN_TAG_PREFIX)
            ),
            None,
        )
        if release is None:
            raise RecoveryError("no public release plan is available", "plan-discovery")
        tag = str(release["tag_name"])
    if release.get("draft"):
        raise RecoveryError(f"release plan {tag} is still a draft", "plan-discovery")
    commit = resolve_tag(client, CONTROL_REPOSITORY, tag)
    if commit is None:
        raise RecoveryError(f"release plan tag {tag} is absent", "plan-discovery")
    plan = read_record(client, tag, commit, "release-plan.json")
    validate_plan(plan)
    if tag != f"{PLAN_TAG_PREFIX}{plan['plan']}":
        raise RecoveryError("release plan tag and document identity differ", "plan-discovery")
    assets = {asset.get("name"): asset for asset in release.get("assets", [])}
    if "release-plan.json" not in assets:
        raise RecoveryError(f"release plan {tag} lacks its durable mirror asset", "plan-discovery")
    mirror = client.bytes(assets["release-plan.json"]["browser_download_url"])
    if mirror != canonical_json(plan):
        raise RecoveryError(f"release plan {tag} mirror differs from immutable Git authority", "plan-discovery")
    return tag, commit, plan


def verify_recovery_workflow_source(name: str, source: str) -> None:
    component = COMPONENTS[name]
    if name == "sdk-rust":
        normalized_source = source.replace("\r\n", "\n").encode("utf-8")
        actual_digest = hashlib.sha256(normalized_source).hexdigest()
        if not hmac.compare_digest(actual_digest, SDK_RUST_RELEASE_RECOVERY_SHA256):
            raise RecoveryError(
                f"{component.repository} release recovery workflow does not match the approved "
                "protected publication source identity",
                "default-branch-preflight",
            )
        return

    if not re.search(r"(?m)^  schedule:\s*$", source) or not re.search(
        r"(?m)^  workflow_dispatch:\s*$", source
    ):
        raise RecoveryError(
            f"{component.repository} recovery workflow lacks scheduled or manual discovery",
            "default-branch-preflight",
        )
    if component.release_workflow is None:
        return

    dispatch = re.search(
        rf'gh\s+workflow\s+run\s+{re.escape(component.release_workflow)}\s+--ref\s+"\$RELEASE_TAG"',
        source,
    )
    tag_ref_at = source.find('-f ref="refs/tags/$RELEASE_TAG"')
    tag_commit_at = source.find('-f sha="$RELEASE_COMMIT"', tag_ref_at)
    selector_at = source.find("select-publication-run")
    if (
        dispatch is None
        or tag_ref_at < 0
        or tag_commit_at < 0
        or selector_at < tag_commit_at
        or selector_at > dispatch.start()
        or "databaseId,displayTitle,headBranch,headSha,status,conclusion" not in source
        or '--release-tag "$RELEASE_TAG"' not in source
        or '--release-commit "$RELEASE_COMMIT"' not in source
    ):
        raise RecoveryError(
            f"{component.repository} publication must create or verify the declared source tag "
            "before dispatching in its exact tag context",
            "default-branch-preflight",
        )
    release_input = f'-f {component.release_tag_input}="$RELEASE_TAG"'
    if source.find(release_input, dispatch.start()) < 0:
        raise RecoveryError(
            f"{component.repository} publication must retain the declared release tag input",
            "default-branch-preflight",
        )


def select_publication_run(
    release_tag: str,
    release_commit: str,
    runs: Any,
) -> dict[str, Any]:
    if not VERSION_PATTERN.fullmatch(release_tag) or not COMMIT_PATTERN.fullmatch(release_commit):
        raise RecoveryError("publication run selection requires an exact release identity", "publication")
    if not isinstance(runs, list):
        raise RecoveryError("publication run metadata must be a JSON array", "publication")

    exact_runs: list[dict[str, Any]] = []
    for run in runs:
        if not isinstance(run, dict) or run.get("headBranch") != release_tag:
            continue
        if run.get("headSha") != release_commit:
            raise RecoveryError(
                f"publication run {run.get('databaseId')} for {release_tag} is bound to a different source commit",
                "publication",
            )
        if not isinstance(run.get("databaseId"), int) or not isinstance(run.get("status"), str):
            raise RecoveryError("publication run metadata is incomplete", "publication")
        exact_runs.append(run)

    selected = next((run for run in exact_runs if run["status"] != "completed"), None)
    action = "wait"
    if selected is None:
        selected = next(
            (run for run in exact_runs if run["status"] == "completed" and run.get("conclusion") == "success"),
            None,
        )
        action = "complete"
    if selected is None:
        selected = next((run for run in exact_runs if run["status"] == "completed"), None)
        action = "rerun"
    if selected is None:
        return {"action": "dispatch", "run_id": None, "status": None, "conclusion": None}
    return {
        "action": action,
        "run_id": selected["databaseId"],
        "status": selected["status"],
        "conclusion": selected.get("conclusion"),
    }


def verify_plan_authority(
    client: PublicClient, plan: dict[str, Any]
) -> tuple[dict[str, str], dict[str, dict[str, Any]]]:
    foundation = read_record(client, FOUNDATION_TAG, FOUNDATION_COMMIT, "candidate.json")
    if foundation.get("candidate") != "beta-continuity-foundation":
        raise RecoveryError("immutable candidate foundation has an unexpected identity", "plan-preflight")
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
        expected_path = ".github/workflows/release-plan-recovery.yml"
        workflow = client.json(
            f"https://api.github.com/repos/{component.repository}/actions/workflows/release-plan-recovery.yml"
        )
        if workflow.get("path") != expected_path or workflow.get("state") != "active":
            raise RecoveryError(
                f"{component.repository} does not expose an active {expected_path} on its default branch",
                "default-branch-preflight",
            )
        source = client.bytes(
            f"https://api.github.com/repos/{component.repository}/contents/{expected_path}"
            f"?ref={component.default_branch}",
            accept="application/vnd.github.raw+json",
        ).decode("utf-8")
        verify_recovery_workflow_source(name, source)
        recovery_workflows[name] = {
            "default_branch": component.default_branch,
            "path": expected_path,
            "state": workflow["state"],
            "workflow_id": workflow.get("id"),
            "url": workflow.get("html_url"),
        }
    authorization = plan["beta_authorization"]
    if authorization is not None:
        record = read_record(client, authorization["tag"], authorization["commit"], "beta-authorization.json")
        expected = {
            "schema": "durable-workflow.beta-authorization/v1",
            "channel": "beta",
            "candidate": plan["plan"],
            "components": plan["components"],
        }
        if record != expected:
            raise RecoveryError(
                "beta authorization names a different candidate or component tuple", "channel-authorization"
            )
    return branches, recovery_workflows


def require_source_tag(client: PublicClient, name: str, identity: dict[str, str]) -> str:
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


def verify_github_release(client: PublicClient, name: str, version: str) -> dict[str, Any]:
    component = COMPONENTS[name]
    encoded = urllib.parse.quote(version, safe="")
    try:
        release = client.json(f"https://api.github.com/repos/{component.repository}/releases/tags/{encoded}")
    except NotFound as error:
        raise NotFound(f"GitHub Release {component.repository}@{version} is absent", "github-release") from error
    if release.get("draft") or release.get("tag_name") != version:
        raise RecoveryError(f"GitHub Release {component.repository}@{version} is not public", "github-release")
    return {"id": release.get("id"), "url": release.get("html_url")}


def verify_composer(client: PublicClient, component: Component, version: str, commit: str) -> dict[str, Any]:
    encoded = "/".join(urllib.parse.quote(part, safe="") for part in component.package.split("/"))
    url = f"https://repo.packagist.org/p2/{encoded}.json"
    payload = client.json(url)
    releases = payload.get("packages", {}).get(component.package, [])
    release = next((item for item in releases if str(item.get("version", "")).lstrip("v") == version.lstrip("v")), None)
    if release is None:
        raise NotFound(f"Packagist does not expose {component.package}@{version}", "registry-publication")
    source = release.get("source", {}).get("reference")
    dist = release.get("dist", {}).get("reference")
    if source != commit or dist != commit:
        raise RecoveryError(
            f"Packagist identity for {component.package}@{version} is {source}/{dist}, not {commit}",
            "registry-publication",
        )
    return {"kind": "composer", "registry": url, "source_reference": source, "dist_reference": dist}


def oci_json(client: PublicClient, url: str, token: str, accept: str) -> tuple[Any, str | None]:
    response = client.request(url, headers={"Authorization": f"Bearer {token}"}, accept=accept)
    with response:
        return json.load(response), response.headers.get("Docker-Content-Digest")


def verify_oci(client: PublicClient, component: Component, version: str, commit: str) -> dict[str, Any]:
    repository = component.package.split("/", 1)[1]
    token_url = "https://auth.docker.io/token?service=registry.docker.io&scope=" + urllib.parse.quote(
        f"repository:{repository}:pull"
    )
    token = client.json(token_url).get("token")
    if not token:
        raise RecoveryError(f"Docker Hub did not grant public pull access to {component.package}:{version}")
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
        raise NotFound(f"Docker Hub does not expose {component.package}:{version}", "registry-publication") from error
    if not re.fullmatch(r"sha256:[0-9a-f]{64}", str(digest or "")):
        raise RecoveryError(f"Docker Hub image {component.package}:{version} has no immutable digest")
    descriptors = manifest.get("manifests")
    if not isinstance(descriptors, list):
        raise RecoveryError(f"Docker Hub image {component.package}:{version} is not multi-platform")
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
            raise RecoveryError(f"Docker Hub platform digest changed for {component.package}:{version}")
        config_digest = child.get("config", {}).get("digest")
        config = client.json(
            f"https://registry-1.docker.io/v2/{repository}/blobs/{config_digest}",
            headers={"Authorization": f"Bearer {token}"},
        )
        labels = config.get("config", {}).get("Labels") or {}
        if labels.get("org.opencontainers.image.revision") != commit:
            raise RecoveryError(f"Docker Hub image {component.package}:{version} names a different source commit")
        if labels.get("dev.durable-workflow.release.tag") != version:
            raise RecoveryError(f"Docker Hub image {component.package}:{version} names a different release tag")
        platforms.add(label)
    if platforms != {"linux/amd64", "linux/arm64"}:
        raise RecoveryError(f"Docker Hub image {component.package}:{version} lacks required Linux platforms")
    return {"kind": "oci", "image": f"{component.package}:{version}", "digest": digest, "platforms": sorted(platforms)}


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
            if member.isfile() and (extracted := archive.extractfile(member)) is not None:
                files[member.name] = extracted.read()
    return files


def strip_root(files: dict[str, bytes]) -> dict[str, bytes]:
    return {
        relative: content
        for name, content in files.items()
        if (separator := name.partition("/"))[1] and (relative := separator[2])
    }


def verify_pypi(client: PublicClient, component: Component, version: str, commit: str) -> dict[str, Any]:
    package = urllib.parse.quote(component.package, safe="")
    encoded_version = urllib.parse.quote(version, safe="")
    url = f"https://pypi.org/pypi/{package}/{encoded_version}/json"
    try:
        payload = client.json(url)
    except NotFound as error:
        raise NotFound(f"PyPI does not expose {component.package}=={version}", "registry-publication") from error
    files = [item for item in payload.get("urls", []) if not item.get("yanked")]
    sdist = next((item for item in files if item.get("packagetype") == "sdist"), None)
    wheels = [item for item in files if item.get("packagetype") == "bdist_wheel"]
    if sdist is None or not wheels:
        raise RecoveryError(f"PyPI release {component.package}=={version} lacks a wheel or source archive")
    with tempfile.TemporaryDirectory(prefix="release-recovery-pypi-") as temporary:
        directory = Path(temporary)
        source_path = directory / "source.tar.gz"
        sdist_path = directory / str(sdist["filename"])
        client.download(f"https://github.com/{component.repository}/archive/{commit}.tar.gz", source_path)
        client.download(sdist["url"], sdist_path, expected_sha256=sdist.get("digests", {}).get("sha256"))
        source_files = strip_root(archive_files(source_path))
        sdist_files = strip_root(archive_files(sdist_path))
        compared = 0
        for name, content in sdist_files.items():
            if ".egg-info/" in name or name.endswith(("/PKG-INFO", "PKG-INFO")):
                continue
            if name == "setup.cfg" and name not in source_files:
                continue
            if source_files.get(name) != content:
                raise RecoveryError(f"PyPI source file {name} differs from source commit {commit}")
            compared += 1
        if not compared:
            raise RecoveryError(f"PyPI release {component.package}=={version} has no comparable source files")
    return {"kind": "pypi", "registry": url, "source_files_compared": compared}


def verify_crate(client: PublicClient, component: Component, version: str, commit: str) -> dict[str, Any]:
    package = urllib.parse.quote(component.package, safe="")
    encoded_version = urllib.parse.quote(version, safe="")
    url = f"https://crates.io/api/v1/crates/{package}/{encoded_version}"
    try:
        payload = client.json(url)
    except NotFound as error:
        raise NotFound(f"crates.io does not expose {component.package}@{version}", "registry-publication") from error
    published = payload.get("version", {})
    if published.get("num") != version or published.get("yanked"):
        raise RecoveryError(f"crates.io release {component.package}@{version} is not active")
    checksum = published.get("checksum")
    with tempfile.TemporaryDirectory(prefix="release-recovery-crate-") as temporary:
        archive_path = Path(temporary) / f"{component.package}-{version}.crate"
        client.download(
            f"https://crates.io/api/v1/crates/{package}/{encoded_version}/download",
            archive_path,
            expected_sha256=checksum,
        )
        with tarfile.open(archive_path, "r:gz") as archive:
            members = [member for member in archive.getmembers() if member.name.endswith("/.cargo_vcs_info.json")]
            if len(members) != 1 or (extracted := archive.extractfile(members[0])) is None:
                raise RecoveryError("published crate has no unique source identity")
            vcs = json.load(extracted)
    if vcs.get("git", {}).get("sha1") != commit or vcs.get("git", {}).get("dirty", False):
        raise RecoveryError(f"crates.io archive for {component.package}@{version} names a different source commit")
    return {"kind": "crates.io", "registry": url, "checksum": checksum, "source_commit": commit}


def parse_checksums(raw: bytes) -> dict[str, str]:
    checksums: dict[str, str] = {}
    try:
        lines = raw.decode("utf-8").splitlines()
    except UnicodeDecodeError as error:
        raise RecoveryError("CLI SHA256SUMS is not valid UTF-8", "registry-publication") from error
    for line in lines:
        match = re.fullmatch(r"([0-9a-fA-F]{64})\s+[*]?([^/\s]+)", line.strip())
        if match:
            checksums[match.group(2)] = match.group(1).lower()
    return checksums


def verify_cli(client: PublicClient, component: Component, version: str, commit: str) -> dict[str, Any]:
    encoded = urllib.parse.quote(version, safe="")
    try:
        release = client.json(f"https://api.github.com/repos/{component.repository}/releases/tags/{encoded}")
    except NotFound as error:
        raise NotFound(f"CLI GitHub Release {version} is absent", "registry-publication") from error
    assets = {asset.get("name"): asset for asset in release.get("assets", [])}
    missing = CLI_ASSETS - set(assets)
    if release.get("draft") or release.get("tag_name") != version or missing:
        raise RecoveryError(f"CLI GitHub Release {version} is incomplete; missing assets: {sorted(missing)}")

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
    source_ref = f"refs/tags/{version}"
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
            process = subprocess.run(
                [
                    "gh",
                    "attestation",
                    "verify",
                    str(asset_path),
                    "--repo",
                    component.repository,
                    "--source-digest",
                    commit,
                    "--source-ref",
                    source_ref,
                ],
                check=False,
                text=True,
                capture_output=True,
            )
            if process.returncode:
                raise RecoveryError(
                    f"CLI build attestation failed for {asset_path.name}: {process.stderr.strip()}",
                    "registry-publication",
                )

    return {
        "kind": "github-release",
        "id": release.get("id"),
        "url": release.get("html_url"),
        "build_attestations_verified": True,
        "build_attestation_source": {"commit": commit, "ref": source_ref},
        "assets": verified_assets,
    }


VERIFIERS = {
    "composer": verify_composer,
    "oci": verify_oci,
    "pypi": verify_pypi,
    "crates.io": verify_crate,
    "github-release": verify_cli,
}


def verify_component(client: PublicClient, name: str, identity: dict[str, str]) -> dict[str, Any]:
    component = COMPONENTS[name]
    require_source_tag(client, name, identity)
    distribution = VERIFIERS[component.distribution](client, component, identity["version"], identity["commit"])
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


def verify_distribution(client: PublicClient, name: str, identity: dict[str, str]) -> dict[str, Any]:
    component = COMPONENTS[name]
    require_source_tag(client, name, identity)
    distribution = VERIFIERS[component.distribution](client, component, identity["version"], identity["commit"])
    return {"version": identity["version"], "commit": identity["commit"], "distribution": distribution}


def write_output(path: Path | None, values: dict[str, str]) -> None:
    if path is None:
        return
    with path.open("a", encoding="utf-8") as output:
        for key, value in values.items():
            output.write(f"{key}={value}\n")


def base_state(component: str, tag: str | None = None, plan: dict[str, Any] | None = None) -> dict[str, Any]:
    return {
        "schema": STATE_SCHEMA,
        "component": component,
        "release_plan_tag": tag,
        "plan": plan.get("plan") if plan else None,
        "channel": plan.get("channel") if plan else None,
        "observed_at": dt.datetime.now(dt.UTC).replace(microsecond=0).isoformat().replace("+00:00", "Z"),
    }


def resolve_component(
    client: PublicClient,
    component_name: str,
    tag: str,
    record_commit: str,
    plan: dict[str, Any],
) -> tuple[dict[str, Any], dict[str, str]]:
    if component_name not in COMPONENTS:
        raise RecoveryError(f"unknown release component: {component_name}")
    branches, recovery_workflows = verify_plan_authority(client, plan)
    component = COMPONENTS[component_name]
    identity = plan["components"][component_name]
    upstream: dict[str, Any] = {}
    for dependency in component.dependencies:
        try:
            upstream[dependency] = verify_component(client, dependency, plan["components"][dependency])
        except NotFound as error:
            raise RecoveryError(
                f"{component_name} is waiting for upstream {dependency}: {error}", "upstream-publication"
            ) from error

    existing_tag = resolve_tag(client, component.repository, identity["version"])
    if existing_tag is not None and existing_tag != identity["commit"]:
        raise RecoveryError(
            f"existing version tag {component.repository}@{identity['version']} "
            f"points to {existing_tag}, not {identity['commit']}",
            "tag-preflight",
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
    completed: dict[str, Any] | None = None
    if existing_tag is not None:
        try:
            completed = verify_component(client, component_name, identity)
            action = "skip"
        except NotFound:
            pass
    state = base_state(component_name, tag, plan)
    state.update(
        {
            "phase": "complete" if action == "skip" else "publication",
            "outcome": "verified" if action == "skip" else "ready",
            "plan_record_commit": record_commit,
            "default_branches": branches,
            "recovery_workflows": recovery_workflows,
            "upstream": upstream,
            "source_tag": {"status": "present" if existing_tag else "absent", "commit": existing_tag},
            "declared_identity": identity,
            "public_evidence": completed,
            "resume_action": (
                "No action is required; repeated recovery verifies and skips this component"
                if action == "skip"
                else f"Run {component.repository} Actions workflow Release plan recovery for {tag}"
            ),
        }
    )
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
                print(f"waiting for public artifact ({attempt}/{attempts}): {error}", file=sys.stderr)
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
                raise RecoveryError(f"cannot read publication run metadata: {error}", "publication") from error
            selection = select_publication_run(args.release_tag, args.release_commit, runs)
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
                tag, record_commit, plan = discover_plan(client, args.plan_tag)
                args.plan_output.write_bytes(canonical_json(plan))
                state, outputs = resolve_component(client, args.component, tag, record_commit, plan)
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
                raise RecoveryError("retry attempts must be positive and sleep must be non-negative")
            try:
                plan = json.loads(args.plan.read_bytes())
            except (OSError, json.JSONDecodeError) as error:
                raise RecoveryError(f"cannot read canonical release plan: {error}") from error
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
                state = base_state(args.component, f"{PLAN_TAG_PREFIX}{plan['plan']}", plan)
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
                state = base_state(args.component, f"{PLAN_TAG_PREFIX}{plan['plan']}", plan)
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
    except RecoveryError as error:
        print(f"release recovery error: {error}", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
