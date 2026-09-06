#!/usr/bin/env python3
"""Update an existing core installation over explicit FTPS (Python 3.10+)."""

import argparse
import datetime
import ftplib
import getpass
import hashlib
import io
import json
import os
from pathlib import Path, PurePosixPath
import re
import ssl
import sys
import uuid
import warnings


ROOT = Path(__file__).resolve().parents[1]
TREES = ("src", "resources", "public", "themes/basic")
FILES = ("frontend.php", "LICENSE", "bin/weewx-php", "bin/worker.php", "bin/ingest-router.php")
SUFFIXES = {".php", ".json", ".js", ".css", ".svg", ".png", ".ico", ".webp", ".woff", ".woff2", ".md"}
MARKERS = ("src/autoload.php", "public/index.php")
MAX_FILE = 16 * 1024 * 1024
MAX_TOTAL = 64 * 1024 * 1024
STATE = ".weewx-deploy"


class DeployError(Exception):
    def __init__(self, key, detail=""):
        super().__init__(key)
        self.key = key
        self.detail = detail


def messages(language):
    directory = Path(__file__).with_name("locales")
    result = json.loads((directory / "deploy.en.json").read_text(encoding="utf-8"))
    if language != "en":
        result.update(json.loads((directory / f"deploy.{language}.json").read_text(encoding="utf-8")))
    return result


def safe_path(value, absolute=False):
    if not isinstance(value, str) or not value or "\\" in value or value.startswith("//"):
        raise DeployError("invalid_path")
    if any(ord(char) < 32 or ord(char) == 127 for char in value):
        raise DeployError("invalid_path")
    if value.startswith("/") != absolute:
        raise DeployError("invalid_path")
    if value != "/" and any(part in ("", ".", "..") for part in value.lstrip("/").split("/")):
        raise DeployError("invalid_path")
    return value


def payload(root):
    selected = []
    for tree in TREES:
        directory = root / tree
        # Check ancestors as well: themes/ must not redirect outside the checkout.
        if any((root / parent).is_symlink() for parent in (PurePosixPath(tree), *PurePosixPath(tree).parents) if str(parent) != "."):
            raise DeployError("symlink", tree)
        if not directory.is_dir():
            raise DeployError("missing", tree)
        for current, directories, files in os.walk(directory, followlinks=False):
            for name in directories + files:
                path = Path(current) / name
                if path.is_symlink():
                    raise DeployError("symlink", path.relative_to(root).as_posix())
            directories[:] = sorted(name for name in directories if not name.startswith("."))
            for name in files:
                if not name.startswith(".") and (Path(name).suffix in SUFFIXES or name in ("LICENSE", "NOTICE")):
                    selected.append(Path(current) / name)
    selected.extend(root / name for name in FILES)
    result = {}
    total = 0
    for path in sorted(selected):
        relative = safe_path(path.relative_to(root).as_posix())
        if path.is_symlink() or any(parent.is_symlink() for parent in path.parents if parent != root and root in parent.parents):
            raise DeployError("symlink", relative)
        if not path.is_file():
            raise DeployError("missing", relative)
        if path.stat().st_size > MAX_FILE:
            raise DeployError("too_large", relative)
        data = path.read_bytes()
        if len(data) > MAX_FILE:
            raise DeployError("too_large", relative)
        total += len(data)
        if total > MAX_TOTAL:
            raise DeployError("too_large")
        result[relative] = data
    for marker in (*MARKERS, "themes/basic/theme.php"):
        if marker not in result:
            raise DeployError("missing", marker)
    return result


def configuration(path):
    data = json.loads(path.read_text(encoding="utf-8-sig"))
    if not isinstance(data, dict) or set(data) - {"host", "username", "remote_root", "port", "timeout"}:
        raise DeployError("invalid_config")
    if not isinstance(data.get("host"), str) or not re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9.-]*", data["host"]):
        raise DeployError("invalid_config")
    user = data.get("username")
    if not isinstance(user, str) or not user or any(ord(char) < 32 or ord(char) == 127 for char in user):
        raise DeployError("invalid_config")
    safe_path(data.get("remote_root"), absolute=True)
    for key, default, maximum in (("port", 21, 65535), ("timeout", 30, 300)):
        data.setdefault(key, default)
        if type(data[key]) is not int or not 1 <= data[key] <= maximum:
            raise DeployError("invalid_config")
    return data


class Remote:
    """All paths are relative to a verified installation root; links are rejected."""

    def __init__(self, ftp):
        self.ftp = ftp
        self.cache = {}

    def listing(self, directory):
        if directory not in self.cache:
            facts = dict(self.ftp.mlsd(directory, facts=["type"]))
            lines = []
            self.ftp.retrlines(f"LIST {directory}", lines.append)
            unix = {}
            for line in lines:
                if line.startswith("total "):
                    continue
                fields = line.split(maxsplit=8)
                if len(fields) != 9 or not re.fullmatch(r"[bcdlps-][rwxstST-]{9}[+@.]?", fields[0]):
                    raise DeployError("listing_format")
                name = fields[8].split(" -> ", 1)[0] if fields[0][0] == "l" else fields[8]
                unix[name] = fields[0][0]
            # MLSD can report the target's type for symbolic links. Unix LIST
            # provides the link itself; require both listings to agree.
            for name, entry in facts.items():
                if entry.get("type") in ("cdir", "pdir"):
                    continue
                actual = {"d": "dir", "-": "file"}.get(unix.get(name), "unsafe")
                if entry.get("type") != actual:
                    entry["type"] = "unsafe"
            self.cache[directory] = facts
        return self.cache[directory]

    def kind(self, path):
        parent, name = str(PurePosixPath(path).parent), PurePosixPath(path).name
        return self.listing(parent).get(name, {}).get("type")

    def directories(self, path, create=False):
        current = ""
        for part in PurePosixPath(path).parts:
            if part == ".":
                continue
            current = f"{current}/{part}" if current else part
            kind = self.kind(current)
            if kind is None and create:
                self.ftp.mkd(current)
                self.cache.pop(str(PurePosixPath(current).parent), None)
            elif kind != "dir":
                raise DeployError("remote_path", current)

    def read(self, path):
        self.directories(str(PurePosixPath(path).parent))
        kind = self.kind(path)
        if kind is None:
            return None
        if kind != "file":
            raise DeployError("remote_path", path)
        output = io.BytesIO()

        def receive(block):
            if output.tell() + len(block) > MAX_FILE:
                raise DeployError("too_large", path)
            output.write(block)

        self.ftp.retrbinary(f"RETR {path}", receive)
        return output.getvalue()

    def write(self, path, data):
        self.directories(str(PurePosixPath(path).parent), create=True)
        if self.kind(path) not in (None, "file"):
            raise DeployError("remote_path", path)
        self.ftp.storbinary(f"STOR {path}", io.BytesIO(data))
        self.cache.pop(str(PurePosixPath(path).parent), None)
        if self.read(path) != data:
            raise DeployError("verification", path)

    def enter(self, root):
        safe_path(root, absolute=True)
        self.ftp.cwd("/")
        for part in root.strip("/").split("/") if root != "/" else ():
            self.cache.clear()
            if self.kind(part) != "dir":
                raise DeployError("remote_path", root)
            self.ftp.cwd(part)
        self.cache.clear()
        for marker in MARKERS:
            if self.read(marker) is None:
                raise DeployError("wrong_root")


def deploy(remote, files, emit):
    """Stage and verify before publishing; restore attempted changes on failure."""
    remote.directories(STATE, create=True)
    try:
        remote.ftp.mkd(f"{STATE}/lock")
    except ftplib.error_perm:
        raise DeployError("locked") from None
    release = f"{STATE}/{datetime.datetime.now(datetime.timezone.utc):%Y%m%dT%H%M%SZ}-{uuid.uuid4().hex[:12]}"
    attempted = []
    originals = {}
    keep_lock = False
    try:
        remote.write(f"{STATE}/.htaccess", b"Require all denied\n")
        changed = {}
        total = 0
        for path, data in files.items():
            # New core directories may not exist on older installations.
            parent = str(PurePosixPath(path).parent)
            remote.directories(parent, create=True)
            old = remote.read(path)
            total += len(old) if old is not None else 0
            if total > MAX_TOTAL:
                raise DeployError("too_large")
            if old != data:
                changed[path] = data
                originals[path] = old
        if not changed:
            emit("unchanged")
            return 0
        emit("backup", release)
        manifest = {"files": {path: {"existed": originals[path] is not None, "sha256": hashlib.sha256(data).hexdigest()} for path, data in changed.items()}}
        for path, data in changed.items():
            old = originals[path]
            if old is not None:
                remote.write(f"{release}/backup/{path}", old)
            remote.write(f"{release}/new/{path}", data)
        remote.write(f"{release}/manifest.json", (json.dumps(manifest, indent=2) + "\n").encode())
        try:
            for path in changed:
                # Include an operation whose acknowledgement might be lost.
                attempted.append(path)
                remote.ftp.rename(f"{release}/new/{path}", path)
                remote.cache.clear()
                if remote.read(path) != changed[path]:
                    raise DeployError("verification", path)
        except (Exception, KeyboardInterrupt):
            restored = True
            for path in reversed(attempted):
                try:
                    remote.cache.clear()
                    if originals[path] is None:
                        if remote.read(path) is not None:
                            remote.ftp.delete(path)
                    else:
                        staging = f"{release}/restore/{path}"
                        remote.write(staging, originals[path])
                        remote.ftp.rename(staging, path)
                        remote.cache.clear()
                        if remote.read(path) != originals[path]:
                            raise DeployError("verification", path)
                except (Exception, KeyboardInterrupt):
                    restored = False
            keep_lock = not restored
            emit("restored" if restored else "restore_failed", release)
            raise
        emit("updated", len(changed))
        return len(changed)
    finally:
        try:
            if keep_lock:
                emit("lock_remains", f"{STATE}/lock")
            else:
                remote.ftp.rmd(f"{STATE}/lock")
        except (Exception, KeyboardInterrupt):
            emit("lock_remains", f"{STATE}/lock")


class SessionFTP_TLS(ftplib.FTP_TLS):
    """Reuse the authenticated TLS session on protected passive data sockets."""

    def ntransfercmd(self, cmd, rest=None):
        connection, size = ftplib.FTP.ntransfercmd(self, cmd, rest)
        try:
            if self._prot_p:
                connection = self.context.wrap_socket(
                    connection, server_hostname=self.host, session=self.sock.session,
                )
            return connection, size
        except BaseException:
            connection.close()
            raise


def connect(config, password, context=None):
    context = context or ssl.create_default_context()
    context.minimum_version = ssl.TLSVersion.TLSv1_2
    ftp = SessionFTP_TLS(context=context, timeout=config["timeout"])
    try:
        ftp.connect(config["host"], config["port"])
        ftp.login(config["username"], password)
        ftp.prot_p()
        ftp.set_pasv(True)
        return ftp
    except BaseException:
        ftp.close()
        raise


def main(argv=None):
    argv = sys.argv[1:] if argv is None else argv
    early = argparse.ArgumentParser(add_help=False)
    early.add_argument("--language", choices=("en", "de"), default="en")
    language, _ = early.parse_known_args(argv)
    words = messages(language.language)
    parser = argparse.ArgumentParser(description=words["description"])
    parser.add_argument("--language", choices=("en", "de"), default="en", help=words["language"])
    parser.add_argument("--config", type=Path, default=ROOT / ".deploy/all-inkl.json", help=words["config"])
    mode = parser.add_mutually_exclusive_group()
    mode.add_argument("--dry-run", action="store_true", help=words["dry_run"])
    mode.add_argument("--apply", action="store_true", help=words["apply"])
    parser.add_argument("--list", action="store_true", help=words["list"])
    args = parser.parse_args(argv)

    def emit(key, detail=""):
        print(words.get(key, words["error"]).format(detail=detail), flush=True)

    ftp = None
    try:
        files = payload(ROOT)
        emit("payload", f"{len(files)} / {sum(map(len, files.values())):,}")
        if args.list:
            for path in files:
                print(path)
        if not args.apply:
            emit("preview")
            return 0
        config = configuration(args.config)
        password = os.environ.get("WEEWX_DEPLOY_PASSWORD")
        if not password:
            with warnings.catch_warnings():
                warnings.simplefilter("error", getpass.GetPassWarning)
                try:
                    password = getpass.getpass(words["password"])
                except getpass.GetPassWarning:
                    raise DeployError("password_terminal") from None
        if not password or "\r" in password or "\n" in password:
            raise DeployError("invalid_password")
        ftp = connect(config, password)
        remote = Remote(ftp)
        remote.enter(config["remote_root"])
        deploy(remote, files, emit)
        return 0
    except DeployError as error:
        emit(error.key, error.detail)
    except ssl.SSLError:
        emit("tls_error")
    except KeyboardInterrupt:
        emit("cancelled")
    except (OSError, ValueError, EOFError, ftplib.Error):
        # Server responses and exception text can contain credentials or private paths.
        emit("error")
    finally:
        if ftp is not None:
            ftp.close()
    return 1


if __name__ == "__main__":
    sys.exit(main())
