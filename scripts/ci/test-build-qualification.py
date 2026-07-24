#!/usr/bin/env python3
"""Behavior and trust contracts for target-branch qualification selection."""

from __future__ import annotations

import json
import re
import subprocess
import tempfile
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
CLASSIFIER = ROOT / "scripts/ci/classify-target-qualification.php"
CONTRACT = ROOT / ".github/target-qualification.json"
BUILD_WORKFLOW = ROOT / ".github/workflows/php.yml"
RECOVERY_WORKFLOW = ROOT / ".github/workflows/release-plan-recovery.yml"
RELEASE_AUDIT_WORKFLOW = ROOT / ".github/workflows/release-docs-audit.yml"


class QualificationClassificationTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.contract = json.loads(CONTRACT.read_text())
        cls.categories = cls.contract["path_categories"]

    def classify(
        self,
        paths: list[str],
        *,
        server_url: str = "https://github.com",
        event_name: str = "push",
        ref: str = "refs/heads/v2",
    ) -> dict[str, str]:
        with tempfile.NamedTemporaryFile("w", encoding="utf-8") as paths_file:
            paths_file.write("\n".join(paths))
            paths_file.flush()
            result = subprocess.run(
                [
                    "php",
                    str(CLASSIFIER),
                    f"--server-url={server_url}",
                    f"--event-name={event_name}",
                    f"--ref={ref}",
                    f"--paths-file={paths_file.name}",
                    f"--config={CONTRACT}",
                ],
                cwd=ROOT,
                check=True,
                capture_output=True,
                text=True,
            )

        return dict(line.split("=", 1) for line in result.stdout.splitlines())

    def classify_revisions(self, before: str, head: str) -> dict[str, str]:
        result = subprocess.run(
            [
                "php",
                str(CLASSIFIER),
                "--server-url=https://github.com",
                "--event-name=push",
                "--ref=refs/heads/v2",
                f"--before={before}",
                f"--head={head}",
                f"--config={CONTRACT}",
            ],
            cwd=ROOT,
            check=True,
            capture_output=True,
            text=True,
        )

        return dict(line.split("=", 1) for line in result.stdout.splitlines())

    def test_every_allowlisted_path_reports_its_category(self) -> None:
        for category, paths in self.categories.items():
            for path in paths:
                with self.subTest(category=category, path=path):
                    result = self.classify([path])
                    self.assertEqual("release-recovery", result["qualification"])
                    self.assertEqual(category, result["changed_path_categories"])

    def test_runtime_and_qualification_control_paths_select_full(self) -> None:
        paths = {
            "dependency": "composer.json",
            "runtime": "src/V2/Support/LocalActivityRuntime.php",
            "migration": (
                "src/migrations/2022_01_01_000000_create_workflows_table.php"
            ),
            "database": "tests/Feature/DatabaseTruncationTest.php",
            "serialization": "tests/Unit/Serializers/SerializeTest.php",
            "behavior": "tests/Feature/V2/V2WorkflowTest.php",
            "matrix": ".github/feature-test-timings.json",
            "build-workflow": ".github/workflows/php.yml",
            "classification-contract": ".github/target-qualification.json",
            "classifier": "scripts/ci/classify-target-qualification.php",
        }

        for category, path in paths.items():
            with self.subTest(category=category):
                self.assertEqual("full", self.classify([path])["qualification"])

    def test_mixed_change_selects_full(self) -> None:
        focused_path = self.categories["recovery-authority"][0]
        result = self.classify(
            [focused_path, "src/V2/Support/LocalActivityRuntime.php"]
        )
        self.assertEqual("full", result["qualification"])

    def test_empty_or_untrusted_context_selects_full(self) -> None:
        focused_path = self.categories["recovery-authority"][0]
        cases = [
            self.classify([]),
            self.classify([focused_path], event_name="pull_request"),
            self.classify([focused_path], server_url="https://ci.example.test"),
            self.classify([focused_path], ref="refs/heads/feature"),
        ]

        for result in cases:
            self.assertEqual("full", result["qualification"])

    def test_ambiguous_or_unavailable_revision_range_selects_full(self) -> None:
        cases = [
            self.classify_revisions("0" * 40, "a" * 40),
            self.classify_revisions("a" * 40, "b" * 40),
        ]

        for result in cases:
            self.assertEqual("full", result["qualification"])


class QualificationWorkflowTrustTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.build = BUILD_WORKFLOW.read_text()
        cls.recovery = RECOVERY_WORKFLOW.read_text()
        cls.release_audit = RELEASE_AUDIT_WORKFLOW.read_text()
        cls.workflow_sources = [
            path.read_text() for path in (ROOT / ".github/workflows").glob("*.yml")
        ]

    def test_build_has_no_release_credentials_and_does_not_persist_checkout_token(
        self,
    ) -> None:
        self.assertIn("\npermissions:\n  contents: read\n", self.build)
        self.assertNotIn("${{ secrets.", self.build)
        all_workflows = "\n".join(self.workflow_sources)
        checkout_count = all_workflows.count("uses: actions/checkout@")
        self.assertGreater(checkout_count, 0)
        self.assertEqual(
            checkout_count,
            all_workflows.count("persist-credentials: false"),
        )

    def test_pull_request_caches_are_separate_from_protected_run_caches(self) -> None:
        cache_count = self.build.count("uses: actions/cache@")
        self.assertGreater(cache_count, 0)
        self.assertEqual(
            cache_count,
            self.build.count("key: ${{ github.event_name }}-${{ runner.os }}-php-"),
        )
        self.assertEqual(
            cache_count,
            self.build.count("${{ github.event_name }}-${{ runner.os }}-php-") // 2,
        )

    def test_protected_workflows_cannot_be_triggered_by_pull_requests(self) -> None:
        for source in (self.recovery, self.release_audit):
            trigger = source.split("\npermissions:", 1)[0]
            self.assertNotIn("pull_request", trigger)

        self.assertIn("if: github.ref == 'refs/heads/v2'", self.recovery)
        self.assertIn("environment: packagist", self.recovery)
        self.assertIn("\n      contents: write\n", self.recovery)

    def test_no_workflow_consumes_artifacts_from_an_untrusted_run(self) -> None:
        for source in self.workflow_sources:
            self.assertNotIn("actions/download-artifact@", source)

    def test_broad_jobs_require_the_full_class(self) -> None:
        for job in (
            "quality",
            "feature-mysql",
            "feature-mariadb",
            "feature-postgresql",
            "coverage",
        ):
            source = self.job_source(job)
            self.assertIn("needs.route.outputs.qualification == 'full'", source)

    def test_required_check_has_explicit_full_and_focused_contracts(self) -> None:
        build_job = self.job_source("build")
        self.assertIn("qualification == 'release-recovery'", build_job)
        self.assertIn("qualification == 'full'", build_job)
        self.assertIn("Report qualification class and elapsed time", build_job)

    def job_source(self, name: str) -> str:
        marker = f"  {name}:\n"
        self.assertIn(marker, self.build)
        source = self.build.split(marker, 1)[1]
        next_job = re.search(r"(?m)^  [a-z][a-z0-9-]*:\s*$", source)
        return source if next_job is None else source[: next_job.start()]


if __name__ == "__main__":
    unittest.main()
