#!/usr/bin/env python3
"""Behavior and trust contracts for GitHub build routing and test selection."""

from __future__ import annotations

import json
import os
import re
import shutil
import subprocess
import sys
import tempfile
import textwrap
import unittest
from unittest.mock import patch
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
BUILD_WORKFLOW = ROOT / ".github/workflows/php.yml"
RELEASE_VERIFICATION_WORKFLOW = ROOT / ".github/workflows/release-verification.yml"
FEATURE_SHARD_VERIFIER = ROOT / "scripts/ci/verify-feature-shards.php"
FEATURE_SHARD_SPLITTER = ROOT / "scripts/ci/split-feature-tests.php"
PR_TEST_SELECTOR = ROOT / "scripts/ci/select-pr-tests.php"


def workflow_job_source(source: str, name: str) -> str:
    marker = f"  {name}:\n"
    if marker not in source:
        raise AssertionError(f"workflow does not define the {name} job")
    job = source.split(marker, 1)[1]
    next_job = re.search(r"(?m)^  [a-z][a-z0-9-]*:\s*$", job)
    return job if next_job is None else job[: next_job.start()]


def workflow_job_needs(source: str, name: str) -> list[str]:
    job = workflow_job_source(source, name)
    needs = re.search(r"(?m)^ {4}needs:[ \t]*(.*)$", job)

    if needs is None:
        return []

    inline = needs.group(1).strip()
    if inline:
        if inline.startswith("[") and inline.endswith("]"):
            return [
                dependency.strip().strip("'\"")
                for dependency in inline[1:-1].split(",")
                if dependency.strip()
            ]

        return [inline.strip("'\"")]

    dependencies = []
    for line in job[needs.end() :].splitlines():
        dependency = re.match(r"^ {4}(?: {2})?-\s+(['\"]?)([a-z0-9-]+)\1\s*$", line)
        if dependency:
            dependencies.append(dependency.group(2))
            continue
        if line.strip():
            break

    return dependencies


def workflow_job_ancestors(source: str, name: str) -> set[str]:
    ancestors = set()
    pending = workflow_job_needs(source, name)

    while pending:
        dependency = pending.pop()
        if dependency in ancestors:
            continue
        ancestors.add(dependency)
        pending.extend(workflow_job_needs(source, dependency))

    return ancestors


class FeatureShardRoutingTest(unittest.TestCase):
    known_features = [
        "tests/Feature/SmokeDurableDispatchTest.php",
        "tests/Feature/SmokeSignalUpdateTest.php",
        "tests/Feature/SmokeQueryReplayTest.php",
        "tests/Feature/SmokePersistedPayloadTest.php",
    ]
    # Enough profiled files for every feature shard to receive one.
    filler_features = [
        "tests/Feature/FillerOneTest.php",
        "tests/Feature/FillerTwoTest.php",
    ]
    unseen_feature = "tests/Feature/V2/UnprofiledFeatureTest.php"

    def setUp(self) -> None:
        self.temporary_directory = tempfile.TemporaryDirectory()
        self.root = Path(self.temporary_directory.name)

        for source in (
            BUILD_WORKFLOW,
            ROOT / ".github/workflows/public-boundary.yml",
            FEATURE_SHARD_VERIFIER,
            FEATURE_SHARD_SPLITTER,
            PR_TEST_SELECTOR,
        ):
            relative = source.relative_to(ROOT)
            destination = self.root / relative
            destination.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(source, destination)

        smoke_methods = [
            "testDurableDispatch",
            "testSignalUpdateOrder",
            "testQueryReplay",
            "testPersistedPayload",
        ]
        for feature, method in zip(self.known_features, smoke_methods, strict=True):
            self.write(
                feature,
                "<?php\n"
                "final class FeatureFixture\n"
                "{\n"
                f"    public function {method}(): void {{}}\n"
                "}\n",
            )
        for feature in self.filler_features:
            self.write(feature, "<?php\nfinal class FillerFeatureTest {}\n")
        self.write(
            self.unseen_feature,
            "<?php\nfinal class UnprofiledFeatureTest {}\n",
        )
        self.write(
            "tests/Unit/QualificationContractTest.php",
            "<?php\n"
            "use PHPUnit\\Framework\\TestCase;\n"
            "final class QualificationContractTest extends TestCase {}\n",
        )

        profile = dict(
            zip(self.known_features, [40.0, 30.0, 20.0, 10.0], strict=True)
        )
        profile.update(dict.fromkeys(self.filler_features, 5.0))
        self.write_json(
            ".github/feature-test-timings.json",
            {"mysql": profile, "postgresql": profile},
        )
        self.write_json(
            ".github/pr-test-selection.json",
            {
                "changed_feature_budget_seconds": 120,
                "unknown_feature_weight_seconds": 30,
                "unit_contracts": ["tests/Unit/QualificationContractTest.php"],
                "mysql_smoke": [
                    {
                        "behavior": behavior,
                        "file": feature,
                        "test": method,
                    }
                    for behavior, feature, method in zip(
                        [
                            "durable-dispatch",
                            "signal-update-order",
                            "query-replay",
                            "persisted-payload",
                        ],
                        self.known_features,
                        smoke_methods,
                        strict=True,
                    )
                ],
            },
        )
        self.write("changed-files.txt", f"{self.unseen_feature}\n")

    def tearDown(self) -> None:
        self.temporary_directory.cleanup()

    def write(self, relative: str, contents: str) -> None:
        path = self.root / relative
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(contents)

    def write_json(self, relative: str, document: object) -> None:
        self.write(relative, json.dumps(document, indent=2) + "\n")

    def run_php(
        self, script: str, *arguments: str, check: bool = True
    ) -> subprocess.CompletedProcess[str]:
        return subprocess.run(
            ["php", script, *arguments],
            cwd=self.root,
            check=check,
            capture_output=True,
            text=True,
        )

    def shard_assignments(self, profile: str) -> list[list[str]]:
        assignments = []

        for shard in range(6):
            result = self.run_php(
                "scripts/ci/split-feature-tests.php",
                "--dir=tests/Feature",
                f"--shard={shard}",
                "--shards=6",
                "--weights=.github/feature-test-timings.json",
                f"--weight-profile={profile}",
            )
            assignments.append(result.stdout.splitlines())

        return assignments

    def test_unprofiled_feature_enters_pull_request_and_target_branch_suites(
        self,
    ) -> None:
        timing_profile = self.root / ".github/feature-test-timings.json"
        original_profile = timing_profile.read_bytes()

        verification = self.run_php("scripts/ci/verify-feature-shards.php")
        self.assertIn("represented exactly once", verification.stdout)

        for profile in ("mysql", "postgresql"):
            with self.subTest(profile=profile):
                first = self.shard_assignments(profile)
                second = self.shard_assignments(profile)
                represented = [file for shard in first for file in shard]

                self.assertEqual(first, second)
                self.assertCountEqual(
                    self.known_features + self.filler_features + [self.unseen_feature],
                    represented,
                )
                self.assertEqual(1, represented.count(self.unseen_feature))
                unseen_shard = next(shard for shard in first if self.unseen_feature in shard)
                self.assertEqual(
                    self.unseen_feature,
                    unseen_shard[-1],
                    "known timings should remain authoritative for shard balance",
                )

        selection = self.run_php(
            "scripts/ci/select-pr-tests.php",
            "--config=.github/pr-test-selection.json",
            "--weights=.github/feature-test-timings.json",
            "--weight-profile=mysql",
            "--changed-files=changed-files.txt",
            "--output-dir=pr-selection",
        )
        self.assertEqual("", selection.stdout)
        self.assertEqual(
            f"{self.unseen_feature}\n",
            (self.root / "pr-selection/mysql-changed-files.txt").read_text(),
        )
        self.assertIn(
            f"30.000 (conservative-default) {self.unseen_feature}",
            (self.root / "pr-selection/summary.txt").read_text(),
        )
        self.assertEqual(original_profile, timing_profile.read_bytes())

    def test_profile_entries_must_describe_discovered_tests(self) -> None:
        profile_path = ".github/feature-test-timings.json"
        document = json.loads((self.root / profile_path).read_text())
        document["mysql"]["tests/Feature/ExplicitlyExcludedTest.php"] = 5
        self.write_json(profile_path, document)

        result = self.run_php(
            "scripts/ci/verify-feature-shards.php",
            check=False,
        )

        self.assertNotEqual(0, result.returncode)
        self.assertIn(
            "contains tests outside the discovered feature inventory",
            result.stderr,
        )

    def test_malformed_profile_fails_validation(self) -> None:
        profile_path = ".github/feature-test-timings.json"
        document = json.loads((self.root / profile_path).read_text())
        document["mysql"][self.known_features[0]] = -1
        self.write_json(profile_path, document)

        result = self.run_php(
            "scripts/ci/verify-feature-shards.php",
            check=False,
        )

        self.assertNotEqual(0, result.returncode)
        self.assertIn("must be greater than zero", result.stderr)

    def test_duplicate_or_unassigned_features_fail_validation(
        self,
    ) -> None:
        cases = {
            "duplicate": (
                [
                    self.known_features[0],
                    self.known_features[0],
                    self.known_features[1],
                    self.known_features[2],
                    *self.filler_features,
                ],
                f"Duplicated: [{self.known_features[0]}]",
            ),
            "unassigned": (
                self.known_features + self.filler_features,
                f"Missing: [{self.unseen_feature}]",
            ),
        }

        for name, (assignments, expected_error) in cases.items():
            with self.subTest(name=name):
                assignment_literal = ",\n    ".join(
                    f"'{assignment}'" for assignment in assignments
                )
                self.write(
                    "scripts/ci/split-feature-tests.php",
                    "<?php\n"
                    "$options = getopt('', [\n"
                    "    'dir:',\n"
                    "    'shard:',\n"
                    "    'shards:',\n"
                    "    'weights:',\n"
                    "    'weight-profile:',\n"
                    "]);\n"
                    "$assignments = [\n"
                    f"    {assignment_literal},\n"
                    "];\n"
                    "echo $assignments[(int) $options['shard']] . PHP_EOL;\n",
                )

                result = self.run_php(
                    "scripts/ci/verify-feature-shards.php",
                    check=False,
                )

                self.assertNotEqual(0, result.returncode)
                self.assertIn(expected_error, result.stderr)


class BuildWorkflowTrustTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.build = BUILD_WORKFLOW.read_text()
        cls.release_verification = RELEASE_VERIFICATION_WORKFLOW.read_text()
        cls.workflow_sources = [
            path.read_text() for path in (ROOT / ".github/workflows").glob("*.yml")
        ]

    def test_build_has_no_release_credentials_and_does_not_persist_checkout_token(
        self,
    ) -> None:
        self.assertIn("\npermissions:\n  contents: read\n", self.build)
        analysis = self.job_source("sonar-coverage")
        self.assertNotIn("${{ secrets.", self.build.replace(analysis, "", 1))
        self.assertEqual(
            ["SONAR_TOKEN"],
            re.findall(r"\$\{\{\s*secrets\.([A-Z0-9_]+)\s*\}\}", analysis),
        )
        self.assertIn(
            "if: github.event_name != 'pull_request' && github.ref == 'refs/heads/main'",
            analysis,
        )
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

    def test_release_verification_cannot_be_triggered_by_pull_requests(self) -> None:
        trigger = self.release_verification.split("\npermissions:", 1)[0]
        self.assertNotIn("pull_request", trigger)

    def test_published_laravel_upgrade_depends_on_package_verification(self) -> None:
        published_ancestors = workflow_job_ancestors(
            self.release_verification,
            "laravel-upgrade",
        )

        self.assertEqual({"published-package"}, published_ancestors)

    def test_target_branch_jobs_skip_pull_requests(self) -> None:
        for job in (
            "feature-mysql",
            "feature-mariadb",
            "coverage-unit",
            "coverage",
        ):
            source = self.job_source(job)
            self.assertIn("github.event_name != 'pull_request'", source)

    def test_unit_and_mysql_coverage_collect_without_waiting_for_each_other(self) -> None:
        for producer in ("coverage-unit", "feature-mysql"):
            with self.subTest(producer=producer):
                self.assertEqual({"preflight"}, workflow_job_ancestors(self.build, producer))

        unit = self.job_source("coverage-unit")
        self.assertIn("--coverage-clover=coverage.xml --testsuite unit", unit)
        self.assertIn("if-no-files-found: error", unit)

    def test_pull_request_tracing_is_independent_and_required(self) -> None:
        self.assertEqual({"preflight"}, workflow_job_ancestors(self.build, "pr-tracing"))
        self.assertNotIn("pr-tracing", workflow_job_ancestors(self.build, "pr-unit-contracts"))
        self.assertIn("github.event_name == 'pull_request'", self.job_source("pr-tracing"))
        self.assertIn("pr-tracing", workflow_job_needs(self.build, "build"))

        gate = self.job_source("build")
        for result in ("failure", "cancelled", "skipped"):
            with self.subTest(result=result):
                command = 'test "${{ needs.pr-tracing.result }}" = "success"'
                self.assertIn(command, gate)
                refused = subprocess.run(
                    ["sh", "-c", command.replace("${{ needs.pr-tracing.result }}", result)],
                    check=False,
                    capture_output=True,
                )
                self.assertNotEqual(0, refused.returncode)

        self.assertIn('test "${{ needs.pr-tracing.result }}" = "skipped"', gate)

    def test_pull_requests_run_the_same_unit_suite_as_coverage_unit(self) -> None:
        self.assertEqual({"preflight"}, workflow_job_ancestors(self.build, "pr-unit"))
        source = self.job_source("pr-unit")
        self.assertIn("github.event_name == 'pull_request'", source)
        self.assertIn("vendor/bin/phpunit --testsuite unit", source)
        self.assertIn("image: redis", source)
        self.assertIn("pr-unit", workflow_job_needs(self.build, "build"))

        gate = self.job_source("build")
        self.assertIn('test "${{ needs.pr-unit.result }}" = "success"', gate)
        self.assertIn('test "${{ needs.pr-unit.result }}" = "skipped"', gate)

    def test_pull_requests_run_the_postgresql_feature_suite(self) -> None:
        source = self.job_source("feature-postgresql")
        self.assertNotIn("    if: ${{ github.event_name != 'pull_request' }}\n", source)
        self.assertIn("feature-postgresql", workflow_job_needs(self.build, "build"))
        gate = self.job_source("build")
        self.assertIn('test "${{ needs.feature-postgresql.result }}" = "success"', gate)
        self.assertNotIn('test "${{ needs.feature-postgresql.result }}" = "skipped"', gate)

    def test_combined_coverage_waits_for_both_producers_and_requires_every_report(self) -> None:
        self.assertEqual(
            {"preflight", "feature-mysql", "coverage-unit"},
            set(workflow_job_needs(self.build, "coverage")),
        )
        aggregate = self.job_source("coverage")
        self.assertIn("artifact-ids: ${{ needs.coverage-unit.outputs.unit_artifact_id }}", aggregate)
        self.assertIn("digest-mismatch: error", aggregate)
        for report in ["coverage.xml"] + [
            f"build/feature-coverage/mysql-shard-{shard}-coverage.xml" for shard in range(6)
        ]:
            with self.subTest(report=report):
                self.assertIn(f"--clover={report}", aggregate)

    def test_required_check_has_explicit_pull_request_and_target_contracts(self) -> None:
        build_job = self.job_source("build")
        self.assertIn("Check pull-request gate", build_job)
        self.assertIn("Check target-branch matrix", build_job)
        self.assertIn("needs.regression-corpus.result", build_job)
        self.assertIn('test "${{ needs.coverage-unit.result }}" = "skipped"', build_job)
        self.assertIn('test "${{ needs.coverage-unit.result }}" = "success"', build_job)

    def job_source(self, name: str) -> str:
        return workflow_job_source(self.build, name)


class PublishedPackageAdmissionTest(unittest.TestCase):
    def step_script(self, identity: str) -> str:
        source = workflow_job_source(RELEASE_VERIFICATION_WORKFLOW.read_text(), "published-package")
        for step in source.split("      - "):
            if re.search(rf"(?m)^        id: {re.escape(identity)}$", step):
                return textwrap.dedent(step.split("        run: |\n", 1)[1]).rstrip()
        self.fail(f"Missing executable release step {identity}")

    def environment(self, output: Path) -> dict[str, str]:
        return {
            "PATH": os.defpath,
            "GITHUB_REPOSITORY": "durable-workflow/workflow",
            "GITHUB_RUN_ATTEMPT": "1",
            "GITHUB_WORKFLOW_SHA": "a" * 40,
            "GITHUB_SHA": "b" * 40,
            "GITHUB_EVENT_NAME": "workflow_dispatch",
            "GITHUB_REF": "refs/heads/main",
            "GITHUB_REF_TYPE": "branch",
            "GITHUB_REF_NAME": "main",
            "INPUT_TAG": "2.20.4",
            "RELEASE_TAG": "2.20.4",
            "GITHUB_OUTPUT": str(output),
        }

    def test_release_admission_accepts_only_the_bound_stable_context(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            output = Path(directory) / "outputs"
            accepted = [
                {},
                {"GITHUB_EVENT_NAME": "push", "GITHUB_REF_TYPE": "tag",
                 "GITHUB_REF": "refs/tags/2.20.4", "GITHUB_REF_NAME": "2.20.4"},
            ]
            refused = [
                {"GITHUB_REPOSITORY": "other/workflow"},
                {"GITHUB_RUN_ATTEMPT": "2"},
                {"GITHUB_WORKFLOW_SHA": "main"},
                {"GITHUB_EVENT_NAME": "pull_request"},
                {"GITHUB_REF": "refs/tags/2.20.4"},
                {"INPUT_TAG": "v2.20.4"},
                {"INPUT_TAG": "2.20.4-rc1"},
                {"INPUT_TAG": "2.020.4"},
                {"INPUT_TAG": "../../main"},
                {"GITHUB_EVENT_NAME": "push", "GITHUB_REF_TYPE": "tag",
                 "GITHUB_REF": "refs/tags/2.20.3", "GITHUB_REF_NAME": "2.20.4"},
            ]
            for changes, expected in [(case, True) for case in accepted] + [(case, False) for case in refused]:
                with self.subTest(changes=changes):
                    output.unlink(missing_ok=True)
                    result = subprocess.run(
                        ["bash", "-c", self.step_script("release")],
                        env=self.environment(output) | changes, capture_output=True, text=True, timeout=5,
                    )
                    self.assertEqual(expected, result.returncode == 0, result.stderr)
                    if expected:
                        self.assertEqual("tag=2.20.4\n", output.read_text())
                    else:
                        self.assertFalse(output.exists())

    def test_product_binding_peels_the_tag_without_replacing_the_harness(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            output = root / "outputs"
            def git(*arguments: str) -> str:
                return subprocess.check_output(
                    ["git", "-c", "user.name=Fixture", "-c", "user.email=fixture@example.invalid", *arguments],
                    cwd=root, text=True, stderr=subprocess.DEVNULL, timeout=5,
                ).strip()
            git("init", "-q")
            git("commit", "--allow-empty", "-qm", "Product")
            product = git("rev-parse", "HEAD")
            git("tag", "-am", "Product tag", "2.20.4")
            git("commit", "--allow-empty", "-qm", "Maintained harness")
            harness = git("rev-parse", "HEAD")
            environment = self.environment(output) | {"GITHUB_WORKFLOW_SHA": harness}
            result = subprocess.run(
                ["bash", "-c", self.step_script("product")], cwd=root,
                env=environment, capture_output=True, text=True, timeout=5,
            )
            self.assertEqual(0, result.returncode, result.stderr)
            self.assertEqual(f"revision={product}\n", output.read_text())
            self.assertEqual(harness, git("rev-parse", "HEAD"))
            output.unlink()
            result = subprocess.run(
                ["bash", "-c", self.step_script("product")], cwd=root,
                env=environment | {"GITHUB_EVENT_NAME": "push", "GITHUB_SHA": harness},
                capture_output=True, text=True, timeout=5,
            )
            self.assertNotEqual(0, result.returncode)
            self.assertFalse(output.exists())

    def test_product_metadata_must_match_the_selected_tag(self) -> None:
        script = self.step_script("product_metadata")
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            product = root / ".release-product"
            product.mkdir()
            manifest = product / "composer.json"
            for train, expected in [("2.20.4", True), ("2.21.0", False)]:
                with self.subTest(train=train):
                    manifest.write_text(json.dumps({"name": "durable-workflow/workflow", "extra": {
                        "durable-workflow": {"product-train": train},
                    }}))
                    result = subprocess.run(
                        ["bash", "-c", script], cwd=root,
                        env=self.environment(root / "outputs"), capture_output=True, text=True, timeout=5,
                    )
                    self.assertEqual(expected, result.returncode == 0, result.stderr)

    def test_matrix_failure_does_not_publish_an_empty_successful_output(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            output = root / "outputs"
            php = root / "php"
            environment = self.environment(output) | {"PATH": str(root) + os.pathsep + os.defpath}
            php.write_text("#!/bin/sh\nexit 23\n")
            php.chmod(0o700)
            result = subprocess.run(
                ["bash", "-e", "-c", self.step_script("laravel-matrix")],
                env=environment, capture_output=True, text=True, timeout=5,
            )
            self.assertEqual(23, result.returncode)
            self.assertFalse(output.exists())
            php.write_text("#!/bin/sh\nprintf '%s\\n' '{\"include\":[]}'\n")
            result = subprocess.run(
                ["bash", "-e", "-c", self.step_script("laravel-matrix")],
                env=environment, capture_output=True, text=True, timeout=5,
            )
            self.assertEqual(0, result.returncode, result.stderr)
            self.assertEqual('matrix={"include":[]}\n', output.read_text())


class SystemPythonBootstrapSelectionTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        source = RELEASE_VERIFICATION_WORKFLOW.read_text()
        step = source.split("      - name: Select the trusted system Python\n", 1)[1]
        cls.script = textwrap.dedent(step.split("<<'PYTHON'\n", 1)[1].split("          PYTHON\n", 1)[0])

    def selection(self, *, version=(3, 12, 3), location="", foreign_alias=False):
        with tempfile.TemporaryDirectory() as directory:
            output = Path(directory) / "github-path"
            output.touch(mode=0o600)
            def canonical(path, strict=False):
                if foreign_alias and str(path) == "/usr/bin/python":
                    return Path("/opt/shared/python")
                return Path("/usr/bin/python3.12")
            with patch.dict(os.environ, {"GITHUB_PATH": str(output), "pythonLocation": location}, clear=True), \
                    patch.object(sys, "version_info", version), \
                    patch.object(sys, "executable", "/usr/bin/python3"), \
                    patch.object(Path, "resolve", autospec=True, side_effect=canonical):
                try:
                    exec(compile(self.script, "actual-system-python-step", "exec"), {})
                except ValueError as error:
                    return output.read_text(), str(error)
            return output.read_text(), None

    def test_exact_system_alias_is_selected_before_bootstrap(self):
        output, error = self.selection()
        self.assertEqual("/usr/bin\n", output)
        self.assertIsNone(error)

    def test_toolcache_selection_refuses_before_path_change(self):
        output, error = self.selection(location="/opt/hostedtoolcache/Python/3.12.16/x64")
        self.assertEqual("", output)
        self.assertIn("without toolcache", error)

    def test_changed_system_alias_refuses_before_path_change(self):
        output, error = self.selection(foreign_alias=True)
        self.assertEqual("", output)
        self.assertIn("exact reviewed origin", error)

    def test_other_python_version_refuses_before_path_change(self):
        output, error = self.selection(version=(3, 13, 0))
        self.assertEqual("", output)
        self.assertIn("Ubuntu 24 system Python", error)


if __name__ == "__main__":
    unittest.main()
