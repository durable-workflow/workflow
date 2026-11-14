import http.server
import json
import os
import pathlib
import ssl
import subprocess
import tempfile
import threading
import unittest
import shutil
import shlex


@unittest.skipUnless(os.environ.get("WORKFLOW_PHP_CI_TEST_IMAGE"), "requires the built PHP CI image")
class PhpContainerTlsTests(unittest.TestCase):
    def test_selected_clean_ca_overrides_ambient_trust_before_http(self):
        state = pathlib.Path(os.environ["WORKFLOW_PHP_CI_STATE"])
        clean_bundle = pathlib.Path(os.environ["SSL_CERT_FILE"])
        requests = []

        class Repository(http.server.BaseHTTPRequestHandler):
            def do_GET(self):
                requests.append(self.path)
                payload = json.dumps({"packages": {"workflow/tls-control": {"1.0.0": {
                    "name": "workflow/tls-control", "version": "1.0.0", "type": "metapackage",
                }}}}).encode()
                self.send_response(200)
                self.send_header("Content-Type", "application/json")
                self.send_header("Content-Length", str(len(payload)))
                self.end_headers()
                self.wfile.write(payload)

            def log_message(self, *_arguments):
                pass

        with tempfile.TemporaryDirectory(dir=state / "tmp") as directory:
            project = pathlib.Path(directory)

            def openssl(*arguments):
                subprocess.run(["openssl", *arguments], cwd=project, check=True,
                               stdout=subprocess.DEVNULL, stderr=subprocess.PIPE, timeout=30)

            openssl("req", "-x509", "-newkey", "rsa:2048", "-nodes", "-days", "1",
                    "-subj", "/CN=AAA Certificate Services", "-keyout", "root.key", "-out", "root.pem")
            openssl("req", "-new", "-newkey", "rsa:2048", "-nodes", "-subj", "/CN=127.0.0.1",
                    "-keyout", "server.key", "-out", "server.csr")
            (project / "extensions.cnf").write_text("subjectAltName=IP:127.0.0.1\n")
            openssl("x509", "-req", "-in", "server.csr", "-CA", "root.pem", "-CAkey", "root.key",
                    "-CAcreateserial", "-days", "1", "-extfile", "extensions.cnf", "-out", "server.pem")
            context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
            context.minimum_version = ssl.TLSVersion.TLSv1_2
            context.load_cert_chain(project / "server.pem", project / "server.key")
            # The Docker source/state mounts must never expose the synthetic private keys.
            (project / "root.key").unlink()
            (project / "server.key").unlink()
            server = http.server.ThreadingHTTPServer(("127.0.0.1", 0), Repository)
            server.socket = context.wrap_socket(server.socket, server_side=True)
            thread = threading.Thread(target=server.serve_forever, daemon=True)
            thread.start()
            try:
                (project / "composer.json").write_text(json.dumps({
                    "name": "workflow/tls-project",
                    "repositories": [{"type": "composer", "url": f"https://127.0.0.1:{server.server_port}"},
                                     {"packagist.org": False}],
                }))
                home = project / "home"
                home.mkdir()
                (home / "config.json").write_text(json.dumps({"config": {"cafile": str(project / "root.pem")}}))
                environment = os.environ.copy()
                for name in list(environment):
                    if name.endswith(("_CA_BUNDLE", "_CAINFO", "_CAPATH")) or name in {
                        "SSL_CERT_FILE", "SSL_CERT_DIR", "COMPOSER_CAFILE", "COMPOSER_CAPATH",
                        "PIP_CERT", "CARGO_HTTP_PROXY_CAINFO", "DW_CI_CA_SHA256", "COMPOSER",
                    }:
                        environment.pop(name)
                environment.update({"WORKFLOW_PHP_CI_IMAGE": os.environ["WORKFLOW_PHP_CI_TEST_IMAGE"],
                                    "COMPOSER_HOME": str(home), "COMPOSER_CACHE_DIR": str(home / "cache")})
                command = [str(state / "bin/composer"), "--no-plugins", "--no-scripts", "--no-cache",
                           "show", "--all", "workflow/tls-control"]
                ambient = subprocess.run(command, cwd=project, env=environment, text=True,
                                         capture_output=True, timeout=45)
                self.assertEqual(ambient.returncode, 0, ambient.stderr)
                self.assertGreater(len(requests), 0, "Ambient trust must reach the HTTPS repository")
                requests.clear()
                environment.update({"SSL_CERT_FILE": str(clean_bundle), "CURL_CA_BUNDLE": str(clean_bundle),
                                    "GIT_SSL_CAINFO": str(clean_bundle), "COMPOSER_CAFILE": str(clean_bundle)})
                selected = subprocess.run(command, cwd=project, env=environment, text=True,
                                          capture_output=True, timeout=45)
                self.assertNotEqual(selected.returncode, 0)
                self.assertIn("curl error 60", selected.stderr, selected.stderr)
                self.assertEqual(requests, [], "Selected clean trust must refuse before any HTTP request")
            finally:
                server.shutdown()
                server.server_close()
                thread.join(timeout=2)


@unittest.skipUnless(os.environ.get("WORKFLOW_PHP_CI_TEST_IMAGE"), "requires the built PHP CI image")
class PhpContainerHostBoundaryTests(unittest.TestCase):
    def replacement_control(self, replace_wrapper):
        source = pathlib.Path(__file__).resolve().parents[2]
        original_state = pathlib.Path(os.environ["WORKFLOW_PHP_CI_STATE"])
        with tempfile.TemporaryDirectory(dir=original_state / "tmp") as directory:
            control = pathlib.Path(directory)
            root = control / "repository"
            state = control / "state"
            for path in (root / "scripts/ci", root / "docker/php-ci", state / "bin",
                         state / "tmp", state / "composer-home"):
                path.mkdir(parents=True, mode=0o700)
            subprocess.run(["git", "init", "-q", str(root)], check=True, timeout=10)
            launcher = root / "scripts/ci/run-php-container.sh"
            # Only isolate network access; exercise the selected launcher's actual mounts.
            original = (source / "scripts/ci/run-php-container.sh").read_bytes().replace(
                b"--network host", b"--network none")
            launcher.write_bytes(original)
            launcher.chmod(0o755)
            shutil.copyfile(source / "docker/php-ci/ci-defaults.ini", root / "docker/php-ci/ci-defaults.ini")
            wrapper = state / "bin/php"
            wrapper.symlink_to(launcher)
            (state / "bin/composer").symlink_to(launcher)
            marker = control / "host-executed-substitution"
            data = state / "tmp/allowed-data"
            payload = "#!/bin/sh\nprintf substituted > " + shlex.quote(str(marker)) + "\n"
            target = wrapper if replace_wrapper else launcher
            environment = os.environ.copy() | {
                "WORKFLOW_PHP_CI_ROOT": str(root), "WORKFLOW_PHP_CI_STATE": str(state),
                "WORKFLOW_PHP_CI_IMAGE": os.environ["WORKFLOW_PHP_CI_TEST_IMAGE"],
                "TMPDIR": str(state / "tmp"), "RUNNER_TEMP": str(state / "tmp"),
                "COMPOSER_HOME": str(state / "composer-home"),
                "COMPOSER_CACHE_DIR": str(state / "composer-home/cache"),
            }
            for variable in ("GITHUB_ACTION_PATH", "WORKFLOW_COMPOSER_TRUST_VERIFIER",
                             "WORKFLOW_UPGRADE_DATABASE_DIRECTORY"):
                environment.pop(variable, None)
            mutation = subprocess.run([
                str(wrapper), "-r",
                'file_put_contents($argv[3], "data"); if ($argv[4] === "1") { @unlink($argv[1]); } '
                '@file_put_contents($argv[1], $argv[2]); @chmod($argv[1], 0755);',
                str(target), payload, str(data), "1" if replace_wrapper else "0",
            ], cwd=root, env=environment, capture_output=True, text=True, timeout=30)
            self.assertEqual(mutation.returncode, 0, mutation.stderr)
            self.assertEqual(data.read_text(), "data", "The container must retain its intended writable data mount")
            followup = subprocess.run([str(wrapper), "-r", 'echo "real-php";'], cwd=root,
                                      env=environment, capture_output=True, text=True, timeout=30)
            self.assertFalse(marker.exists(), "Container code replaced a subsequent host-executed verifier wrapper")
            self.assertEqual(followup.returncode, 0, followup.stderr)
            self.assertEqual(followup.stdout, "real-php")
            self.assertEqual(launcher.read_bytes(), original)
            self.assertTrue(wrapper.is_symlink())

    def test_repository_launcher_cannot_replace_the_next_host_verifier(self):
        self.replacement_control(False)

    def test_state_wrapper_cannot_replace_the_next_host_verifier(self):
        self.replacement_control(True)


if __name__ == "__main__":
    unittest.main()
