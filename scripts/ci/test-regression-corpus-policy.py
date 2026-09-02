#!/usr/bin/env python3
"""Adversarial checks for regression-corpus policy enforcement."""

from __future__ import annotations

import json
import os
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path
from typing import Any

REPOSITORY_ROOT = Path(__file__).resolve().parents[2]
VALIDATOR = Path(__file__).with_name("validate-regression-corpus.py")
PHP_GOLDEN_REPLAY_WORKFLOW = "Tests\\Fixtures\\V2\\TestGoldenReplayWorkflow"


def run(
    *arguments: str,
    cwd: Path,
    env: dict[str, str] | None = None,
) -> subprocess.CompletedProcess[str]:
    return subprocess.run(
        list(arguments),
        cwd=cwd,
        check=False,
        capture_output=True,
        text=True,
        env=env,
    )


class RegressionCorpusPolicyTest(unittest.TestCase):
    def setUp(self) -> None:
        self.temporary = tempfile.TemporaryDirectory()
        self.root = Path(self.temporary.name)
        for source_path in self.codec_source_paths():
            target = self.root / source_path
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_text("<?php\nreturn 'base';\n", encoding="utf-8")
        for source_path in self.replay_source_paths():
            target = self.root / source_path
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_text("<?php\nreturn 'base';\n", encoding="utf-8")
        self.write_json(
            "tests/Fixtures/V2/CodecRegression/base.json",
            self.codec_fixture("base-codec-case", "0", "AA=="),
        )
        self.write_json(
            "tests/Fixtures/V2/ReplayRegression/base.json",
            self.replay_fixture("base-replay-case"),
        )
        self.write_json(
            "tests/Evidence/existing-codec.json",
            self.codec_fixture("existing-unselected-codec-case", "1", "Ag=="),
        )
        policy = json.loads(
            (REPOSITORY_ROOT / "regression-corpus-policy.json").read_text(encoding="utf-8")
        )
        self.write_json("regression-corpus-policy.json", policy)
        consumer = self.root / "vendor/bin/phpunit"
        consumer.parent.mkdir(parents=True, exist_ok=True)
        consumer.write_text(
            """<?php
$arguments = implode(' ', $argv);
$codec = str_contains($arguments, 'AvroValueProtocolTest.php');
$fixtureDirectory = $codec
    ? __DIR__ . '/../../tests/Fixtures/V2/CodecRegression'
    : __DIR__ . '/../../tests/Fixtures/V2/ReplayRegression';
$embeddedHarness = __DIR__ . '/../../tests/Feature/V2/V2EmbeddedReplayRegressionCorpusTest.php';
$embeddedReplay = !$codec
    && is_file($embeddedHarness)
    && str_contains((string) file_get_contents($embeddedHarness), 'query-state-replayer');
$source = $codec
    ? __DIR__ . '/../../src/Serializers/Json.php'
    : (
        $embeddedReplay
            ? __DIR__ . '/../../src/V2/Support/QueryStateReplayer.php'
            : __DIR__ . '/../../src/V2/Support/WorkflowFiberRunner.php'
    );
foreach (glob($fixtureDirectory . '/*.json') ?: [] as $path) {
    $fixture = json_decode((string) file_get_contents($path), true);
    $requiresChange = str_contains((string) ($fixture['id'] ?? ''), 'requires-change')
        || (
            $embeddedReplay
            && str_contains((string) ($fixture['id'] ?? ''), 'requires-query-change')
        );
    if (
        $requiresChange
        && str_contains((string) file_get_contents($source), "'base'")
    ) {
        fwrite(STDERR, "fixture requires the guarded implementation change\\n");
        exit(1);
    }
}
exit(0);
""",
            encoding="utf-8",
        )
        self.git("init", "--quiet")
        self.git("add", "--all")
        self.git(
            "-c",
            "user.name=Regression Corpus Test",
            "-c",
            "user.email=regression-corpus@example.invalid",
            "commit",
            "--quiet",
            "--message=baseline",
        )
        self.base_ref = self.git("rev-parse", "HEAD").stdout.strip()

    def tearDown(self) -> None:
        self.temporary.cleanup()

    @staticmethod
    def codec_source_paths() -> tuple[str, ...]:
        return (
            "src/Serializers/Json.php",
            "src/Serializers/Serializer.php",
            "src/V2/Support/PayloadEnvelopeResolver.php",
            "src/V2/Support/WorkflowPayloadDecoder.php",
        )

    @staticmethod
    def replay_source_paths() -> tuple[str, ...]:
        return (
            "src/V2/Support/DefaultWorkflowTaskBridge.php",
            "src/V2/Support/QueryStateReplayer.php",
            "src/V2/Support/WorkflowExecution.php",
            "src/V2/Support/WorkflowExecutor.php",
            "src/V2/Support/WorkflowFiberRunner.php",
            "src/V2/Support/WorkflowReplayer.php",
            "src/V2/Support/WorkflowStepHistory.php",
        )

    @staticmethod
    def codec_fixture(
        identity: str,
        value: str,
        wire_base64: str | None,
        *,
        codec: str = "avro",
        schema: str = "example.Value",
        fingerprint: str | None = None,
        tagged_value: dict[str, Any] | None = None,
        bindings: list[str] | None = None,
        operation: str = "round_trip",
        error: str | None = None,
    ) -> dict[str, Any]:
        fixture = {
            "$schema": "https://example.invalid/evidence-schema.json",
            "fixture_schema": "durable-workflow.codec-regression/v1",
            "id": identity,
            "protocol": {
                "codec": codec,
                "schema": schema,
                "version": "1",
                "fingerprint": fingerprint,
            },
            "bindings": bindings or ["php"],
            "value": tagged_value or {"type": "long", "value": value},
            "framing": {"encoding": "base64"},
            "failure_policy": {"operation": operation, "error": error},
        }
        if wire_base64 is not None:
            fixture["framing"]["wire_base64"] = wire_base64
        return fixture

    @staticmethod
    def replay_fixture(
        identity: str,
        *,
        bindings: list[str] | None = None,
        protocol_version: str = "2",
        workflow_type: str = "Tests\\Fixtures\\ReplayWorkflow",
    ) -> dict[str, Any]:
        return {
            "$schema": "https://example.invalid/evidence-schema.json",
            "fixture_schema": "durable-workflow.replay-regression/v1",
            "id": identity,
            "protocol_version": protocol_version,
            "bindings": bindings or ["php"],
            "workflow": {
                "type": workflow_type,
                "arguments": [],
                "payload_codec": "avro",
            },
            "history": [
                {
                    "event_type": "WorkflowStarted",
                    "payload": {},
                }
            ],
            "expected": {
                "completed": True,
                "result": "done",
                "commands": [],
            },
        }

    @staticmethod
    def avro_golden_fixture(
        wire_base64: str,
        *,
        kind: str = "long",
        value: Any = "7",
        value_base64: str | None = None,
    ) -> dict[str, Any]:
        case = {
            "name": f"{kind}_value",
            "kind": kind,
            "wire_base64": wire_base64,
        }
        if value is not None:
            case["value"] = value
        if value_base64 is not None:
            case["value_base64"] = value_base64
        return {
            "schema": "example.CrossFormatValue",
            "fingerprint": "0123456789abcdef",
            "cases": [case],
            "alternate_map_orders": [
                {
                    "name": "map_order",
                    "wire_base64": ["Ag==", "Aw=="],
                }
            ],
            "malformed_frames": [
                {
                    "name": "bad_frame",
                    "error": "invalid_payload_framing",
                    "wire_base64": "AQ==",
                }
            ],
        }

    @classmethod
    def malformed_avro_golden_fixture(
        cls,
        wire_base64: str,
        *,
        name: str = "bad_frame",
    ) -> dict[str, Any]:
        fixture = cls.avro_golden_fixture("AA==")
        fixture["malformed_frames"][0]["name"] = name
        fixture["malformed_frames"][0]["wire_base64"] = wire_base64
        return fixture

    @staticmethod
    def shared_avro_fixture() -> dict[str, Any]:
        return json.loads(
            (
                REPOSITORY_ROOT
                / "resources/protocol/avro-value-v1-golden.json"
            ).read_text(encoding="utf-8")
        )

    @classmethod
    def golden_history_fixture(cls) -> dict[str, Any]:
        replay = cls.official_history_replay_fixture()
        history = json.loads(json.dumps(replay["history"]))
        history[1]["payload"]["result_value"] = "Hello, Ada!"
        del history[1]["payload"]["result"]
        del history[1]["payload"]["payload_codec"]
        for event in history:
            event.pop("sequence")
            event.pop("recorded_at")
        return {
            "fixture_schema": "durable-workflow.golden-history.v1",
            "source": {
                "runtime": "workflow-php",
                "package": "durable-workflow/workflow",
                "version": "2.0.0",
                "worker_protocol_version": "1.0",
            },
            "cases": [
                {
                    "name": "base-replay",
                    "family": "activity",
                    "scenario": replay["workflow"]["arguments"][0],
                    "history": history,
                    "expected_state": replay["expected"]["result"],
                }
            ],
        }

    @staticmethod
    def golden_failure_fixture(
        *,
        message: str = "payment declined",
        code: Any = None,
        exception: Any = None,
        expected_message: str | None = None,
    ) -> dict[str, Any]:
        failure_payload: dict[str, Any] = {
            "sequence": 2,
            "message": message,
        }
        if code is not None:
            failure_payload["code"] = code
        if exception is not None:
            failure_payload["exception"] = exception
        return {
            "fixture_schema": "durable-workflow.golden-history.v1",
            "source": {
                "runtime": "workflow-php",
                "package": "durable-workflow/workflow",
                "version": "2.0.0",
                "worker_protocol_version": "1.0",
            },
            "cases": [
                {
                    "name": "saga-child-failure",
                    "family": "saga-compensation",
                    "scenario": "saga-compensation",
                    "history": [
                        {
                            "event_type": "WorkflowStarted",
                            "payload": {},
                        },
                        {
                            "event_type": "ActivityCompleted",
                            "payload": {
                                "sequence": 1,
                                "result_value": "inventory-id-456",
                            },
                        },
                        {
                            "event_type": "ChildRunFailed",
                            "payload": failure_payload,
                        },
                        {
                            "event_type": "ActivityCompleted",
                            "payload": {
                                "sequence": 3,
                                "result_value": "cancelled-inventory-id-456",
                            },
                        },
                    ],
                    "expected_state": {
                        "stage": "compensated",
                        "name": None,
                        "greeting": None,
                        "approved": False,
                        "version": -1,
                        "version_result": None,
                        "reservation_id": "inventory-id-456",
                        "events": [
                            f"compensated:{expected_message or message}",
                        ],
                    },
                }
            ],
        }

    @classmethod
    def official_history_replay_fixture(cls) -> dict[str, Any]:
        replay = cls.replay_fixture(
            "official-history-replay",
            workflow_type=PHP_GOLDEN_REPLAY_WORKFLOW,
        )
        replay["workflow"]["arguments"] = ["single-activity"]
        replay["history"] = [
            {
                "sequence": 1,
                "event_type": "WorkflowStarted",
                "payload": {},
                "recorded_at": "2026-07-29T12:00:00+00:00",
            },
            {
                "sequence": 7,
                "event_type": "ActivityCompleted",
                "payload": {
                    "sequence": 7,
                    "activity_type": "Tests\\Fixtures\\V2\\TestGreetingActivity",
                    "result": "wwHioz3/VYAiNwoWSGVsbG8sIEFkYSE=",
                    "payload_codec": "avro",
                },
                "recorded_at": "2026-07-29T12:00:01+00:00",
            },
        ]
        replay["expected"] = {
            "completed": True,
            "result": {
                "stage": "completed",
                "name": None,
                "greeting": "Hello, Ada!",
                "approved": False,
                "version": -1,
                "version_result": None,
                "reservation_id": None,
                "events": ["activity:Hello, Ada!"],
            },
            "commands": [{"type": "complete_workflow"}],
        }
        return replay

    @classmethod
    def official_command_replay_fixture(cls) -> dict[str, Any]:
        replay = cls.official_history_replay_fixture()
        del replay["history"]
        replay["command_sequence"] = [
            {
                "completed": False,
                "result": None,
                "commands": [
                    {
                        "type": "schedule_activity",
                        "activity_type": "Tests\\Fixtures\\V2\\TestGreetingActivity",
                    }
                ],
                "resume_with": "Hello, Ada!",
            },
            json.loads(json.dumps(replay["expected"])),
        ]
        return replay

    def git(self, *arguments: str) -> subprocess.CompletedProcess[str]:
        result = run("git", *arguments, cwd=self.root)
        if result.returncode != 0:
            self.fail(
                f"git command failed: {arguments!r}\n{result.stdout}\n{result.stderr}"
            )
        return result

    def read_policy(self) -> dict[str, Any]:
        return json.loads(
            (self.root / "regression-corpus-policy.json").read_text(encoding="utf-8")
        )

    def write_json(self, relative_path: str, value: dict[str, Any]) -> None:
        target = self.root / relative_path
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_text(json.dumps(value, indent=2) + "\n", encoding="utf-8")

    def validate(
        self,
        *,
        env: dict[str, str] | None = None,
    ) -> subprocess.CompletedProcess[str]:
        return run(
            sys.executable,
            str(VALIDATOR),
            "--root",
            str(self.root),
            "--base-ref",
            self.base_ref,
            cwd=self.root,
            env=env,
        )

    def commit_current_as_base(self) -> None:
        self.git("add", "--all")
        self.git(
            "-c",
            "user.name=Regression Corpus Test",
            "-c",
            "user.email=regression-corpus@example.invalid",
            "commit",
            "--quiet",
            "--message=expanded-baseline",
        )
        self.base_ref = self.git("rev-parse", "HEAD").stdout.strip()

    def test_fixture_deletion_cannot_hide_behind_weakened_inventory(self) -> None:
        (self.root / "tests/Fixtures/V2/CodecRegression/base.json").unlink()
        policy = self.read_policy()
        policy["categories"]["codec"]["fixtures"] = [
            policy["categories"]["codec"]["fixtures"][0]
        ]
        self.write_json("regression-corpus-policy.json", policy)

        result = self.validate()

        self.assertNotEqual(0, result.returncode, result.stdout)
        self.assertIn(
            "categories.codec.fixtures cannot remove or change a base selector",
            result.stderr,
        )

    def test_codec_change_cannot_hide_behind_weakened_guard(self) -> None:
        (self.root / "src/Serializers/Json.php").write_text(
            "<?php\nreturn 'changed';\n",
            encoding="utf-8",
        )
        policy = self.read_policy()
        policy["categories"]["codec"]["guards"] = [
            guard
            for guard in policy["categories"]["codec"]["guards"]
            if guard["glob"] != "src/Serializers/*.php"
        ]
        self.write_json("regression-corpus-policy.json", policy)

        result = self.validate()

        self.assertNotEqual(0, result.returncode, result.stdout)
        self.assertIn(
            "categories.codec.guards cannot remove or change a base selector",
            result.stderr,
        )

    def test_selector_expansion_cannot_manufacture_growth_from_existing_json(
        self,
    ) -> None:
        (self.root / "src/Serializers/Json.php").write_text(
            "<?php\nreturn 'changed';\n",
            encoding="utf-8",
        )
        policy = self.read_policy()
        policy["categories"]["codec"]["fixtures"].append(
            {
                "glob": "tests/Evidence/existing-codec.json",
                "format": "codec-regression-v1",
            }
        )
        self.write_json("regression-corpus-policy.json", policy)

        result = self.validate()

        self.assertNotEqual(0, result.returncode, result.stdout)
        self.assertIn(
            "is not bound to this repository's official php consumer",
            result.stderr,
        )

    def test_codec_change_accepts_minimal_counterfactual_fixture(self) -> None:
        (self.root / "src/Serializers/Json.php").write_text(
            "<?php\nreturn 'changed';\n",
            encoding="utf-8",
        )
        self.write_json(
            "tests/Fixtures/V2/CodecRegression/new.json",
            self.codec_fixture("requires-change-codec-case", "2", "BA=="),
        )

        result = self.validate()

        self.assertEqual(0, result.returncode, result.stderr)
        counts = json.loads(result.stdout)["counts"]["codec"]
        self.assertEqual(1, counts["base"])
        self.assertEqual(2, counts["current"])
        self.assertEqual(1, counts["new_fixture_evidence"])
        self.assertEqual(1, counts["counterfactual_fixture_paths"])
        self.assertTrue(counts["related_change"])

    def test_already_passing_fixture_cannot_prove_guarded_regression(self) -> None:
        (self.root / "src/Serializers/Json.php").write_text(
            "<?php\nreturn 'changed';\n",
            encoding="utf-8",
        )
        self.write_json(
            "tests/Fixtures/V2/CodecRegression/unrelated.json",
            self.codec_fixture("unrelated-already-passing-case", "2", "BA=="),
        )

        result = self.validate()

        self.assertNotEqual(0, result.returncode, result.stdout)
        self.assertIn(
            "passes the official php codec-regression-v1 consumer at both base "
            "and current revisions",
            result.stderr,
        )

    def test_replay_selector_cannot_escape_the_official_consumer(self) -> None:
        (self.root / "src/V2/Support/WorkflowFiberRunner.php").write_text(
            "<?php\nreturn 'changed';\n",
            encoding="utf-8",
        )
        policy = self.read_policy()
        policy["categories"]["replay"]["fixtures"].append(
            {
                "glob": "tests/Evidence/*.json",
                "format": "replay-regression-v1",
            }
        )
        self.write_json("regression-corpus-policy.json", policy)
        self.write_json(
            "tests/Evidence/new-replay.json",
            self.replay_fixture(
                "inert-replay-case",
                workflow_type="NoSuch\\Workflow",
            ),
        )

        result = self.validate()

        self.assertNotEqual(0, result.returncode, result.stdout)
        self.assertIn(
            "is not bound to this repository's official php consumer",
            result.stderr,
        )

    def test_current_embedded_harness_exercises_merge_base_runtime(self) -> None:
        (
            self.root / "src/V2/Support/QueryStateReplayer.php"
        ).write_text(
            "<?php\nreturn 'changed';\n",
            encoding="utf-8",
        )
        harness = (
            self.root
            / "tests/Feature/V2/V2EmbeddedReplayRegressionCorpusTest.php"
        )
        harness.parent.mkdir(parents=True, exist_ok=True)
        harness.write_text(
            "<?php\n// query-state-replayer\n",
            encoding="utf-8",
        )
        fixture = self.replay_fixture("requires-query-change-replay-case")
        fixture["workflow"]["arguments"] = ["embedded"]
        fixture["consumers"] = [
            "workflow-fiber-runner",
            "query-state-replayer",
        ]
        self.write_json(
            "tests/Fixtures/V2/ReplayRegression/embedded.json",
            fixture,
        )

        result = self.validate()

        self.assertEqual(0, result.returncode, result.stderr)
        counts = json.loads(result.stdout)["counts"]["replay"]
        self.assertEqual(1, counts["counterfactual_fixture_paths"])

    def test_codec_binding_metadata_cannot_manufacture_growth(self) -> None:
        (self.root / "src/Serializers/Json.php").write_text(
            "<?php\nreturn 'changed';\n",
            encoding="utf-8",
        )
        self.write_json(
            "tests/Fixtures/V2/CodecRegression/metadata-rewrap.json",
            self.codec_fixture(
                "metadata-rewrapped-codec-case",
                "0",
                "AA==",
                bindings=["rust", "php"],
            ),
        )

        result = self.validate()

        self.assertNotEqual(0, result.returncode, result.stdout)
        self.assertIn("duplicate semantic fixtures", result.stderr)

    def test_encode_reject_wire_cannot_manufacture_guarded_growth(self) -> None:
        rejected_value = {"type": "double", "value": "1e309"}
        self.write_json(
            "tests/Fixtures/V2/CodecRegression/base.json",
            self.codec_fixture(
                "base-encode-reject",
                "",
                None,
                tagged_value=rejected_value,
                operation="encode_reject",
                error="non_finite_float",
            ),
        )
        self.commit_current_as_base()
        (self.root / "src/Serializers/Json.php").write_text(
            "<?php\nreturn 'changed';\n",
            encoding="utf-8",
        )

        duplicate_path = "tests/Fixtures/V2/CodecRegression/wire-only-growth.json"
        for wire in ("AA==", "AQ=="):
            with self.subTest(wire=wire):
                self.write_json(
                    duplicate_path,
                    self.codec_fixture(
                        f"wire-only-encode-reject-{wire}",
                        "",
                        wire,
                        tagged_value=rejected_value,
                        operation="encode_reject",
                        error="non_finite_float",
                    ),
                )

                try:
                    result = self.validate()

                    self.assertNotEqual(0, result.returncode, result.stdout)
                    self.assertIn("duplicate semantic fixtures", result.stderr)
                finally:
                    (self.root / duplicate_path).unlink(missing_ok=True)

    def test_encode_reject_values_and_error_policies_remain_distinct(self) -> None:
        self.write_json(
            "tests/Fixtures/V2/CodecRegression/base.json",
            self.codec_fixture(
                "base-encode-reject",
                "",
                None,
                tagged_value={"type": "double", "value": "1e309"},
                operation="encode_reject",
                error="non_finite_float",
            ),
        )
        self.commit_current_as_base()
        self.write_json(
            "tests/Fixtures/V2/CodecRegression/different-value.json",
            self.codec_fixture(
                "different-rejected-value",
                "",
                None,
                tagged_value={"type": "double", "value": "-1e309"},
                operation="encode_reject",
                error="non_finite_float",
            ),
        )
        self.write_json(
            "tests/Fixtures/V2/CodecRegression/different-policy.json",
            self.codec_fixture(
                "different-rejection-policy",
                "",
                None,
                tagged_value={"type": "double", "value": "1e309"},
                operation="encode_reject",
                error="Avro Value doubles must be finite",
            ),
        )

        result = self.validate()

        self.assertEqual(0, result.returncode, result.stderr)
        counts = json.loads(result.stdout)["counts"]["codec"]
        self.assertEqual(1, counts["base"])
        self.assertEqual(3, counts["current"])

    def test_wire_consuming_codec_operations_preserve_wire_identity(self) -> None:
        self.write_json(
            "tests/Fixtures/V2/CodecRegression/round-trip-alternate-wire.json",
            self.codec_fixture(
                "round-trip-alternate-wire",
                "0",
                "AQ==",
            ),
        )
        for identity, wire in (
            ("decode-reject-short", "AQ=="),
            ("decode-reject-alternate", "Ag=="),
        ):
            self.write_json(
                f"tests/Fixtures/V2/CodecRegression/{identity}.json",
                self.codec_fixture(
                    identity,
                    "",
                    wire,
                    operation="decode_reject",
                    error="invalid_payload_framing",
                ),
            )

        result = self.validate()

        self.assertEqual(0, result.returncode, result.stderr)
        counts = json.loads(result.stdout)["counts"]["codec"]
        self.assertEqual(1, counts["base"])
        self.assertEqual(4, counts["current"])

    def test_replay_binding_metadata_cannot_manufacture_growth(self) -> None:
        (self.root / "src/V2/Support/WorkflowFiberRunner.php").write_text(
            "<?php\nreturn 'changed';\n",
            encoding="utf-8",
        )
        self.write_json(
            "tests/Fixtures/V2/ReplayRegression/metadata-rewrap.json",
            self.replay_fixture(
                "metadata-rewrapped-replay-case",
                bindings=["rust", "php"],
            ),
        )

        result = self.validate()

        self.assertNotEqual(0, result.returncode, result.stdout)
        self.assertIn("duplicate semantic fixtures", result.stderr)

    def test_replay_protocol_version_relabel_cannot_manufacture_growth(self) -> None:
        (self.root / "src/V2/Support/WorkflowFiberRunner.php").write_text(
            "<?php\nreturn 'changed';\n",
            encoding="utf-8",
        )
        self.write_json(
            "tests/Fixtures/V2/ReplayRegression/version-relabel.json",
            self.replay_fixture(
                "version-relabeled-replay-case",
                protocol_version="999",
            ),
        )

        result = self.validate()

        self.assertNotEqual(0, result.returncode, result.stdout)
        self.assertIn("duplicate semantic fixtures", result.stderr)

    def test_positive_json_replay_cannot_be_preserved_beside_an_avro_copy(self) -> None:
        legacy = self.official_history_replay_fixture()
        legacy["id"] = "frozen-json-replay"
        legacy["workflow"]["payload_codec"] = "json"
        legacy["history"][1]["payload"].update(
            {
                "result": '"Hello, Ada!"',
                "payload_codec": "json",
            }
        )
        self.write_json(
            "tests/Fixtures/V2/ReplayRegression/base.json",
            legacy,
        )
        self.commit_current_as_base()

        successor = self.official_history_replay_fixture()
        successor["id"] = "frozen-json-replay-avro"
        self.write_json(
            "tests/Fixtures/V2/ReplayRegression/base-avro.json",
            successor,
        )

        result = self.validate()

        self.assertNotEqual(0, result.returncode, result.stdout)
        self.assertIn(
            "JSON-tagged replay evidence must declare expected_failure.type=unsupported_payload_codec",
            result.stderr,
        )

    def test_golden_history_rewrap_cannot_manufacture_growth(self) -> None:
        self.write_json(
            "tests/Fixtures/V2/ReplayRegression/base.json",
            self.official_history_replay_fixture(),
        )
        self.commit_current_as_base()
        self.write_json(
            "tests/Fixtures/V2/GoldenHistory/rewrapped.json",
            self.golden_history_fixture(),
        )

        result = self.validate()

        self.assertNotEqual(0, result.returncode, result.stdout)
        self.assertIn("duplicate semantic fixtures", result.stderr)

    def test_numeric_sequence_aliases_cannot_manufacture_replay_growth(self) -> None:
        self.write_json(
            "tests/Fixtures/V2/ReplayRegression/base.json",
            self.official_history_replay_fixture(),
        )
        self.commit_current_as_base()

        for sequence in ("7", "7.0", "7e0"):
            with self.subTest(sequence=sequence):
                duplicate = self.official_history_replay_fixture()
                duplicate["id"] = f"sequence-alias-{sequence}"
                duplicate["history"][1]["payload"]["sequence"] = sequence
                self.write_json(
                    "tests/Fixtures/V2/ReplayRegression/sequence-alias.json",
                    duplicate,
                )

                result = self.validate()

                self.assertNotEqual(0, result.returncode, result.stdout)
                self.assertIn("duplicate semantic fixtures", result.stderr)
                (
                    self.root / "tests/Fixtures/V2/ReplayRegression/sequence-alias.json"
                ).unlink()

    def test_overflowing_numeric_sequence_cannot_manufacture_growth(self) -> None:
        base = self.official_history_replay_fixture()
        base["history"][1]["payload"]["sequence"] = 0
        self.write_json(
            "tests/Fixtures/V2/ReplayRegression/base.json",
            base,
        )
        self.commit_current_as_base()
        duplicate = self.official_history_replay_fixture()
        duplicate["id"] = "overflowing-sequence-alias"
        duplicate["history"][1]["payload"]["sequence"] = "1e309"
        self.write_json(
            "tests/Fixtures/V2/ReplayRegression/overflowing-sequence.json",
            duplicate,
        )

        result = self.validate()

        self.assertNotEqual(0, result.returncode, result.stdout)
        self.assertIn("duplicate semantic fixtures", result.stderr)

    def test_ignored_activity_metadata_cannot_manufacture_growth(self) -> None:
        self.write_json(
            "tests/Fixtures/V2/ReplayRegression/base.json",
            self.official_history_replay_fixture(),
        )
        self.commit_current_as_base()

        variants = (
            ("payload", "activity_type", "RepresentationOnlyActivity"),
            ("payload", "corpus_note", "representation only"),
            ("event", "id", "representation-only-event-id"),
        )
        for location, field, value in variants:
            with self.subTest(location=location, field=field):
                duplicate = self.official_history_replay_fixture()
                duplicate["id"] = f"ignored-activity-{field}"
                target = (
                    duplicate["history"][1]["payload"]
                    if location == "payload"
                    else duplicate["history"][1]
                )
                target[field] = value
                self.write_json(
                    "tests/Fixtures/V2/ReplayRegression/ignored-metadata.json",
                    duplicate,
                )

                result = self.validate()

                self.assertNotEqual(0, result.returncode, result.stdout)
                self.assertIn("duplicate semantic fixtures", result.stderr)
                (
                    self.root
                    / "tests/Fixtures/V2/ReplayRegression/ignored-metadata.json"
                ).unlink()

    def test_condition_wait_occurrences_have_distinct_replay_identity(self) -> None:
        base = self.replay_fixture("condition-wait-occurrence-base")
        base["history"].append(
            {
                "event_type": "ConditionWaitOpened",
                "payload": {
                    "sequence": 1,
                    "condition_wait_id": "condition:1",
                    "condition_wait_occurrence_id": "rust:condition-wait:0",
                    "condition_key": "approval.ready",
                    "condition_definition_fingerprint": "condition-fp",
                },
            }
        )
        self.write_json("tests/Fixtures/V2/ReplayRegression/base.json", base)
        self.commit_current_as_base()

        adjacent = json.loads(json.dumps(base))
        adjacent["id"] = "condition-wait-occurrence-adjacent"
        adjacent["history"][1]["payload"]["condition_wait_occurrence_id"] = (
            "rust:condition-wait:1"
        )
        self.write_json(
            "tests/Fixtures/V2/ReplayRegression/adjacent.json",
            adjacent,
        )

        result = self.validate()

        self.assertEqual(0, result.returncode, result.stderr)
        counts = json.loads(result.stdout)["counts"]["replay"]
        self.assertEqual(1, counts["base"])
        self.assertEqual(2, counts["current"])

    def test_shadowed_event_namespace_cannot_manufacture_growth(self) -> None:
        base = self.official_history_replay_fixture()
        base["history"][0]["payload"]["namespace"] = "effective-namespace"
        self.write_json(
            "tests/Fixtures/V2/ReplayRegression/base.json",
            base,
        )
        self.commit_current_as_base()
        duplicate = self.official_history_replay_fixture()
        duplicate["id"] = "shadowed-event-namespace"
        duplicate["history"][0]["payload"]["namespace"] = "effective-namespace"
        duplicate["history"][1]["namespace"] = "representation-only-namespace"
        self.write_json(
            "tests/Fixtures/V2/ReplayRegression/shadowed-namespace.json",
            duplicate,
        )

        result = self.validate()

        self.assertNotEqual(0, result.returncode, result.stdout)
        self.assertIn("duplicate semantic fixtures", result.stderr)

    def test_effective_namespace_representations_cannot_manufacture_growth(
        self,
    ) -> None:
        base = self.official_history_replay_fixture()
        base["history"][0]["payload"]["namespace"] = "effective-namespace"
        self.write_json(
            "tests/Fixtures/V2/ReplayRegression/base.json",
            base,
        )
        self.commit_current_as_base()
        duplicate = self.official_history_replay_fixture()
        duplicate["id"] = "event-namespace-fallback"
        duplicate["history"][0]["namespace"] = "effective-namespace"
        self.write_json(
            "tests/Fixtures/V2/ReplayRegression/event-namespace-fallback.json",
            duplicate,
        )

        result = self.validate()

        self.assertNotEqual(0, result.returncode, result.stdout)
        self.assertIn("duplicate semantic fixtures", result.stderr)

    def test_expected_command_subset_cannot_manufacture_growth(self) -> None:
        self.write_json(
            "tests/Fixtures/V2/ReplayRegression/base.json",
            self.official_history_replay_fixture(),
        )
        self.commit_current_as_base()
        duplicate = self.official_history_replay_fixture()
        duplicate["id"] = "expected-command-subset-rewrap"
        duplicate["expected"]["commands"][0]["payload_codec"] = "avro"
        self.write_json(
            "tests/Fixtures/V2/ReplayRegression/expected-command-subset.json",
            duplicate,
        )

        result = self.validate()

        self.assertNotEqual(0, result.returncode, result.stdout)
        self.assertIn("duplicate semantic fixtures", result.stderr)

    def test_command_sequence_subset_cannot_manufacture_growth(self) -> None:
        self.write_json(
            "tests/Fixtures/V2/ReplayRegression/base.json",
            self.official_command_replay_fixture(),
        )
        self.commit_current_as_base()
        duplicate = self.official_command_replay_fixture()
        duplicate["id"] = "command-sequence-subset-rewrap"
        duplicate["command_sequence"][0]["commands"][0]["payload_codec"] = "avro"
        self.write_json(
            "tests/Fixtures/V2/ReplayRegression/command-sequence-subset.json",
            duplicate,
        )

        result = self.validate()

        self.assertNotEqual(0, result.returncode, result.stdout)
        self.assertIn("duplicate semantic fixtures", result.stderr)

    def test_inline_payload_envelopes_cannot_manufacture_replay_growth(self) -> None:
        payload_fields = (
            ("ActivityCompleted", "result", "wwHioz3/VYAiNwoeYWN0aXZpdHktcmVzdWx0", "avro"),
            ("SideEffectRecorded", "result", "wwHioz3/VYAiNw4CDHNvdXJjZQoOaGlzdG9yeQA=", "avro"),
            ("SignalReceived", "arguments", "wwHioz3/VYAiNwwCCh5yZWNlaXZlZC1zaWduYWwA", "avro"),
            ("SignalApplied", "value", "wwHioz3/VYAiNwocYXBwbGllZC1zaWduYWw=", "avro"),
            ("SignalApplied", "arguments", "wwHioz3/VYAiNwwCCiJhcHBsaWVkLWFyZ3VtZW50cwA=", "avro"),
            ("UpdateAccepted", "arguments", "wwHioz3/VYAiNwwCAgEA", "avro"),
            ("UpdateApplied", "arguments", "wwHioz3/VYAiNwwCAgEA", "avro"),
            ("ChildRunCompleted", "output", "wwHioz3/VYAiNwoYY2hpbGQtb3V0cHV0", "avro"),
            ("ServiceCallStarted", "response_payload", "wwHioz3/VYAiNw4CEGFjY2VwdGVkAgEA", "avro"),
            ("ServiceCallCompleted", "response_payload", "wwHioz3/VYAiNw4CEmNvbXBsZXRlZAIBAA==", "avro"),
            (
                "ActivityCompleted",
                "result",
                "wwHioz3/VYAiNw4CCnZhbHVlBFQA",
                "avro",
            ),
        )

        for event_type, field, blob, codec in payload_fields:
            with self.subTest(event_type=event_type, field=field, codec=codec):
                base = self.official_history_replay_fixture()
                base["history"][1]["event_type"] = event_type
                base["history"][1]["payload"] = {
                    "sequence": 7,
                    "payload_codec": codec,
                    field: blob,
                }
                self.write_json(
                    "tests/Fixtures/V2/ReplayRegression/base.json",
                    base,
                )
                self.commit_current_as_base()

                duplicate = json.loads(json.dumps(base))
                duplicate["id"] = f"inline-envelope-{event_type}-{field}-{codec}"
                duplicate_payload = duplicate["history"][1]["payload"]
                duplicate_payload.pop("payload_codec")
                duplicate_payload[field] = {
                    "codec": codec,
                    "blob": blob,
                }
                duplicate_path = (
                    "tests/Fixtures/V2/ReplayRegression/inline-envelope.json"
                )
                self.write_json(duplicate_path, duplicate)

                try:
                    result = self.validate()

                    self.assertNotEqual(0, result.returncode, result.stdout)
                    self.assertIn("duplicate semantic fixtures", result.stderr)
                finally:
                    (self.root / duplicate_path).unlink(missing_ok=True)

    def test_codec_reencoding_cannot_manufacture_replay_growth(self) -> None:
        base = self.official_history_replay_fixture()
        base["history"][1]["payload"]["result"] = (
            "wwHioz3/VYAiNwoeYWN0aXZpdHktcmVzdWx0"
        )
        self.write_json(
            "tests/Fixtures/V2/ReplayRegression/base.json",
            base,
        )
        self.commit_current_as_base()

        duplicate = json.loads(json.dumps(base))
        duplicate["id"] = "avro-reencoded-activity-result"
        duplicate_payload = duplicate["history"][1]["payload"]
        duplicate_payload.pop("payload_codec")
        duplicate_payload["result"] = {
            "codec": "avro",
            "blob": "wwHioz3/VYAiNwoeYWN0aXZpdHktcmVzdWx0",
        }
        self.write_json(
            "tests/Fixtures/V2/ReplayRegression/avro-reencoded.json",
            duplicate,
        )

        result = self.validate()

        self.assertNotEqual(0, result.returncode, result.stdout)
        self.assertIn("duplicate semantic fixtures", result.stderr)

    def test_alternate_avro_map_orders_grow_replay_evidence(
        self,
    ) -> None:
        wires = self.shared_avro_fixture()["alternate_map_orders"][0]["wire_base64"]
        payload_fields = (
            ("ActivityCompleted", "result"),
            ("SideEffectRecorded", "result"),
            ("SignalReceived", "arguments"),
            ("SignalApplied", "value"),
            ("SignalApplied", "arguments"),
            ("UpdateAccepted", "arguments"),
            ("UpdateApplied", "arguments"),
            ("ChildRunCompleted", "output"),
            ("ServiceCallStarted", "response_payload"),
            ("ServiceCallCompleted", "response_payload"),
        )

        for event_type, field in payload_fields:
            with self.subTest(event_type=event_type, field=field):
                base = self.official_history_replay_fixture()
                base["history"][1]["event_type"] = event_type
                base["history"][1]["payload"] = {
                    "sequence": 7,
                    "payload_codec": "avro",
                    field: wires[0],
                }
                self.write_json(
                    "tests/Fixtures/V2/ReplayRegression/base.json",
                    base,
                )
                self.commit_current_as_base()

                duplicate = json.loads(json.dumps(base))
                duplicate["id"] = f"alternate-avro-map-order-{event_type}-{field}"
                duplicate["history"][1]["payload"][field] = wires[1]
                duplicate_path = (
                    "tests/Fixtures/V2/ReplayRegression/alternate-map-order.json"
                )
                self.write_json(duplicate_path, duplicate)

                try:
                    result = self.validate()

                    self.assertEqual(0, result.returncode, result.stderr)
                    counts = json.loads(result.stdout)["counts"]["replay"]
                    self.assertEqual(1, counts["base"])
                    self.assertEqual(2, counts["current"])
                finally:
                    (self.root / duplicate_path).unlink(missing_ok=True)

    def test_alternate_inline_object_orders_grow_replay_evidence(self) -> None:
        base = self.official_history_replay_fixture()
        base["history"][1]["event_type"] = "ServiceCallCompleted"
        base["history"][1]["payload"] = {
            "sequence": 7,
            "response_payload": {
                "outer": {"left": 1, "right": "x"},
                "tail": "done",
            },
        }
        self.write_json(
            "tests/Fixtures/V2/ReplayRegression/base.json",
            base,
        )
        self.commit_current_as_base()

        changed = json.loads(json.dumps(base))
        changed["id"] = "alternate-inline-object-order"
        changed["history"][1]["payload"]["response_payload"] = {
            "tail": "done",
            "outer": {"right": "x", "left": 1},
        }
        self.write_json(
            "tests/Fixtures/V2/ReplayRegression/alternate-inline-order.json",
            changed,
        )

        result = self.validate()

        self.assertEqual(0, result.returncode, result.stderr)
        counts = json.loads(result.stdout)["counts"]["replay"]
        self.assertEqual(1, counts["base"])
        self.assertEqual(2, counts["current"])

    def test_different_decoded_avro_values_grow_replay_evidence(self) -> None:
        golden = self.shared_avro_fixture()
        alternate_wire = golden["alternate_map_orders"][0]["wire_base64"][0]
        different_wire = next(
            case["wire_base64"]
            for case in golden["cases"]
            if case["name"] == "nested"
        )
        base = self.official_history_replay_fixture()
        base["history"][1]["payload"] = {
            "sequence": 7,
            "payload_codec": "avro",
            "result": alternate_wire,
        }
        self.write_json(
            "tests/Fixtures/V2/ReplayRegression/base.json",
            base,
        )
        self.commit_current_as_base()

        changed = json.loads(json.dumps(base))
        changed["id"] = "different-decoded-avro-result"
        changed["history"][1]["payload"]["result"] = different_wire
        self.write_json(
            "tests/Fixtures/V2/ReplayRegression/different-avro-result.json",
            changed,
        )

        result = self.validate()

        self.assertEqual(0, result.returncode, result.stderr)
        counts = json.loads(result.stdout)["counts"]["replay"]
        self.assertEqual(1, counts["base"])
        self.assertEqual(2, counts["current"])

    def test_malformed_raw_avro_payload_fails_closed(self) -> None:
        malformed_wire = next(
            frame["wire_base64"]
            for frame in self.shared_avro_fixture()["malformed_frames"]
            if frame["name"] == "trailing_bytes"
        )
        fixture = self.official_history_replay_fixture()
        fixture["id"] = "malformed-raw-avro-result"
        fixture["history"][1]["payload"] = {
            "sequence": 7,
            "payload_codec": "avro",
            "result": malformed_wire,
        }
        self.write_json(
            "tests/Fixtures/V2/ReplayRegression/malformed-raw-avro.json",
            fixture,
        )

        result = self.validate()

        self.assertNotEqual(0, result.returncode, result.stdout)
        self.assertIn(
            "cannot be decoded by the PHP payload consumer",
            result.stderr,
        )

    def test_unavailable_php_payload_consumer_fails_closed(self) -> None:
        fixture = self.official_history_replay_fixture()
        fixture["id"] = "consumer-unavailable"
        self.write_json(
            "tests/Fixtures/V2/ReplayRegression/consumer-unavailable.json",
            fixture,
        )
        fake_bin = self.root / "bin"
        fake_bin.mkdir()
        php = fake_bin / "php"
        php.write_text(
            "#!/bin/sh\n"
            "echo 'PHP replay payload consumer is unavailable' >&2\n"
            "exit 127\n",
            encoding="utf-8",
        )
        php.chmod(0o755)
        env = dict(os.environ)
        env["PATH"] = f"{fake_bin}{os.pathsep}{env.get('PATH', '')}"

        result = self.validate(env=env)

        self.assertNotEqual(0, result.returncode, result.stdout)
        self.assertIn(
            "cannot be decoded by the PHP payload consumer",
            result.stderr,
        )
        self.assertIn("consumer is unavailable", result.stderr)

    def test_json_envelope_is_rejected_even_with_avro_workflow_codec(self) -> None:
        base = self.official_history_replay_fixture()
        base["workflow"]["payload_codec"] = "avro"
        self.write_json(
            "tests/Fixtures/V2/ReplayRegression/base.json",
            base,
        )
        self.commit_current_as_base()

        duplicate = json.loads(json.dumps(base))
        duplicate["id"] = "envelope-codec-precedes-workflow-fallback"
        duplicate_payload = duplicate["history"][1]["payload"]
        duplicate_payload.pop("payload_codec")
        duplicate_payload["result"] = {
            "codec": "json",
            "blob": '"Hello, Ada!"',
        }
        self.write_json(
            "tests/Fixtures/V2/ReplayRegression/envelope-codec-precedence.json",
            duplicate,
        )

        result = self.validate()

        self.assertNotEqual(0, result.returncode, result.stdout)
        self.assertIn("unsupported_payload_codec", result.stderr)

    def test_workflow_avro_codec_is_the_payload_fallback(self) -> None:
        base = self.official_history_replay_fixture()
        base["history"][1]["payload"].pop("payload_codec")
        self.write_json(
            "tests/Fixtures/V2/ReplayRegression/base.json",
            base,
        )
        self.commit_current_as_base()

        duplicate = json.loads(json.dumps(base))
        duplicate["id"] = "workflow-codec-fallback-envelope"
        duplicate["history"][1]["payload"]["result"] = {
            "blob": "wwHioz3/VYAiNwoWSGVsbG8sIEFkYSE=",
        }
        self.write_json(
            "tests/Fixtures/V2/ReplayRegression/fallback-codec.json",
            duplicate,
        )

        result = self.validate()

        self.assertNotEqual(0, result.returncode, result.stdout)
        self.assertIn("duplicate semantic fixtures", result.stderr)

    def test_direct_domain_service_responses_match_encoded_identity(self) -> None:
        direct_responses = (
            ("codec", {"codec": "preferred", "accepted": True}),
            ("blob", {"blob": {"domain": "value"}, "accepted": True}),
            (
                "external-storage",
                {"external_storage": "inline", "accepted": True},
            ),
        )
        encoded_responses = {
            "ServiceCallStarted-codec": "wwHioz3/VYAiNw4GCmNvZGVjChJwcmVmZXJyZWQQYWNjZXB0ZWQCARBpZGVudGl0eQowU2VydmljZUNhbGxTdGFydGVkLWNvZGVjAA==",
            "ServiceCallStarted-blob": "wwHioz3/VYAiNw4GCGJsb2IOAgxkb21haW4KCnZhbHVlABBhY2NlcHRlZAIBEGlkZW50aXR5Ci5TZXJ2aWNlQ2FsbFN0YXJ0ZWQtYmxvYgA=",
            "ServiceCallStarted-external-storage": "wwHioz3/VYAiNw4GIGV4dGVybmFsX3N0b3JhZ2UKDGlubGluZRBhY2NlcHRlZAIBEGlkZW50aXR5CkZTZXJ2aWNlQ2FsbFN0YXJ0ZWQtZXh0ZXJuYWwtc3RvcmFnZQA=",
            "ServiceCallCompleted-codec": "wwHioz3/VYAiNw4GCmNvZGVjChJwcmVmZXJyZWQQYWNjZXB0ZWQCARBpZGVudGl0eQo0U2VydmljZUNhbGxDb21wbGV0ZWQtY29kZWMA",
            "ServiceCallCompleted-blob": "wwHioz3/VYAiNw4GCGJsb2IOAgxkb21haW4KCnZhbHVlABBhY2NlcHRlZAIBEGlkZW50aXR5CjJTZXJ2aWNlQ2FsbENvbXBsZXRlZC1ibG9iAA==",
            "ServiceCallCompleted-external-storage": "wwHioz3/VYAiNw4GIGV4dGVybmFsX3N0b3JhZ2UKDGlubGluZRBhY2NlcHRlZAIBEGlkZW50aXR5CkpTZXJ2aWNlQ2FsbENvbXBsZXRlZC1leHRlcm5hbC1zdG9yYWdlAA==",
        }
        fixtures = []

        for event_type in ("ServiceCallStarted", "ServiceCallCompleted"):
            for field, response in direct_responses:
                fixture = self.official_history_replay_fixture()
                identity = f"{event_type}-{field}"
                fixture["id"] = f"direct-service-response-{identity}"
                direct = json.loads(json.dumps(response))
                direct["identity"] = identity
                fixture["history"][1]["event_type"] = event_type
                fixture["history"][1]["payload"] = {
                    "sequence": 7,
                    "response_payload": direct,
                }
                path = (
                    "tests/Fixtures/V2/ReplayRegression/"
                    f"direct-service-response-{identity}.json"
                )
                self.write_json(path, fixture)
                fixtures.append((fixture, identity))

        self.commit_current_as_base()

        for fixture, identity in fixtures:
            duplicate = json.loads(json.dumps(fixture))
            duplicate["id"] = f"encoded-service-response-{identity}"
            duplicate["history"][1]["payload"]["response_payload"] = {
                "codec": "avro",
                "blob": encoded_responses[identity],
            }
            self.write_json(
                (
                    "tests/Fixtures/V2/ReplayRegression/"
                    f"encoded-service-response-{identity}.json"
                ),
                duplicate,
            )

        result = self.validate()

        self.assertNotEqual(0, result.returncode, result.stdout)
        self.assertIn("duplicate semantic fixtures", result.stderr)
        for _, identity in fixtures:
            self.assertIn(
                f"direct-service-response-{identity}.json",
                result.stderr,
            )
            self.assertIn(
                f"encoded-service-response-{identity}.json",
                result.stderr,
            )

    def test_malformed_service_response_envelopes_are_rejected(self) -> None:
        malformed_values = (
            (
                {"codec": "avro", "blob": "not-an-avro-frame"},
                "cannot be decoded by the PHP payload consumer",
            ),
            (
                {"codec": "avro", "external_storage": {"key": "payload"}},
                "not a decodable inline payload envelope",
            ),
        )

        for event_type in ("ServiceCallStarted", "ServiceCallCompleted"):
            for index, (malformed, expected_error) in enumerate(malformed_values):
                with self.subTest(event_type=event_type, index=index):
                    fixture = self.official_history_replay_fixture()
                    fixture["id"] = f"malformed-{event_type}-{index}"
                    fixture["history"][1]["event_type"] = event_type
                    fixture["history"][1]["payload"] = {
                        "sequence": 7,
                        "response_payload": malformed,
                    }
                    path = (
                        "tests/Fixtures/V2/ReplayRegression/"
                        "malformed-service-response.json"
                    )
                    self.write_json(path, fixture)

                    try:
                        result = self.validate()

                        self.assertNotEqual(0, result.returncode, result.stdout)
                        self.assertIn(expected_error, result.stderr)
                    finally:
                        (self.root / path).unlink(missing_ok=True)

    def test_expected_malformed_service_response_failure_grows_replay_corpus(self) -> None:
        fixture = self.official_history_replay_fixture()
        fixture["id"] = "malformed-service-response-requires-change"
        fixture["history"][1]["event_type"] = "ServiceCallCompleted"
        fixture["history"][1]["payload"] = {
            "sequence": 7,
            "response_payload": {
                "codec": "avro",
                "blob": "not-an-avro-frame",
            },
        }
        del fixture["expected"]
        fixture["expected_failure"] = {
            "type": "malformed_service_response_envelope",
            "exception": "Workflow\\Serializers\\CodecDecodeException",
        }
        self.write_json(
            "tests/Fixtures/V2/ReplayRegression/malformed-service-response.json",
            fixture,
        )
        (self.root / "src/V2/Support/WorkflowFiberRunner.php").write_text(
            "<?php\nreturn 'changed';\n",
            encoding="utf-8",
        )

        result = self.validate()

        self.assertEqual(0, result.returncode, result.stderr)
        counts = json.loads(result.stdout)["counts"]["replay"]
        self.assertEqual(1, counts["base"])
        self.assertEqual(2, counts["current"])
        self.assertEqual(1, counts["new_fixture_evidence"])
        self.assertEqual(1, counts["counterfactual_fixture_paths"])

    def test_malformed_payload_envelopes_are_rejected(self) -> None:
        base = self.official_history_replay_fixture()
        self.write_json(
            "tests/Fixtures/V2/ReplayRegression/base.json",
            base,
        )
        self.commit_current_as_base()
        malformed_values = (
            ({"codec": "avro"}, "not a decodable inline payload envelope"),
            ({"codec": "avro", "blob": 42}, "not a decodable inline payload envelope"),
            (
                {"codec": "avro", "external_storage": {"key": "payload"}},
                "not a decodable inline payload envelope",
            ),
            (
                {"codec": "unknown", "blob": '"activity-result"'},
                "declares unsupported payload codec",
            ),
            (
                {"codec": "avro", "blob": "not-an-avro-frame"},
                "cannot be decoded by the PHP payload consumer",
            ),
        )

        for index, (malformed, expected_error) in enumerate(malformed_values):
            with self.subTest(index=index):
                fixture = json.loads(json.dumps(base))
                fixture["id"] = f"malformed-inline-envelope-{index}"
                fixture["history"][1]["payload"].pop("payload_codec")
                fixture["history"][1]["payload"]["result"] = malformed
                path = "tests/Fixtures/V2/ReplayRegression/malformed-envelope.json"
                self.write_json(path, fixture)

                try:
                    result = self.validate()

                    self.assertNotEqual(0, result.returncode, result.stdout)
                    self.assertIn(expected_error, result.stderr)
                finally:
                    (self.root / path).unlink(missing_ok=True)

    def test_nested_json_payload_codec_is_rejected_before_decode(self) -> None:
        fixture = self.official_history_replay_fixture()
        fixture["id"] = "conflicting-inline-envelope-codecs"
        fixture["history"][1]["payload"]["result"] = {
            "codec": "json",
            "blob": '"Hello, Ada!"',
        }
        self.write_json(
            "tests/Fixtures/V2/ReplayRegression/conflicting-codecs.json",
            fixture,
        )

        result = self.validate()

        self.assertNotEqual(0, result.returncode, result.stdout)
        self.assertIn("unsupported_payload_codec", result.stderr)

    def test_ignored_failure_exception_cannot_manufacture_growth(self) -> None:
        self.write_json(
            "tests/Fixtures/V2/GoldenHistory/failure-base.json",
            self.golden_failure_fixture(),
        )
        self.commit_current_as_base()
        duplicate = self.golden_failure_fixture(exception="representation only")
        duplicate["cases"][0]["name"] = "ignored-scalar-exception"
        self.write_json(
            "tests/Fixtures/V2/GoldenHistory/ignored-exception.json",
            duplicate,
        )

        result = self.validate()

        self.assertNotEqual(0, result.returncode, result.stdout)
        self.assertIn("duplicate semantic fixtures", result.stderr)

    def test_overflowing_failure_code_cannot_manufacture_growth(self) -> None:
        self.write_json(
            "tests/Fixtures/V2/GoldenHistory/failure-base.json",
            self.golden_failure_fixture(),
        )
        self.commit_current_as_base()
        duplicate = self.golden_failure_fixture(code="1e309")
        duplicate["cases"][0]["name"] = "overflowing-failure-code"
        self.write_json(
            "tests/Fixtures/V2/GoldenHistory/overflowing-failure-code.json",
            duplicate,
        )

        result = self.validate()

        self.assertNotEqual(0, result.returncode, result.stdout)
        self.assertIn("duplicate semantic fixtures", result.stderr)

    def test_failure_alias_precedence_and_invalid_values_cannot_grow_evidence(
        self,
    ) -> None:
        self.write_json(
            "tests/Fixtures/V2/GoldenHistory/failure-defaults.json",
            self.golden_failure_fixture(),
        )
        structured = self.golden_failure_fixture(
            message="outer fallback is ignored",
            exception={
                "class": "RuntimeException",
                "type": "",
                "message": "payment processor unavailable",
                "code": 17,
            },
            expected_message="payment processor unavailable",
        )
        structured["cases"][0]["name"] = "structured-child-failure"
        self.write_json(
            "tests/Fixtures/V2/GoldenHistory/failure-structured.json",
            structured,
        )
        self.commit_current_as_base()

        variants = []
        invalid = self.golden_failure_fixture(
            exception={
                "class": 123,
                "type": False,
                "message": ["not", "a", "message"],
                "code": "not-an-integer",
                "corpus_note": "representation only",
            },
        )
        invalid["cases"][0]["name"] = "invalid-exception-values"
        invalid_payload = invalid["cases"][0]["history"][2]["payload"]
        invalid_payload.update(
            {
                "exception_class": [],
                "exception_type": 123,
                "code": "not-an-integer",
            }
        )
        variants.append(("invalid", invalid))

        aliases = json.loads(json.dumps(structured))
        aliases["cases"][0]["name"] = "shadowed-failure-aliases"
        aliases["cases"][0]["history"][2]["payload"].update(
            {
                "exception_class": "LogicException",
                "exception_type": "shadowed-type",
                "message": "shadowed message",
                "code": 99,
            }
        )
        variants.append(("aliases", aliases))

        for name, duplicate in variants:
            with self.subTest(name=name):
                duplicate_path = (
                    f"tests/Fixtures/V2/GoldenHistory/failure-{name}-duplicate.json"
                )
                self.write_json(duplicate_path, duplicate)

                result = self.validate()

                self.assertNotEqual(0, result.returncode, result.stdout)
                self.assertIn("duplicate semantic fixtures", result.stderr)
                (self.root / duplicate_path).unlink()

    def test_changed_failure_message_grows_replay_evidence(self) -> None:
        self.write_json(
            "tests/Fixtures/V2/GoldenHistory/failure-base.json",
            self.golden_failure_fixture(),
        )
        self.commit_current_as_base()
        changed = self.golden_failure_fixture(message="payment gateway offline")
        changed["cases"][0]["name"] = "changed-child-failure"
        self.write_json(
            "tests/Fixtures/V2/GoldenHistory/changed-failure.json",
            changed,
        )

        result = self.validate()

        self.assertEqual(0, result.returncode, result.stderr)
        counts = json.loads(result.stdout)["counts"]["replay"]
        self.assertEqual(2, counts["base"])
        self.assertEqual(3, counts["current"])

    def test_changed_activity_result_grows_replay_evidence(self) -> None:
        self.write_json(
            "tests/Fixtures/V2/ReplayRegression/base.json",
            self.official_history_replay_fixture(),
        )
        self.commit_current_as_base()
        changed = self.official_history_replay_fixture()
        changed["id"] = "changed-activity-result"
        changed["history"][1]["payload"]["result"] = (
            "wwHioz3/VYAiNwoaSGVsbG8sIEdyYWNlIQ=="
        )
        changed["expected"]["result"]["greeting"] = "Hello, Grace!"
        changed["expected"]["result"]["events"] = ["activity:Hello, Grace!"]
        self.write_json(
            "tests/Fixtures/V2/ReplayRegression/changed-result.json",
            changed,
        )

        result = self.validate()

        self.assertEqual(0, result.returncode, result.stderr)
        counts = json.loads(result.stdout)["counts"]["replay"]
        self.assertEqual(1, counts["base"])
        self.assertEqual(2, counts["current"])

    def test_genuinely_new_replay_behavior_grows_the_corpus(self) -> None:
        fixture = self.replay_fixture(
            "new-replay-behavior",
            workflow_type="Tests\\Fixtures\\AnotherReplayWorkflow",
        )
        self.write_json(
            "tests/Fixtures/V2/ReplayRegression/new.json",
            fixture,
        )

        result = self.validate()

        self.assertEqual(0, result.returncode, result.stderr)
        counts = json.loads(result.stdout)["counts"]["replay"]
        self.assertEqual(1, counts["base"])
        self.assertEqual(2, counts["current"])

    def test_codec_schema_relabel_cannot_manufacture_growth(self) -> None:
        (self.root / "src/Serializers/Json.php").write_text(
            "<?php\nreturn 'changed';\n",
            encoding="utf-8",
        )
        self.write_json(
            "tests/Fixtures/V2/CodecRegression/schema-relabel.json",
            self.codec_fixture(
                "schema-relabeled-codec-case",
                "0",
                "AA==",
                schema="example.RelabeledValue",
            ),
        )

        result = self.validate()

        self.assertNotEqual(0, result.returncode, result.stdout)
        self.assertIn("duplicate semantic fixtures", result.stderr)

    def test_codec_name_relabel_cannot_manufacture_growth(self) -> None:
        (self.root / "src/Serializers/Json.php").write_text(
            "<?php\nreturn 'changed';\n",
            encoding="utf-8",
        )
        self.write_json(
            "tests/Fixtures/V2/CodecRegression/codec-relabel.json",
            self.codec_fixture(
                "codec-relabeled-codec-case",
                "0",
                "AA==",
                codec="renamed-avro",
            ),
        )

        result = self.validate()

        self.assertNotEqual(0, result.returncode, result.stdout)
        self.assertIn("duplicate semantic fixtures", result.stderr)

    def test_cross_format_rewrapping_cannot_manufacture_growth(self) -> None:
        self.write_json(
            "resources/protocol/avro-value-v1-golden.json",
            self.avro_golden_fixture("AA=="),
        )
        self.commit_current_as_base()
        (self.root / "src/Serializers/Json.php").write_text(
            "<?php\nreturn 'changed';\n",
            encoding="utf-8",
        )
        self.write_json(
            "tests/Fixtures/V2/CodecRegression/rewrapped.json",
            self.codec_fixture(
                "rewrapped-long-seven",
                "7",
                "AA==",
                schema="example.CrossFormatValue",
                fingerprint="0123456789abcdef",
            ),
        )

        result = self.validate()

        self.assertNotEqual(0, result.returncode, result.stdout)
        self.assertIn("duplicate semantic fixtures", result.stderr)

    def test_equivalent_base64_bytes_share_cross_format_identity(self) -> None:
        self.write_json(
            "resources/protocol/avro-value-v1-golden.json",
            self.avro_golden_fixture("AA=="),
        )
        self.commit_current_as_base()
        (self.root / "src/Serializers/Json.php").write_text(
            "<?php\nreturn 'changed';\n",
            encoding="utf-8",
        )
        self.write_json(
            "tests/Fixtures/V2/CodecRegression/rewrapped.json",
            self.codec_fixture(
                "equivalent-base64-long-seven",
                "7",
                "AB==",
                schema="example.CrossFormatValue",
                fingerprint="0123456789abcdef",
            ),
        )

        result = self.validate()

        self.assertNotEqual(0, result.returncode, result.stdout)
        self.assertIn("is not canonical base64", result.stderr)

    def test_bytes_value_aliases_share_cross_format_identity(self) -> None:
        self.write_json(
            "resources/protocol/avro-value-v1-golden.json",
            self.avro_golden_fixture(
                "AA==",
                kind="bytes",
                value=None,
                value_base64="AP8=",
            ),
        )
        self.commit_current_as_base()
        (self.root / "src/Serializers/Json.php").write_text(
            "<?php\nreturn 'changed';\n",
            encoding="utf-8",
        )
        self.write_json(
            "tests/Fixtures/V2/CodecRegression/rewrapped.json",
            self.codec_fixture(
                "rewrapped-bytes",
                "",
                "AA==",
                schema="example.CrossFormatValue",
                fingerprint="0123456789abcdef",
                tagged_value={"type": "bytes", "base64": "AP8="},
            ),
        )

        result = self.validate()

        self.assertNotEqual(0, result.returncode, result.stdout)
        self.assertIn("duplicate semantic fixtures", result.stderr)

    def test_equivalent_base64_value_bytes_share_cross_format_identity(self) -> None:
        self.write_json(
            "resources/protocol/avro-value-v1-golden.json",
            self.avro_golden_fixture(
                "AA==",
                kind="bytes",
                value=None,
                value_base64="AP8=",
            ),
        )
        self.commit_current_as_base()
        (self.root / "src/Serializers/Json.php").write_text(
            "<?php\nreturn 'changed';\n",
            encoding="utf-8",
        )
        self.write_json(
            "tests/Fixtures/V2/CodecRegression/rewrapped.json",
            self.codec_fixture(
                "equivalent-base64-bytes",
                "",
                "AB==",
                schema="example.CrossFormatValue",
                fingerprint="0123456789abcdef",
                tagged_value={"type": "bytes", "base64": "AP9="},
            ),
        )

        result = self.validate()

        self.assertNotEqual(0, result.returncode, result.stdout)
        self.assertIn("is not canonical base64", result.stderr)

    def test_malformed_golden_wire_must_be_canonical_base64(self) -> None:
        fixture = self.avro_golden_fixture("AA==")
        fixture["malformed_frames"][0]["wire_base64"] = "%%%"
        self.write_json(
            "resources/protocol/avro-value-v1-golden.json",
            fixture,
        )

        result = self.validate()

        self.assertNotEqual(0, result.returncode, result.stdout)
        self.assertIn("is not canonical base64", result.stderr)

    def test_malformed_wire_migration_rejects_different_decoded_bytes(self) -> None:
        self.write_json(
            "resources/protocol/avro-value-v1-golden.json",
            self.malformed_avro_golden_fixture("AR=="),
        )
        self.commit_current_as_base()
        self.write_json(
            "resources/protocol/avro-value-v1-golden.json",
            self.malformed_avro_golden_fixture("Ag=="),
        )

        result = self.validate()

        self.assertNotEqual(0, result.returncode, result.stdout)
        self.assertIn("immutable fixture file", result.stderr)

    def test_prerelease_json_replay_fixture_migrates_in_place_to_avro(self) -> None:
        path = "tests/Fixtures/V2/ReplayRegression/base.json"
        legacy = self.official_history_replay_fixture()
        legacy["workflow"]["payload_codec"] = "json"
        legacy["history"][1]["payload"].update(
            {
                "result": '"Hello, Ada!"',
                "payload_codec": "json",
            }
        )
        self.write_json(path, legacy)
        self.commit_current_as_base()
        self.write_json(path, self.official_history_replay_fixture())

        result = self.validate()

        self.assertEqual(0, result.returncode, result.stderr)
        counts = json.loads(result.stdout)["counts"]["replay"]
        self.assertEqual(1, counts["base"])
        self.assertEqual(1, counts["current"])

    def test_prerelease_json_to_avro_migration_cannot_change_replay_behavior(
        self,
    ) -> None:
        path = "tests/Fixtures/V2/ReplayRegression/base.json"
        legacy = self.official_history_replay_fixture()
        legacy["workflow"]["payload_codec"] = "json"
        legacy["history"][1]["payload"].update(
            {
                "result": '"Hello, Ada!"',
                "payload_codec": "json",
            }
        )
        self.write_json(path, legacy)
        self.commit_current_as_base()

        changed = self.official_history_replay_fixture()
        changed["expected"]["result"]["greeting"] = "Hello, Grace!"
        self.write_json(path, changed)

        result = self.validate()

        self.assertNotEqual(0, result.returncode, result.stdout)
        self.assertIn("immutable fixture file", result.stderr)

    def test_malformed_wire_migration_accepts_same_decoded_bytes(self) -> None:
        self.write_json(
            "resources/protocol/avro-value-v1-golden.json",
            self.malformed_avro_golden_fixture("AR=="),
        )
        self.commit_current_as_base()
        self.write_json(
            "resources/protocol/avro-value-v1-golden.json",
            self.malformed_avro_golden_fixture("AQ=="),
        )

        result = self.validate()

        self.assertEqual(0, result.returncode, result.stderr)

    def test_malformed_wire_migration_accepts_explicit_legacy_repair(self) -> None:
        self.write_json(
            "resources/protocol/avro-value-v1-golden.json",
            self.malformed_avro_golden_fixture("%%%"),
        )
        self.commit_current_as_base()
        self.write_json(
            "resources/protocol/avro-value-v1-golden.json",
            self.malformed_avro_golden_fixture("JSUl"),
        )

        result = self.validate()

        self.assertEqual(0, result.returncode, result.stderr)

    def test_malformed_name_migration_accepts_decoded_behavior_reclassification(self) -> None:
        self.write_json(
            "resources/protocol/avro-value-v1-golden.json",
            self.malformed_avro_golden_fixture("JSUl", name="invalid_base64"),
        )
        self.commit_current_as_base()
        self.write_json(
            "resources/protocol/avro-value-v1-golden.json",
            self.malformed_avro_golden_fixture(
                "JSUl",
                name="decoded_non_magic_bytes",
            ),
        )

        result = self.validate()

        self.assertEqual(0, result.returncode, result.stderr)

    def test_malformed_name_migration_rejects_unrelated_reclassification(self) -> None:
        self.write_json(
            "resources/protocol/avro-value-v1-golden.json",
            self.malformed_avro_golden_fixture("JSUl", name="invalid_base64"),
        )
        self.commit_current_as_base()
        self.write_json(
            "resources/protocol/avro-value-v1-golden.json",
            self.malformed_avro_golden_fixture("JSUl", name="unrelated_name"),
        )

        result = self.validate()

        self.assertNotEqual(0, result.returncode, result.stdout)
        self.assertIn("immutable fixture file", result.stderr)

    def test_double_representations_share_cross_format_identity(self) -> None:
        self.write_json(
            "resources/protocol/avro-value-v1-golden.json",
            self.avro_golden_fixture("AA==", kind="double", value=7.0),
        )
        self.commit_current_as_base()
        (self.root / "src/Serializers/Json.php").write_text(
            "<?php\nreturn 'changed';\n",
            encoding="utf-8",
        )
        self.write_json(
            "tests/Fixtures/V2/CodecRegression/rewrapped.json",
            self.codec_fixture(
                "rewrapped-double",
                "",
                "AA==",
                schema="example.CrossFormatValue",
                fingerprint="0123456789abcdef",
                tagged_value={"type": "double", "value": "7.0"},
            ),
        )

        result = self.validate()

        self.assertNotEqual(0, result.returncode, result.stdout)
        self.assertIn("duplicate semantic fixtures", result.stderr)

    def test_active_payload_codec_surfaces_require_corpus_growth(self) -> None:
        for source_path in self.codec_source_paths():
            with self.subTest(source_path=source_path):
                target = self.root / source_path
                target.write_text("<?php\nreturn 'changed';\n", encoding="utf-8")

                result = self.validate()

                self.assertNotEqual(0, result.returncode, result.stdout)
                self.assertIn(
                    "codec implementation changed but its corpus did not grow",
                    result.stderr,
                )
                target.write_text("<?php\nreturn 'base';\n", encoding="utf-8")

    def test_core_replay_surfaces_require_growth_without_diff_keywords(self) -> None:
        for source_path in self.replay_source_paths():
            with self.subTest(source_path=source_path):
                target = self.root / source_path
                target.write_text("<?php\nreturn 'changed';\n", encoding="utf-8")

                result = self.validate()

                self.assertNotEqual(0, result.returncode, result.stdout)
                self.assertIn(
                    "replay implementation changed but its corpus did not grow",
                    result.stderr,
                )
                target.write_text("<?php\nreturn 'base';\n", encoding="utf-8")

    def test_workflow_step_history_guard_cannot_be_weakened(self) -> None:
        policy = self.read_policy()
        policy["categories"]["replay"]["guards"] = [
            guard
            for guard in policy["categories"]["replay"]["guards"]
            if guard["glob"] != "src/V2/Support/WorkflowStepHistory.php"
        ]
        self.write_json("regression-corpus-policy.json", policy)

        result = self.validate()

        self.assertNotEqual(0, result.returncode, result.stdout)
        self.assertIn(
            "categories.replay.guards cannot remove or change a base selector",
            result.stderr,
        )


if __name__ == "__main__":
    unittest.main()
