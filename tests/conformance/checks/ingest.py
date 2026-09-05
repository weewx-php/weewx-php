"""Real HTTP requests, CLI adoption and simultaneous first WU uploads.

Runs the application's PHP entry point, with several server workers on
loopback. All data and logs are temporary, and no external service is used.
"""

from __future__ import annotations

import concurrent.futures
import contextlib
import datetime
import json
import os
import signal
import socket
import sqlite3
import subprocess
import time
import urllib.error
import urllib.parse
import urllib.request

from harness import Context, Failure


def run(ctx: Context) -> None:
    work = ctx.work / "http-ingest"
    work.mkdir()
    config = work / "weather.conf"
    config.write_text("data_dir = data\ntimezone = UTC\n[Ingest]\nenabled = true\ntick_mode = external\n")

    def cli(*args: str) -> str:
        result = subprocess.run([ctx.php, str(ctx.root / "bin/weewx-php"), "--config", str(config), "ingest", *args],
                                capture_output=True, text=True, timeout=15)
        if result.returncode:
            raise Failure(f"ingest {args[0]} failed: {result.stderr}")
        return result.stdout

    cli("endpoints")
    dbpath = work / "data/ingest.sdb"

    def rows(sql: str, params: tuple = ()) -> list:
        with contextlib.closing(sqlite3.connect(dbpath)) as db:
            return db.execute(sql, params).fetchall()

    keys = dict(rows("SELECT name, value FROM ingest_meta"))
    with socket.socket() as listener:
        listener.bind(("127.0.0.1", 0))
        port = listener.getsockname()[1]
    env = {**os.environ, "WEEWX_PHP_CONF": str(config), "PHP_CLI_SERVER_WORKERS": "4"}
    log = (work / "server.log").open("w+")
    server = subprocess.Popen([ctx.php, "-S", f"127.0.0.1:{port}", "-t", str(ctx.root / "public"),
                               str(ctx.root / "bin/ingest-router.php")], env=env, stdout=log, stderr=log,
                              start_new_session=True)
    root = f"http://127.0.0.1:{port}"

    def request(path: str, fields: dict | None = None, method: str = "GET") -> tuple[int, str]:
        encoded = urllib.parse.urlencode(fields or {})
        url = root + path + (("?" + encoded) if method == "GET" and encoded else "")
        req = urllib.request.Request(url, data=encoded.encode() if method == "POST" else None, method=method)
        try:
            with urllib.request.urlopen(req, timeout=15) as response:
                return response.status, response.read().decode()
        except urllib.error.HTTPError as error:
            return error.code, error.read().decode()

    def require(condition: bool, message: str) -> None:
        if not condition:
            raise Failure(message)

    try:
        for _ in range(100):
            try:
                with socket.create_connection(("127.0.0.1", port), timeout=0.1):
                    break
            except OSError:
                time.sleep(0.05)
        else:
            raise Failure("HTTP test server did not start")

        stamp = datetime.datetime.now(datetime.timezone.utc).strftime("%Y-%m-%d %H:%M:%S")
        wu = {"ID": "SHARED", "PASSWORD": keys["wunderground"], "tempf": "68", "dateutc": stamp, "action": "updateraw"}
        endpoint = "/weatherstation/updateweatherstation.php"
        with concurrent.futures.ThreadPoolExecutor(max_workers=8) as pool:
            answers = list(pool.map(lambda _: request(endpoint, wu), range(8)))
        require(all(answer == (200, "success") for answer in answers), "concurrent WU requests were not all accepted")
        senders = rows("SELECT id, received, state FROM ingest_sender")
        require(len(senders) == 1 and senders[0][1:] == (8, "pending"), "first-use assignment was not atomic")
        first = senders[0][0]
        next_password = rows("SELECT value FROM ingest_meta WHERE name='wunderground'")[0][0]
        require(next_password != keys["wunderground"], "WU password was not consumed")
        require(not (work / "data/live.sdb").exists(), "pending requests wrote live.sdb")
        require(request(endpoint, {**wu, "PASSWORD": "wrong"})[0] == 403, "bad WU password accepted")
        require(request(endpoint, {"ID": "SHARED", "PASSWORD": next_password})[0] == 400, "metadata-only WU consumed password")
        require(rows("SELECT value FROM ingest_meta WHERE name='wunderground'")[0][0] == next_password, "invalid request rotated password")

        second_wu = {**wu, "PASSWORD": next_password}
        require(request("/weatherstation/updateweatherstation.asp", second_wu, "POST") == (200, "success"), "WU form POST or ASP route failed")
        senders = rows("SELECT id, identity FROM ingest_sender ORDER BY rowid")
        require(len(senders) == 2 and senders[0][1] == senders[1][1], "same WU ID did not create distinct senders")
        second = senders[1][0]
        cli("adopt", first, "First console")
        cli("adopt", second, "Second console")
        require(request(endpoint, wu) == (200, "success"), "assigned WU password stopped working")
        require(request("/weatherstation/updateweatherstation", second_wu) == (200, "success"), "extensionless WU route failed")

        eco_path = f'/{keys["ecowitt"]}/ecowitt/'
        eco = {"PASSKEY": "0" * 32, "tempf": "70", "dateutc": stamp, "model": "Local test"}
        require(request(eco_path, eco, "POST") == (200, '{"errcode":"0","errmsg":"ok"}'), "Ecowitt acknowledgement mismatch")
        ecowitt_id = rows("SELECT id FROM ingest_sender WHERE protocol='ecowitt'")[0][0]
        require(request(eco_path, eco)[0] == 405, "Ecowitt accepted GET")
        cli("adopt", ecowitt_id, "Ecowitt console")
        require(request("/receive.php" + eco_path, eco, "POST")[0] == 200, "PATH_INFO fallback failed")
        cli("block", ecowitt_id)
        require(request(eco_path, {**eco, "tempf": "72"}, "POST")[0] == 200, "blocked console did not receive acknowledgement")
        require(rows("SELECT sample FROM ingest_sender WHERE id=?", (ecowitt_id,))[0][0] is None, "blocked console retained sample")
        for path in ["/", "/data/ingest.sdb", "/src/Ingest/Store.php", "/receive.php", "/no-such-path"]:
            require(request(path)[0] == 404, "an unrelated path exposed data")

        with contextlib.closing(sqlite3.connect(work / "data/live.sdb")) as live:
            records = live.execute("SELECT sender, identity, data, raw FROM packet").fetchall()
        require(len(records) == 3, "wrong live packet count after adoption/blocking")
        require(len({row[0] for row in records}) == 3, "distinct senders merged in live.sdb")
        require(all(keys["wunderground"] not in str(row) and next_password not in str(row) for row in records), "WU secret leaked to live.sdb")
        require(all("PASSWORD=" not in json.dumps(json.loads(row[2])) for row in records), "credentials entered measurements")
        require("[redacted]" in urllib.parse.unquote(cli("sample", first)), "CLI sample was not redacted")
        require(not (work / "data/state.sdb").exists(), "external mode ran an inline tick")
        require(not rows("SELECT value FROM ingest_meta WHERE name='tick'"), "external mode claimed a tick")

        # Key replacement races with in-flight uploads but cannot change sender
        # identity, free credentials, adoption or the existing live history.
        free_before = rows("SELECT value FROM ingest_meta WHERE name='wunderground'")[0][0]
        with concurrent.futures.ThreadPoolExecutor(max_workers=9) as pool:
            uploads = [pool.submit(request, endpoint, wu) for _ in range(8)]
            replacement = pool.submit(cli, "rotate", first)
            new_password = replacement.result().strip().removeprefix("PASSWORD: ")
            require(all(task.result()[0] in (200, 403) for task in uploads), "rotation race lost storage availability")
        require(request(endpoint, wu)[0] == 403, "replaced password remained valid")
        wu["PASSWORD"] = new_password
        with concurrent.futures.ThreadPoolExecutor(max_workers=8) as pool:
            require(all(answer == (200, "success") for answer in pool.map(lambda _: request(endpoint, wu), range(8))),
                    "replacement password failed under concurrent uploads")
        require(rows("SELECT id, state FROM ingest_sender WHERE id=?", (first,)) == [(first, "adopted")], "replacement changed sender identity/adoption")
        require(rows("SELECT value FROM ingest_meta WHERE name='wunderground'")[0][0] == free_before, "replacement consumed free password")
        new_free = cli("rotate", "wunderground").strip().removeprefix("PASSWORD: ")
        require(new_free != free_before and new_free != new_password, "setup rotation reused a credential")
        require(request(endpoint, {**wu, "PASSWORD": free_before})[0] == 403, "discarded free password remained valid")
        require(request(endpoint, wu)[0] == 200, "free rotation broke an assigned sender")
        new_path = cli("rotate", "ecowitt").strip().removeprefix("Path: ")
        require(request(eco_path, eco, "POST")[0] == 403, "old Ecowitt path remained valid")
        require(request(new_path, eco, "POST")[0] == 200, "new Ecowitt path failed")
        require(rows("SELECT state FROM ingest_sender WHERE id=?", (ecowitt_id,)) == [("blocked",)], "rotation removed a sender block")
        require(len(rows("SELECT id FROM ingest_sender")) == 3, "rotation created duplicate senders")
        diagnostics = cli("status", first)
        require("duplicates=" in diagnostics and "interval=" in diagnostics and "time=device" in diagnostics, "CLI diagnostics incomplete")
        require(new_password not in cli("status") and new_free not in cli("status"), "diagnostics exposed credentials")
        cli("prune")
        # CLI/non-FastCGI auto mode must also keep archival work out of receipt.
        config.write_text(config.read_text().replace("tick_mode = external", "tick_mode = auto"))
        require(request(endpoint, wu)[0] == 200, "auto mode receipt failed")
        require(not (work / "data/state.sdb").exists(), "non-FastCGI auto mode ran an inline tick")
        with contextlib.closing(sqlite3.connect(work / "data/live.sdb")) as live:
            require(live.execute("SELECT COUNT(*) FROM packet").fetchone()[0] == 3, "key rotation changed live history/deduplication")
        print("  HTTP protocols, concurrent discovery and credential replacement, adoption, diagnostics, blocking, rotation and external/auto ticks")
    finally:
        os.killpg(server.pid, signal.SIGTERM)
        server.wait(timeout=10)
        log.close()
