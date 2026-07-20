#!/usr/bin/env python3
"""Shared focused contracts for CLI recovery provenance verification."""

from __future__ import annotations

import hashlib
import importlib.util
import sys
import unittest
from pathlib import Path
from unittest import mock

RECOVERY_SCRIPT = Path(__file__).with_name("component-release-recovery.py")
CLI_WORKFLOW_FIXTURE = Path(__file__).with_name("cli-release-plan-recovery.fixture.yml")
CURRENT_CLI_RECOVERY_WORKFLOW = CLI_WORKFLOW_FIXTURE.read_text()


def load_recovery_module():
    spec = importlib.util.spec_from_file_location("component_release_recovery_cli_contract", RECOVERY_SCRIPT)
    assert spec is not None and spec.loader is not None
    module = importlib.util.module_from_spec(spec)
    sys.modules[spec.name] = module
    spec.loader.exec_module(module)
    return module


class CliRecoveryWorkflowSourceTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.recovery = load_recovery_module()

    def test_cli_workflow_fixture_matches_the_pinned_protected_authority(self) -> None:
        digest = hashlib.sha256(CURRENT_CLI_RECOVERY_WORKFLOW.encode("utf-8")).hexdigest()
        self.assertEqual(self.recovery.CLI_RELEASE_RECOVERY_SHA256, digest)
        self.recovery.verify_recovery_workflow_source("cli", CURRENT_CLI_RECOVERY_WORKFLOW)
        self.recovery.verify_recovery_workflow_source(
            "cli",
            CURRENT_CLI_RECOVERY_WORKFLOW.replace("\n", "\r\n"),
        )

    def test_cli_workflow_pin_rejects_any_source_mutation(self) -> None:
        mutated = CURRENT_CLI_RECOVERY_WORKFLOW.replace("timeout-minutes: 45", "timeout-minutes: 44", 1)
        self.assertNotEqual(CURRENT_CLI_RECOVERY_WORKFLOW, mutated)
        with self.assertRaises(self.recovery.RecoveryError) as caught:
            self.recovery.verify_recovery_workflow_source("cli", mutated)
        self.assertEqual("default-branch-preflight", caught.exception.phase)


class CliReleaseAuthorityTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.recovery = load_recovery_module()

    def release_client(self, version: str) -> mock.Mock:
        client = mock.Mock()
        assets = [
            {
                "id": index,
                "name": name,
                "browser_download_url": f"https://example.invalid/{name}",
            }
            for index, name in enumerate(sorted(self.recovery.CLI_ASSETS), start=1)
        ]
        client.json.return_value = {
            "id": 94,
            "html_url": f"https://github.com/durable-workflow/cli/releases/tag/{version}",
            "tag_name": version,
            "draft": False,
            "assets": assets,
        }
        client.bytes.return_value = "".join(
            f"{'a' * 64}  {name}\n" for name in sorted(self.recovery.CLI_ASSETS - {"SHA256SUMS"})
        ).encode()

        def download(_url: str, path: Path, *, expected_sha256: str | None = None) -> dict[str, object]:
            path.write_bytes(b"artifact")
            return {"url": str(path), "size": 8, "sha256": expected_sha256}

        client.download.side_effect = download
        return client

    def test_qualified_main_authority_is_observed_without_a_version_gate(self) -> None:
        version = "0.1.93"
        commit = "3fcc580000000000000000000000000000000000"
        calls: list[list[str]] = []

        def run(arguments: list[str], **_kwargs: object) -> object:
            calls.append(arguments)
            if arguments[0] == "php":
                return mock.Mock(
                    returncode=0,
                    stdout=f"dw {version} (commit {commit[:12]}, built 2026-07-20)",
                    stderr="",
                )
            if "--source-digest" in arguments:
                return mock.Mock(returncode=1, stdout="", stderr="no exact-tag attestation")
            return mock.Mock(returncode=0, stdout="verified", stderr="")

        with (
            mock.patch.object(self.recovery.shutil, "which", return_value="/usr/bin/tool"),
            mock.patch.object(self.recovery.subprocess, "run", side_effect=run),
        ):
            evidence = self.recovery.verify_cli(
                self.release_client(version),
                self.recovery.COMPONENTS["cli"],
                version,
                commit,
            )

        attestations = [arguments for arguments in calls if arguments[:3] == ["gh", "attestation", "verify"]]
        main_attestations = [arguments for arguments in attestations if "--signer-workflow" in arguments]
        self.assertEqual(len(self.recovery.CLI_ASSETS), len(main_attestations))
        self.assertEqual(len(self.recovery.CLI_ASSETS) + 1, len(attestations))
        self.assertEqual("php", calls[-1][0])
        self.assertTrue(all(arguments[0] == "gh" for arguments in calls[:-1]))
        self.assertEqual("qualified-main-workflow", evidence["build_attestation_authority"]["mode"])
        self.assertEqual("refs/heads/main", evidence["build_attestation_authority"]["ref"])
        self.assertEqual(commit, evidence["package_source"]["commit"])
        for arguments in main_attestations:
            self.assertIn("durable-workflow/cli/.github/workflows/release.yml", arguments)
            self.assertNotIn("--source-digest", arguments)

    def test_future_ordinary_release_uses_observed_exact_tag_authority(self) -> None:
        version = "0.1.95"
        commit = "4" * 40
        calls: list[list[str]] = []

        def run(arguments: list[str], **_kwargs: object) -> object:
            calls.append(arguments)
            if arguments[0] == "php":
                return mock.Mock(
                    returncode=0,
                    stdout=f"dw {version} (commit {commit[:12]}, built 2026-07-21)",
                    stderr="",
                )
            return mock.Mock(returncode=0, stdout="verified", stderr="")

        with (
            mock.patch.object(self.recovery.shutil, "which", return_value="/usr/bin/tool"),
            mock.patch.object(self.recovery.subprocess, "run", side_effect=run),
        ):
            evidence = self.recovery.verify_cli(
                self.release_client(version),
                self.recovery.COMPONENTS["cli"],
                version,
                commit,
            )

        attestations = [arguments for arguments in calls if arguments[:3] == ["gh", "attestation", "verify"]]
        self.assertEqual(len(self.recovery.CLI_ASSETS), len(attestations))
        self.assertEqual("php", calls[-1][0])
        self.assertEqual("exact-tag", evidence["build_attestation_authority"]["mode"])
        self.assertEqual(f"refs/tags/{version}", evidence["build_attestation_authority"]["ref"])
        self.assertEqual(commit, evidence["build_attestation_authority"]["commit"])
        for arguments in attestations:
            self.assertIn("--source-digest", arguments)
            self.assertIn(commit, arguments)
            self.assertNotIn("--signer-workflow", arguments)

    def test_mixed_asset_authorities_are_rejected_before_phar_execution(self) -> None:
        version = "0.1.94"
        commit = "36bde75882980e834854a145c9ad0f61ceec4659"
        attestation_count = 0
        calls: list[list[str]] = []

        def run(arguments: list[str], **_kwargs: object) -> object:
            nonlocal attestation_count
            calls.append(arguments)
            if arguments[0] == "php":
                self.fail("the PHAR executed before the complete asset set was authenticated")
            if "--signer-workflow" in arguments:
                return mock.Mock(returncode=0, stdout="verified under main", stderr="")
            attestation_count += 1
            return mock.Mock(
                returncode=0 if attestation_count == 1 else 1,
                stdout="verified" if attestation_count == 1 else "",
                stderr="authority differs" if attestation_count > 1 else "",
            )

        with (
            mock.patch.object(self.recovery.shutil, "which", return_value="/usr/bin/tool"),
            mock.patch.object(self.recovery.subprocess, "run", side_effect=run),
            self.assertRaisesRegex(self.recovery.RecoveryError, "exact-tag: authority differs"),
        ):
            self.recovery.verify_cli(
                self.release_client(version),
                self.recovery.COMPONENTS["cli"],
                version,
                commit,
            )

        self.assertEqual(2, attestation_count)
        self.assertFalse(any(arguments[0] == "php" for arguments in calls))
        self.assertFalse(any("--signer-workflow" in arguments for arguments in calls))

    def test_missing_attestations_fail_before_phar_execution(self) -> None:
        version = "0.1.94"
        commit = "36bde75882980e834854a145c9ad0f61ceec4659"
        calls: list[list[str]] = []

        def run(arguments: list[str], **_kwargs: object) -> object:
            calls.append(arguments)
            if arguments[0] == "php":
                self.fail("the PHAR executed without a build attestation")
            return mock.Mock(returncode=1, stdout="", stderr="attestation missing")

        with (
            mock.patch.object(self.recovery.shutil, "which", return_value="/usr/bin/tool"),
            mock.patch.object(self.recovery.subprocess, "run", side_effect=run),
            self.assertRaisesRegex(self.recovery.RecoveryError, "attestation missing"),
        ):
            self.recovery.verify_cli(
                self.release_client(version),
                self.recovery.COMPONENTS["cli"],
                version,
                commit,
            )

        self.assertEqual(2, len(calls))
        self.assertTrue(all(arguments[0] == "gh" for arguments in calls))

    def test_embedded_planned_commit_is_enforced_after_provenance(self) -> None:
        version = "0.1.94"
        commit = "36bde75882980e834854a145c9ad0f61ceec4659"
        calls: list[list[str]] = []

        def run(arguments: list[str], **_kwargs: object) -> object:
            calls.append(arguments)
            if arguments[0] == "php":
                return mock.Mock(
                    returncode=0,
                    stdout=f"dw {version} (commit {'f' * 12}, built 2026-07-20)",
                    stderr="",
                )
            return mock.Mock(returncode=0, stdout="verified", stderr="")

        with (
            mock.patch.object(self.recovery.shutil, "which", return_value="/usr/bin/tool"),
            mock.patch.object(self.recovery.subprocess, "run", side_effect=run),
            self.assertRaisesRegex(self.recovery.RecoveryError, "does not embed planned source commit"),
        ):
            self.recovery.verify_cli(
                self.release_client(version),
                self.recovery.COMPONENTS["cli"],
                version,
                commit,
            )

        attestations = [arguments for arguments in calls if arguments[0] == "gh"]
        self.assertEqual(len(self.recovery.CLI_ASSETS), len(attestations))
        self.assertEqual("php", calls[-1][0])

