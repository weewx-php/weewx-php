"""Deployment checks against a local TLS-only FTP server, inside Docker."""

import contextlib
import ftplib
import importlib.util
import io
import json
import logging
from pathlib import Path
import ssl
import subprocess
import tempfile
import threading
import unittest
from unittest.mock import patch

from pyftpdlib.authorizers import DummyAuthorizer
from pyftpdlib.handlers import TLS_FTPHandler
from pyftpdlib.servers import FTPServer


ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location("deploy", ROOT / "scripts/deploy_all_inkl.py")
deploy = importlib.util.module_from_spec(spec)
spec.loader.exec_module(deploy)
logging.getLogger("pyftpdlib").disabled = True


class DeploymentTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.certs = tempfile.TemporaryDirectory()
        cls.cert = Path(cls.certs.name) / "server.crt"
        cls.key = Path(cls.certs.name) / "server.key"
        subprocess.run([
            "openssl", "req", "-x509", "-newkey", "rsa:2048", "-nodes", "-days", "1",
            "-keyout", str(cls.key), "-out", str(cls.cert), "-subj", "/CN=localhost",
            "-addext", "subjectAltName=DNS:localhost,IP:127.0.0.1",
        ], check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)

    @classmethod
    def tearDownClass(cls):
        cls.certs.cleanup()

    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()
        self.addCleanup(self.directory.cleanup)
        self.root = Path(self.directory.name)
        self.app = self.root / "app"
        self.old = {"src/autoload.php": b"old autoload", "public/index.php": b"old page"}
        for path, content in self.old.items():
            self.put(path, content)
        authorizer = DummyAuthorizer()
        authorizer.add_user("test-user", "test-password", str(self.root), perm="elradfmwMT")

        class Handler(TLS_FTPHandler):
            tls_control_required = True
            tls_data_required = True

        Handler.certfile = str(self.cert)
        Handler.keyfile = str(self.key)
        Handler.authorizer = authorizer
        self.server = FTPServer(("127.0.0.1", 0), Handler)
        self.thread = threading.Thread(target=self.server.serve_forever, kwargs={"timeout": 0.05, "handle_exit": False}, daemon=True)
        self.thread.start()
        self.addCleanup(self.stop)
        self.config = {"host": "127.0.0.1", "port": self.server.socket.getsockname()[1], "username": "test-user", "timeout": 3, "remote_root": "/app"}
        self.events = []

    def stop(self):
        self.server.close_all()
        self.thread.join(timeout=3)

    def put(self, path, data):
        target = self.app / path
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_bytes(data)

    def remote(self):
        ftp = deploy.connect(self.config, "test-password", ssl.create_default_context(cafile=str(self.cert)))
        self.addCleanup(ftp.close)
        remote = deploy.Remote(ftp)
        remote.enter("/app")
        return remote

    def emit(self, key, detail=""):
        self.events.append((key, detail))

    def test_update_preserves_installation_and_verifies_backups(self):
        protected = {
            "weewx-php.conf": b"private config", "data/archive.sdb": b"database",
            "extensions/climate/extension.php": b"extension", "themes/demo/theme.php": b"theme",
            "public/.htaccess": b"custom rules", "public/custom.php": b"custom page",
        }
        for path, data in protected.items():
            self.put(path, data)
        new = {"src/autoload.php": b"new autoload", "public/index.php": b"new page", "themes/basic/theme.php": b"basic"}
        remote = self.remote()
        self.assertEqual(3, deploy.deploy(remote, new, self.emit))
        for path, data in {**new, **protected}.items():
            self.assertEqual(data, (self.app / path).read_bytes())
        release = next(detail for key, detail in self.events if key == "backup")
        for path, data in self.old.items():
            self.assertEqual(data, (self.app / release / "backup" / path).read_bytes())
        manifest = json.loads((self.app / release / "manifest.json").read_text())
        self.assertFalse(manifest["files"]["themes/basic/theme.php"]["existed"])
        self.assertFalse((self.app / deploy.STATE / "lock").exists())
        self.assertEqual(0, deploy.deploy(remote, new, self.emit))
        self.assertIn(("unchanged", ""), self.events)

    def test_complete_checkout_can_be_published(self):
        files = deploy.payload(ROOT)
        self.assertEqual(len(files), deploy.deploy(self.remote(), files, self.emit))
        for path, data in files.items():
            self.assertEqual(data, (self.app / path).read_bytes(), path)

    def test_stage_failure_does_not_replace_live_files(self):
        remote = self.remote()
        write = remote.write

        def fail(path, data):
            if "/new/public/" in path:
                raise ftplib.error_perm("550 injected staging failure")
            return write(path, data)

        with patch.object(remote, "write", side_effect=fail):
            with self.assertRaises(ftplib.error_perm):
                deploy.deploy(remote, {key: b"new" for key in self.old}, self.emit)
        for path, data in self.old.items():
            self.assertEqual(data, (self.app / path).read_bytes())
        self.assertFalse((self.app / deploy.STATE / "lock").exists())

    def test_publish_failure_restores_replacements_and_removes_new_files(self):
        remote = self.remote()
        rename = remote.ftp.rename

        def fail(source, destination):
            result = rename(source, destination)
            # Simulate a server that committed a rename before the acknowledgement failed.
            if "/new/" in source and destination == "public/index.php":
                raise ftplib.error_temp("450 injected publication failure")
            return result

        new = {"src/new.php": b"new file", "src/autoload.php": b"new autoload", "public/index.php": b"new page"}
        with patch.object(remote.ftp, "rename", side_effect=fail):
            with self.assertRaises(ftplib.error_temp):
                deploy.deploy(remote, new, self.emit)
        self.assertFalse((self.app / "src/new.php").exists())
        for path, data in self.old.items():
            self.assertEqual(data, (self.app / path).read_bytes())
        self.assertIn("restored", [key for key, _ in self.events])

    def test_failed_recovery_keeps_lock_and_backups(self):
        remote = self.remote()
        with patch.object(remote.ftp, "rename", side_effect=ftplib.error_temp("450 unavailable")):
            with self.assertRaises(ftplib.error_temp):
                deploy.deploy(remote, {"public/index.php": b"new"}, self.emit)
        self.assertTrue((self.app / deploy.STATE / "lock").is_dir())
        self.assertIn("restore_failed", [key for key, _ in self.events])

    def test_lock_blocks_a_second_deployment(self):
        self.put(f"{deploy.STATE}/lock/owner", b"other deployment")
        with self.assertRaisesRegex(deploy.DeployError, "locked"):
            deploy.deploy(self.remote(), {"public/index.php": b"new"}, self.emit)
        self.assertEqual(b"old page", (self.app / "public/index.php").read_bytes())

    def test_wrong_root_is_rejected_without_writes(self):
        remote = self.remote()
        with self.assertRaises(deploy.DeployError):
            remote.enter("/app/public")
        self.assertFalse((self.app / "public" / deploy.STATE).exists())

    def test_remote_links_are_rejected(self):
        (self.app / "themes").symlink_to(self.root / "outside", target_is_directory=True)
        (self.root / "outside").mkdir()
        with self.assertRaises(deploy.DeployError):
            deploy.deploy(self.remote(), {"themes/basic/theme.php": b"new"}, self.emit)
        self.assertEqual([], list((self.root / "outside").iterdir()))

    def test_remote_root_link_is_rejected(self):
        (self.root / "linked").symlink_to(self.app, target_is_directory=True)
        with self.assertRaises(deploy.DeployError):
            self.remote().enter("/linked")

    def test_remote_file_link_is_rejected(self):
        (self.app / "src/new.php").symlink_to(self.app / "src/autoload.php")
        with self.assertRaises(deploy.DeployError):
            deploy.deploy(self.remote(), {"src/new.php": b"new"}, self.emit)
        self.assertEqual(b"old autoload", (self.app / "src/autoload.php").read_bytes())

    def test_untrusted_certificate_is_rejected(self):
        with self.assertRaises(ssl.SSLCertVerificationError):
            deploy.connect(self.config, "test-password")

    def test_plaintext_connections_are_rejected(self):
        with ftplib.FTP() as ftp:
            ftp.connect(self.config["host"], self.config["port"], timeout=3)
            with self.assertRaises(ftplib.error_perm):
                ftp.login("test-user", "test-password")


class LocalTest(unittest.TestCase):
    def test_real_payload_only_contains_core(self):
        files = deploy.payload(ROOT)
        self.assertIn("themes/basic/theme.php", files)
        self.assertIn("public/assets/vendor/echarts/LICENSE", files)
        for path in files:
            self.assertFalse(path.startswith(("extensions/", "vendor/", "data/", "scripts/")))
            self.assertNotIn(".htaccess", path)
            self.assertFalse(path.startswith("themes/") and not path.startswith("themes/basic/"))

    def test_preview_never_connects_or_prompts(self):
        for language in ("en", "de"):
            with patch.object(deploy, "connect") as connect, patch.object(deploy.getpass, "getpass") as password:
                with contextlib.redirect_stdout(io.StringIO()) as output:
                    self.assertEqual(0, deploy.main(["--dry-run", "--language", language]))
                self.assertIn(deploy.messages(language)["preview"], output.getvalue())
                connect.assert_not_called()
                password.assert_not_called()

    def test_locale_keys_match(self):
        english = json.loads((ROOT / "scripts/locales/deploy.en.json").read_text())
        german = json.loads((ROOT / "scripts/locales/deploy.de.json").read_text())
        self.assertEqual(english.keys(), german.keys())

    def test_paths_reject_traversal_and_command_injection(self):
        for path in ("../app", "//app", "/app/../other", "/app\r\nDELE data", "/app//src", "/app/", "/app\\src"):
            with self.subTest(path=path), self.assertRaises(deploy.DeployError):
                deploy.safe_path(path, absolute=True)

    def test_config_rejects_passwords_and_invalid_values(self):
        valid = {"host": "ftp.example.org", "username": "user", "remote_root": "/app"}
        with tempfile.TemporaryDirectory() as temp:
            path = Path(temp) / "config.json"
            path.write_text(json.dumps(valid))
            self.assertEqual(21, deploy.configuration(path)["port"])
            for extra in ({"password": "not-permitted"}, {"port": True}, {"host": "ftp://example.org"}, {"username": "user\r\nPASS x"}, {"remote_root": "../app"}):
                path.write_text(json.dumps({**valid, **extra}))
                with self.assertRaises(deploy.DeployError):
                    deploy.configuration(path)

    def test_local_linked_tree_is_rejected(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            (root / "src").symlink_to(ROOT / "src", target_is_directory=True)
            with self.assertRaisesRegex(deploy.DeployError, "symlink"):
                deploy.payload(root)

    def test_password_prompt_never_falls_back_to_echoed_input(self):
        with patch.dict(deploy.os.environ, {}, clear=True), patch.object(deploy, "configuration", return_value={}):
            with patch.object(deploy.getpass, "getpass", side_effect=deploy.getpass.GetPassWarning):
                with patch.object(deploy, "connect") as connect, contextlib.redirect_stdout(io.StringIO()) as output:
                    self.assertEqual(1, deploy.main(["--apply"]))
                connect.assert_not_called()
                self.assertIn(deploy.messages("en")["password_terminal"], output.getvalue())


if __name__ == "__main__":
    unittest.main()
