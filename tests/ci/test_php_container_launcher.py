import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest
import shutil


ROOT = Path(__file__).resolve().parents[2]
LAUNCHER = ROOT / "scripts/ci/run-php-container.sh"


class PhpContainerLauncherTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix="workflow-php-launcher-")
        self.addCleanup(self.temporary.cleanup)
        self.state = Path(self.temporary.name)
        self.binary = self.state / "bin"
        self.binary.mkdir()
        self.log = self.state / "docker-arguments.json"
        docker = self.binary / "docker"
        docker.write_text("#!/usr/bin/env python3\nimport json,os,sys\n"
                          "open(os.environ['WORKFLOW_PHP_CI_TEST_LOG'],'w').write(json.dumps(sys.argv[1:]))\n")
        docker.chmod(0o755)
        for name in ("php", "composer"):
            (self.binary / name).symlink_to(LAUNCHER)
        self.environment = os.environ.copy()
        self.environment.update({
            "PATH": str(self.binary) + os.pathsep + self.environment["PATH"],
            "WORKFLOW_PHP_CI_ROOT": str(ROOT), "WORKFLOW_PHP_CI_STATE": str(self.state),
            "WORKFLOW_PHP_CI_IMAGE": "sha256:" + "a" * 64,
            "WORKFLOW_PHP_CI_TEST_LOG": str(self.log),
        })

    def invoke(self, command, *arguments):
        return subprocess.run([str(self.binary / command), *arguments], cwd=ROOT,
                              env=self.environment, capture_output=True, text=True, timeout=10)

    def arguments(self):
        return json.loads(self.log.read_text())

    def test_config_id_and_caps_are_bound_without_passing_host_credentials(self):
        for name in ("GH_TOKEN", "GITHUB_TOKEN", "COMPOSER_AUTH"):
            self.environment[name] = "synthetic-unforwarded-canary"
        result = self.invoke("php", "-r", "echo 1;")
        self.assertEqual(result.returncode, 0, result.stderr)
        arguments = self.arguments()
        self.assertIn("sha256:" + "a" * 64, arguments)
        self.assertIn("--init", arguments)
        for option, value in (("--cpus", "2"), ("--memory", "2g"),
                              ("--pids-limit", "512"), ("--cap-drop", "ALL"),
                              ("--security-opt", "no-new-privileges")):
            self.assertEqual(arguments[arguments.index(option) + 1], value)
        for name in ("GH_TOKEN", "GITHUB_TOKEN", "COMPOSER_AUTH"):
            self.assertNotIn(name, arguments)
        self.assertNotIn("synthetic-unforwarded-canary", str(arguments))

    def test_mutable_image_selector_refuses_before_docker(self):
        self.environment["WORKFLOW_PHP_CI_IMAGE"] = "php:8.4"
        result = self.invoke("php", "--version")
        self.assertNotEqual(result.returncode, 0)
        self.assertFalse(self.log.exists())

    def test_existing_ci_ini_defaults_are_mounted_read_only(self):
        result = self.invoke("php", "-r", "echo ini_get('memory_limit');")
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn(f"type=bind,source={ROOT}/docker/php-ci/ci-defaults.ini,target=/usr/local/etc/php/conf.d/zz-workflow-php-ci.ini,readonly",
                      self.arguments())

    def test_selected_ca_mounts_are_read_only_and_composer_guard_uses_real_phar(self):
        bundle = self.state / "selected.pem"
        bundle.write_text("public certificate fixture\n")
        roots = self.state / "empty-roots"
        roots.mkdir()
        self.environment.update({"SSL_CERT_FILE": str(bundle), "COMPOSER_CAFILE": str(bundle),
                                 "SSL_CERT_DIR": str(roots), "COMPOSER_CAPATH": str(roots)})
        result = self.invoke("php", "/reviewed/verify.php", str(self.binary / "composer"))
        self.assertEqual(result.returncode, 0, result.stderr)
        arguments = self.arguments()
        for selected in (bundle, roots):
            mount = f"type=bind,source={selected},target={selected},readonly"
            self.assertEqual(arguments.count(mount), 1)
        self.assertEqual(arguments[-3:], ["php", "/reviewed/verify.php", "/usr/local/bin/composer"])

    def test_host_selector_ignores_workspace_and_pythonpath_modules_before_apt(self):
        for location in ("workspace", "pythonpath"):
            with self.subTest(location=location):
                root = self.state / location
                scripts = root / "scripts/ci"
                scripts.mkdir(parents=True)
                for name in ("setup-php-container.sh", "php-container-images.json"):
                    shutil.copyfile(ROOT / "scripts/ci" / name, scripts / name)
                marker = root / "untrusted-import-ran"
                modules = root if location == "workspace" else root / "ambient"
                modules.mkdir(exist_ok=True)
                (modules / "json.py").write_text(f"open({str(marker)!r},'w').write('marker only')\n")
                environment = self.environment | {"PYTHONPATH": str(modules)}
                result = subprocess.run(["bash", str(scripts / "setup-php-container.sh"), "8.2"],
                                        cwd=root, env=environment, capture_output=True, timeout=10)
                self.assertNotEqual(result.returncode, 0, "The deliberately absent APT helper must stop before Docker.")
                self.assertFalse(marker.exists(), "Host setup must never import workspace/PYTHONPATH code.")
                self.assertFalse(self.log.exists())

    def test_original_upgrade_inputs_and_private_database_cross_the_container_boundary(self):
        database = self.state / "tmp/laravel-embedded-upgrade.fixture123"
        database.mkdir(parents=True, mode=0o700)
        verifier = self.state / "verify.php"
        verifier.write_text("<?php // public verifier fixture\n")
        values = {"LARAVEL_VERSION": "12.*", "WORKFLOW_V2_VERSION": "2.99.0",
                  "DATABASE_PATH": str(database / "database.sqlite"), "TABLES": "jobs",
                  "QUEUE_NAME": "workflow-v2"}
        self.environment.update(values | {"WORKFLOW_UPGRADE_DATABASE_DIRECTORY": str(database),
                                          "WORKFLOW_COMPOSER_TRUST_VERIFIER": str(verifier)})
        result = self.invoke("php", "-r", "echo 1;")
        self.assertEqual(result.returncode, 0, result.stderr)
        arguments = self.arguments()
        for variable in values:
            self.assertIn(variable, arguments)
        self.assertIn(f"type=bind,source={database},target={database}", arguments)
        self.assertIn(f"type=bind,source={verifier},target={verifier},readonly", arguments)

    def test_database_mount_refuses_an_unowned_scope_before_docker(self):
        self.environment["WORKFLOW_UPGRADE_DATABASE_DIRECTORY"] = str(ROOT)
        result = self.invoke("php", "--version")
        self.assertNotEqual(result.returncode, 0)
        self.assertFalse(self.log.exists())


if __name__ == "__main__":
    unittest.main()
