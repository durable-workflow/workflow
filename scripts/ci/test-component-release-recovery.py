#!/usr/bin/env python3
"""Focused regressions for release recovery workflow source verification."""

from __future__ import annotations

import hashlib
import importlib.util
import io
import sys
import unittest
import urllib.error
from pathlib import Path
from unittest import mock

RECOVERY_SCRIPT = Path(__file__).with_name("component-release-recovery.py")
RUST_WORKFLOW_FIXTURE = Path(__file__).with_name("sdk-rust-release-plan-recovery.fixture.yml")

# This is the complete public sdk-rust workflow identified by the verifier's
# pinned digest, not a reduced semantic approximation of its shell commands.
CURRENT_RUST_RECOVERY_WORKFLOW = RUST_WORKFLOW_FIXTURE.read_text()

GENERIC_RECOVERY_WORKFLOW = r"""on:
  schedule:
  workflow_dispatch:
jobs:
  recover:
    steps:
      - run: |
          python recovery.py resolve --preparation-output release-preparation.json
          gh api --method POST "repos/$GITHUB_REPOSITORY/git/refs" \
            -f ref="refs/tags/$RELEASE_TAG" -f sha="$RELEASE_COMMIT"
          select-publication-run \
            --release-tag "$RELEASE_TAG" --release-commit "$RELEASE_COMMIT"
          gh run list --json databaseId,displayTitle,headBranch,headSha,status,conclusion
          gh workflow run release.yml --ref "$RELEASE_TAG" -f tag="$RELEASE_TAG"
"""


def load_recovery_module():
    spec = importlib.util.spec_from_file_location("component_release_recovery_test", RECOVERY_SCRIPT)
    assert spec is not None and spec.loader is not None
    module = importlib.util.module_from_spec(spec)
    sys.modules[spec.name] = module
    spec.loader.exec_module(module)
    return module


def github_http_error(status: int, body: bytes = b"error", **headers: str) -> urllib.error.HTTPError:
    return urllib.error.HTTPError(
        "https://api.github.com/repos/durable-workflow/.github/releases",
        status,
        "request failed",
        headers,
        io.BytesIO(body),
    )


def load_recovery_for_retry_tests():
    loaded = globals().get("recovery")
    if loaded is not None:
        return loaded
    loader = globals().get("load_recovery_module")
    if not callable(loader):
        raise RuntimeError("release recovery module loader is unavailable")
    return loader()


class ContinuityGateTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.recovery = load_recovery_for_retry_tests()

    def test_scheduled_recovery_pauses_until_remote_resume(self) -> None:
        plan = {"plan": "workspace-unavailable-test"}
        with (
            mock.patch.object(
                self.recovery,
                "resolve_tag",
                side_effect=["a" * 40, None],
            ),
            mock.patch.object(self.recovery, "read_record", return_value=plan),
            mock.patch.object(self.recovery, "validate_plan"),
        ):
            paused = self.recovery.scheduled_continuity_pause(mock.Mock(), plan)

        self.assertEqual(
            "beta-continuity/workspace-unavailable-test/resumed",
            paused["resumed_tag"],
        )
        with (
            mock.patch.object(
                self.recovery,
                "resolve_tag",
                side_effect=["a" * 40, "b" * 40],
            ),
            mock.patch.object(self.recovery, "read_record", return_value=plan),
            mock.patch.object(self.recovery, "validate_plan"),
        ):
            self.assertIsNone(self.recovery.scheduled_continuity_pause(mock.Mock(), plan))


class PublicClientRetryTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.recovery = load_recovery_for_retry_tests()

    def test_retries_service_failures_connection_resets_and_timeouts(self) -> None:
        failures = (
            ("service", github_http_error(503, **{"Retry-After": "4"}), 4),
            ("connection-reset", urllib.error.URLError(ConnectionResetError("reset")), 1),
            ("timeout", urllib.error.URLError(TimeoutError("timed out")), 1),
        )

        for label, failure, expected_delay in failures:
            with self.subTest(label=label):
                sleeps: list[float] = []
                client = self.recovery.PublicClient(
                    max_attempts=2,
                    retry_base_seconds=1,
                    sleep=sleeps.append,
                )
                with mock.patch.object(
                    self.recovery.urllib.request,
                    "urlopen",
                    side_effect=[failure, io.BytesIO(b"[]")],
                ) as open_url:
                    self.assertEqual(
                        [],
                        client.json(
                            "https://api.github.com/repos/durable-workflow/.github/releases?per_page=100"
                        ),
                    )

                self.assertEqual([expected_delay], sleeps)
                self.assertEqual(2, open_url.call_count)

    def test_authentication_is_terminal_even_with_rate_limit_guidance(self) -> None:
        sleeps: list[float] = []
        client = self.recovery.PublicClient(max_attempts=3, sleep=sleeps.append)
        error = github_http_error(
            401,
            b"Bad credentials: API rate limit exceeded",
            **{"Retry-After": "20", "X-RateLimit-Remaining": "0"},
        )

        with (
            mock.patch.object(self.recovery.urllib.request, "urlopen", side_effect=error) as open_url,
            self.assertRaisesRegex(self.recovery.RecoveryError, r"public request failed \(401\)"),
        ):
            client.json("https://api.github.com/repos/durable-workflow/.github/releases?per_page=100")

        self.assertEqual([], sleeps)
        self.assertEqual(1, open_url.call_count)

    def test_authorization_requires_explicit_rate_limit_guidance(self) -> None:
        client = self.recovery.PublicClient(
            max_attempts=2,
            sleep=lambda _delay: self.fail("ordinary authorization failure was retried"),
        )
        with (
            mock.patch.object(
                self.recovery.urllib.request,
                "urlopen",
                side_effect=github_http_error(403, b"Resource not accessible"),
            ) as open_url,
            self.assertRaisesRegex(self.recovery.RecoveryError, r"public request failed \(403\)"),
        ):
            client.json("https://api.github.com/repos/durable-workflow/.github/releases?per_page=100")
        self.assertEqual(1, open_url.call_count)

        sleeps: list[float] = []
        client = self.recovery.PublicClient(max_attempts=2, retry_base_seconds=1, sleep=sleeps.append)
        with mock.patch.object(
            self.recovery.urllib.request,
            "urlopen",
            side_effect=[
                github_http_error(
                    403,
                    b"API rate limit exceeded",
                    **{"X-RateLimit-Remaining": "0"},
                ),
                io.BytesIO(b"[]"),
            ],
        ) as open_url:
            self.assertEqual(
                [],
                client.json("https://api.github.com/repos/durable-workflow/.github/releases?per_page=100"),
            )
        self.assertEqual([1], sleeps)
        self.assertEqual(2, open_url.call_count)

    def test_retry_exhaustion_has_a_distinct_infrastructure_classification(self) -> None:
        client = self.recovery.PublicClient(max_attempts=2, retry_base_seconds=1, sleep=lambda _delay: None)
        with (
            mock.patch.object(
                self.recovery.urllib.request,
                "urlopen",
                side_effect=[github_http_error(503), github_http_error(502)],
            ) as open_url,
            self.assertRaisesRegex(
                self.recovery.PublicInfrastructureError,
                r"classification=github-read-transient, endpoint_class=releases-api, "
                r"attempts=2, reason=retry-exhausted, status=502",
            ),
        ):
            client.json("https://api.github.com/repos/durable-workflow/.github/releases?per_page=100")
        self.assertEqual(2, open_url.call_count)


class ReleasePreparationRecoveryTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.recovery = load_recovery_module()

    def candidate(self) -> dict[str, object]:
        return {
            "plan": "missing-preparation",
            "channel": "alpha",
            "components": {"workflow": {"version": "2.0.0-alpha.1", "commit": "a" * 40}},
        }

    def test_discovery_rejects_missing_preparation_for_an_incomplete_release(self) -> None:
        candidate = self.candidate()
        tag = "release-plan/missing-preparation"
        record_commit = "b" * 40
        client = mock.Mock()
        client.json.return_value = {
            "tag_name": tag,
            "draft": False,
            "assets": [
                {
                    "name": "release-plan.json",
                    "browser_download_url": "https://example.invalid/release-plan.json",
                }
            ],
        }
        client.bytes.return_value = self.recovery.canonical_json(candidate)
        with (
            mock.patch.object(self.recovery, "validate_plan"),
            mock.patch.object(self.recovery, "resolve_tag", return_value=record_commit),
            mock.patch.object(
                self.recovery,
                "read_record",
                side_effect=[candidate, self.recovery.NotFound("missing preparation")],
            ),
            mock.patch.object(
                self.recovery,
                "verify_component",
                side_effect=self.recovery.NotFound("release is incomplete"),
            ),
            self.assertRaisesRegex(self.recovery.RecoveryError, "only completed legacy releases"),
        ):
            self.recovery.discover_plan(client, tag, "workflow")

    def test_missing_preparation_cannot_resolve_to_publish(self) -> None:
        candidate = self.candidate()
        with (
            mock.patch.object(self.recovery, "verify_plan_authority", return_value=({}, {})),
            mock.patch.object(self.recovery, "resolve_tag", return_value=None),
            self.assertRaisesRegex(
                self.recovery.RecoveryError,
                "release preparation required before publishing workflow",
            ),
        ):
            self.recovery.resolve_component(
                mock.Mock(),
                "workflow",
                "release-plan/missing-preparation",
                "b" * 40,
                candidate,
                None,
            )

    def test_completed_legacy_release_still_resolves_to_skip(self) -> None:
        candidate = self.candidate()
        identity = candidate["components"]["workflow"]
        public_evidence = {"version": identity["version"], "commit": identity["commit"]}
        with (
            mock.patch.object(self.recovery, "verify_plan_authority", return_value=({}, {})),
            mock.patch.object(self.recovery, "resolve_tag", return_value=identity["commit"]),
            mock.patch.object(self.recovery, "verify_component", return_value=public_evidence),
        ):
            state, outputs = self.recovery.resolve_component(
                mock.Mock(),
                "workflow",
                "release-plan/missing-preparation",
                "b" * 40,
                candidate,
                None,
            )

        self.assertEqual("skip", outputs["action"])
        self.assertEqual("complete", state["phase"])
        self.assertNotIn("release_preparation", state)


class RecoveryWorkflowSourceTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.recovery = load_recovery_module()

    def assert_rejected(self, source: str) -> None:
        with self.assertRaises(self.recovery.RecoveryError) as caught:
            self.recovery.verify_recovery_workflow_source("sdk-rust", source)
        self.assertEqual(caught.exception.phase, "default-branch-preflight")

    def test_accepts_only_the_current_protected_rust_workflow_identity(self) -> None:
        digest = hashlib.sha256(CURRENT_RUST_RECOVERY_WORKFLOW.encode("utf-8")).hexdigest()
        self.assertEqual(digest, self.recovery.SDK_RUST_RELEASE_RECOVERY_SHA256)
        self.recovery.verify_recovery_workflow_source("sdk-rust", CURRENT_RUST_RECOVERY_WORKFLOW)
        self.recovery.verify_recovery_workflow_source(
            "sdk-rust",
            CURRENT_RUST_RECOVERY_WORKFLOW.replace("\n", "\r\n"),
        )

    def test_rejects_shell_semantic_bypasses_and_any_source_mutation(self) -> None:
        source = CURRENT_RUST_RECOVERY_WORKFLOW
        variants = {
            "one-byte mutation": source.replace("timeout-minutes: 30", "timeout-minutes: 31", 1),
            "one-line mutation": source + "\n",
            "readarray release tag mutation": source.replace(
                "          select_publication_run() {",
                "          readarray -t release_identity < <(printf '%s\\n' mutable)\n"
                '          RELEASE_TAG="${release_identity[0]}"\n\n'
                "          select_publication_run() {",
                1,
            ),
            "successful early exit": source.replace(
                "          python scripts/ci/publish-planned-tag.py \\",
                "          exit 0\n          python scripts/ci/publish-planned-tag.py \\",
                1,
            ),
            "shadowed gh command": source.replace(
                "          set -euo pipefail",
                "          set -euo pipefail\n          gh() { printf 'shadowed\\n'; }",
                1,
            ),
        }

        for label, variant in variants.items():
            with self.subTest(label):
                self.assertNotEqual(variant, source)
                self.assert_rejected(variant)

    def test_rejects_skipped_nonblocking_or_decoy_scoped_steps(self) -> None:
        source = CURRENT_RUST_RECOVERY_WORKFLOW
        tag_step = "      - name: Create or verify the exact planned source tag"
        publication_step = "      - name: Start or resume repository-owned publication"
        completion_step = "      - name: Verify crates.io source identity and the GitHub Release"
        exact_bindings = """          RELEASE_TAG: ${{ needs.discover.outputs.version }}
          RELEASE_COMMIT: ${{ needs.discover.outputs.commit }}"""
        decoy_step = f"""      - name: Unrelated release identity
        env:
{exact_bindings}
        run: echo "release identity is not consumed here"

"""
        mutable_tag_bindings = source.replace(
            exact_bindings,
            """          RELEASE_TAG: ${{ github.ref_name }}
          RELEASE_COMMIT: ${{ github.sha }}""",
            1,
        ).replace(tag_step, decoy_step + tag_step, 1)
        publication_env = """        env:
          GH_TOKEN: ${{ github.token }}
          PLAN_TAG: ${{ needs.discover.outputs.plan_tag }}
          RELEASE_TAG: ${{ needs.discover.outputs.version }}
          RELEASE_COMMIT: ${{ needs.discover.outputs.commit }}"""
        mutable_selector_bindings = source.replace(
            publication_env,
            """        env:
          GH_TOKEN: ${{ github.token }}
          PLAN_TAG: ${{ needs.discover.outputs.plan_tag }}
          RELEASE_TAG: ${{ github.ref_name }}
          RELEASE_COMMIT: ${{ github.sha }}""",
            1,
        ).replace(
            "      - name: Start or resume repository-owned publication",
            decoy_step + "      - name: Start or resume repository-owned publication",
            1,
        )
        variants = {
            "tag publication skipped": source.replace(
                tag_step,
                tag_step + "\n        if: ${{ false }}",
                1,
            ),
            "tag publication nonblocking even when false": source.replace(
                tag_step,
                tag_step + "\n        continue-on-error: false",
                1,
            ),
            "tag publication expression-enabled nonblocking": source.replace(
                tag_step,
                tag_step + "\n        continue-on-error: ${{ github.ref_name != '' }}",
                1,
            ),
            "tag publication uses a nonblocking shell": source.replace(
                tag_step,
                tag_step + "\n        shell: bash {0} || true",
                1,
            ),
            "publication selection skipped": source.replace(
                publication_step,
                publication_step + "\n        if: ${{ false }}",
                1,
            ),
            "completion verification skipped": source.replace(
                completion_step,
                completion_step + "\n        if: ${{ false }}",
                1,
            ),
            "completion verification nonblocking": source.replace(
                completion_step,
                completion_step + "\n        continue-on-error: true",
                1,
            ),
            "completion verification expression-enabled nonblocking": source.replace(
                completion_step,
                completion_step + "\n        continue-on-error: ${{ failure() }}",
                1,
            ),
            "tag bindings moved to an unrelated step": mutable_tag_bindings,
            "selector bindings moved to an unrelated step": mutable_selector_bindings,
            "checkout adds repository-token authority": source.replace(
                "          fetch-depth: 0",
                "          fetch-depth: 0\n          token: ${{ github.token }}",
                1,
            ),
            "run identity includes an unapproved field": source.replace(
                "databaseId,displayTitle,headBranch,headSha,status,conclusion",
                "databaseId,displayTitle,headBranch,headSha,status,conclusion,event",
                1,
            ),
        }

        for label, variant in variants.items():
            with self.subTest(label):
                self.assertNotEqual(variant, source)
                self.assert_rejected(variant)

    def test_rejects_weakened_or_mismatched_rust_publication_shapes(self) -> None:
        source = CURRENT_RUST_RECOVERY_WORKFLOW
        publisher = r"""          python scripts/ci/publish-planned-tag.py \
            --tag "$RELEASE_TAG" --commit "$RELEASE_COMMIT" --plan-tag "$PLAN_TAG" \
            --evidence release-tag-publication-evidence.json"""
        deferred_publisher = source.replace(publisher, "          echo tag-publication-deferred", 1).replace(
            "      - name: Verify crates.io source identity and the GitHub Release",
            "      - name: Deferred source tag publication\n"
            "        run: |\n"
            f"{publisher}\n\n"
            "      - name: Verify crates.io source identity and the GitHub Release",
            1,
        )
        repository_token_creation = source.replace(
            "          python scripts/ci/publish-planned-tag.py",
            "          gh api --method POST \"repos/$GITHUB_REPOSITORY/git/refs\"\n"
            "          python scripts/ci/publish-planned-tag.py",
            1,
        )
        misplaced_deploy_key = source.replace(
            "          ssh-key: ${{ secrets.RELEASE_PLAN_DEPLOY_KEY }}",
            "          env:\n"
            "            UNUSED_DEPLOY_KEY: ${{ secrets.RELEASE_PLAN_DEPLOY_KEY }}",
            1,
        )
        dormant_publisher = source.replace(
            publisher,
            "          publish_planned_tag() {\n"
            + "\n".join(f"  {line}" for line in publisher.splitlines())
            + "\n          }",
            1,
        )
        reassigned_tag = source.replace(
            "          python scripts/ci/publish-planned-tag.py",
            '          RELEASE_TAG="$GITHUB_REF_NAME"\n'
            "          python scripts/ci/publish-planned-tag.py",
            1,
        )
        nonblocking_verification = source.replace(
            "--attempts 6 --sleep 10 --evidence release-completion-evidence.json",
            "--attempts 6 --sleep 10 --evidence release-completion-evidence.json || true",
            1,
        )
        variants = {
            "missing protected environment": source.replace(
                "environment: release-plan-publication", "environment: unprotected", 1
            ),
            "missing deploy key": source.replace(
                "secrets.RELEASE_PLAN_DEPLOY_KEY", "secrets.UNPROTECTED_KEY", 1
            ),
            "deploy key only in unrelated env": misplaced_deploy_key,
            "tag publisher defined but not executed": dormant_publisher,
            "release tag reassigned before publication": reassigned_tag,
            "public verification made nonblocking": nonblocking_verification,
            "tag publication after dispatch": deferred_publisher,
            "mutable tag publisher argument": source.replace(
                '--tag "$RELEASE_TAG"', '--tag "$GITHUB_REF_NAME"', 1
            ),
            "mismatched tag publisher commit": source.replace(
                '--commit "$RELEASE_COMMIT"', '--commit "$GITHUB_SHA"', 1
            ),
            "mutable planned tag binding": source.replace(
                "needs.discover.outputs.version", "github.ref_name"
            ),
            "mutable planned commit binding": source.replace(
                "needs.discover.outputs.commit", "github.sha"
            ),
            "different selected workflow": source.replace(
                "gh run list --workflow release.yml", "gh run list --workflow nightly.yml", 1
            ),
            "different dispatched workflow": source.replace(
                "gh workflow run release.yml", "gh workflow run nightly.yml", 1
            ),
            "incomplete run identity": source.replace(
                "headBranch,headSha,status", "headBranch,status", 1
            ),
            "mismatched selector tag": source.replace(
                '--release-tag "$RELEASE_TAG"', '--release-tag "$GITHUB_REF_NAME"', 1
            ),
            "mismatched selector commit": source.replace(
                '--release-commit "$RELEASE_COMMIT"', '--release-commit "$GITHUB_SHA"', 1
            ),
            "mismatched dispatch tag": source.replace(
                '-f release_tag="$RELEASE_TAG"', '-f release_tag="$GITHUB_REF_NAME"', 1
            ),
            "missing completed release verification": source.replace(
                "--component sdk-rust --plan recovery-input/release-plan.json",
                "--component sdk-rust --plan mutable-release-plan.json",
                1,
            ),
            "broad contents permission": source.replace("contents: read", "contents: write", 1),
            "repository token tag creation": repository_token_creation,
        }

        for label, variant in variants.items():
            with self.subTest(label):
                self.assertNotEqual(variant, source)
                self.assert_rejected(variant)

    def test_other_components_keep_the_contents_api_contract(self) -> None:
        self.recovery.verify_recovery_workflow_source("server", GENERIC_RECOVERY_WORKFLOW)

        protected_only = GENERIC_RECOVERY_WORKFLOW.replace(
            '-f ref="refs/tags/$RELEASE_TAG" -f sha="$RELEASE_COMMIT"',
            'python scripts/ci/publish-planned-tag.py --tag "$RELEASE_TAG" --commit "$RELEASE_COMMIT"',
        )
        with self.assertRaises(self.recovery.RecoveryError):
            self.recovery.verify_recovery_workflow_source("server", protected_only)


if __name__ == "__main__":
    unittest.main()
