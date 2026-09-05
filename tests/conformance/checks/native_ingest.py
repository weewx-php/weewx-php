"""Real PHP HTTP endpoint, original WeeWX Simulator packets, concurrent retries and CLI admission."""

from __future__ import annotations

import concurrent.futures
import contextlib
import json
import os
import signal
import socket
import sqlite3
import subprocess
import time
import urllib.error
import urllib.request
import uuid

from harness import Context, Failure
from weewx.drivers.simulator import Simulator


def run(ctx: Context) -> None:
    work = ctx.work / "native-ingest"
    work.mkdir()
    config = work / "weather.conf"
    config.write_text("data_dir = data\ntimezone = UTC\n[Ingest]\nenabled = true\n"
                      "tick_mode = external\ntrusted_proxies = 127.0.0.1\nmax_native_receipts = 5000000\n")
    webroot = work / "web"
    webroot.mkdir()
    (webroot / "weather").symlink_to(ctx.root / "public", target_is_directory=True)

    def require(condition: bool, message: str) -> None:
        if not condition:
            raise Failure(message)

    def cli(*args: str) -> str:
        done = subprocess.run([ctx.php, str(ctx.root / "bin/weewx-php"), "--config", str(config),
                               "collector", *args], capture_output=True, text=True, timeout=15)
        require(done.returncode == 0, "collector CLI failed: " + done.stderr)
        return done.stdout

    created = dict(line.split(": ", 1) for line in cli("add", "Raspberry").splitlines())
    collector = created["collector_id"]
    token = created["token"]
    station = str(uuid.uuid4())
    simulator = Simulator(start_time=int(time.time()) - 86400, mode="generator", loop_interval=2.5)
    loops = simulator.genLoopPackets()
    original = next(loops)
    event = {"event_id": str(uuid.uuid4()), "station_id": station,
             "driver_module": "weewx.drivers.simulator", "kind": "loop",
             "dateTime": original["dateTime"], "usUnits": original["usUnits"],
             "data": {key: value for key, value in original.items() if key not in ("dateTime", "usUnits")}}
    payload = {"version": 1, "collector_id": collector, "packets": [event]}
    with socket.socket() as listener:
        listener.bind(("127.0.0.1", 0))
        port = listener.getsockname()[1]
    log = (work / "server.log").open("w+")
    env = {**os.environ, "WEEWX_PHP_CONF": str(config), "PHP_CLI_SERVER_WORKERS": "4"}
    # No router: the real file must work in a subdirectory without URL rewriting.
    server = subprocess.Popen([ctx.php, "-S", f"127.0.0.1:{port}", "-t", str(webroot)],
                              env=env, stdout=log, stderr=log, start_new_session=True)
    url = f"http://127.0.0.1:{port}/weather/ingest/weewx.php"
    tls_headers = {"X-Forwarded-For": "192.0.2.10", "X-Forwarded-Proto": "https"}

    def request(body: dict | None = None, *, headers: dict | None = None,
                method: str = "POST", proxy: bool = True) -> tuple[int, dict]:
        request_headers = {"Content-Type": "application/json", **(tls_headers if proxy else {}),
                           **({"Authorization": "Bearer " + token} if headers is None else headers)}
        req = urllib.request.Request(url, data=json.dumps(body or payload).encode(),
                                     headers=request_headers, method=method)
        try:
            with urllib.request.urlopen(req, timeout=15) as response:
                return response.status, json.loads(response.read())
        except urllib.error.HTTPError as error:
            return error.code, json.loads(error.read())

    try:
        for _ in range(100):
            try:
                with socket.create_connection(("127.0.0.1", port), timeout=0.1):
                    break
            except OSError:
                time.sleep(0.05)
        else:
            raise Failure("native HTTP server did not start")

        require(request(proxy=False)[0] == 403, "plaintext native ingest accepted")
        require(request(method="GET")[0] == 405, "native GET accepted")
        require(request(headers={})[0] == 401, "unauthenticated native ingest accepted")
        require(request(headers={"X-WeeWX-Token": token, "Authorization": "Bearer " + token})[0] == 401,
                "ambiguous native credentials accepted")

        with concurrent.futures.ThreadPoolExecutor(max_workers=8) as pool:
            answers = list(pool.map(lambda _: request(), range(8)))
        require(all(code == 200 and answer["results"][0]["status"] == "pending" for code, answer in answers),
                "concurrent discovery failed")
        discovered = cli("stations", collector).splitlines()
        require(len(discovered) == 1 and station in discovered[0], "discovery created duplicate stations")
        cli("adopt", collector, station, "Simulator")

        with concurrent.futures.ThreadPoolExecutor(max_workers=8) as pool:
            answers = list(pool.map(lambda _: request(), range(8)))
        require(all(code == 200 for code, _ in answers), "concurrent native write failed")
        require(all(answer["limits"]["max_receipts"] == 5000000 for _, answer in answers),
                "configured native receipt capacity was not advertised")
        statuses = [answer["results"][0]["status"] for _, answer in answers]
        require(statuses.count("stored") == 1 and statuses.count("duplicate") == 7,
                "lost-ACK retry was not idempotent")
        dbpath = work / "data/live.sdb"
        with contextlib.closing(sqlite3.connect(dbpath)) as db:
            rows = db.execute("SELECT dateTime, usUnits, data, identity FROM packet").fetchall()
            require(len(rows) == 1, "retry duplicated observations")
            stamp, units, data, identity = rows[0]
            require(stamp == original["dateTime"] and units == original["usUnits"]
                    and json.loads(data) == event["data"], "WeeWX Simulator packet changed in transport")
            require(identity == collector + "/" + station, "station identity was not namespaced")
            require(db.execute("SELECT COUNT(*) FROM weewx_receipt").fetchone()[0] == 1,
                    "receipt did not commit with the packet")

        answer = request(headers={"X-WeeWX-Token": token})
        require(answer[0] == 200 and answer[1]["results"][0]["status"] == "duplicate",
                "shared-host token header failed")
        changed = {**payload, "packets": [{**event, "data": {"outTemp": -99}}]}
        require(request(changed)[1]["results"][0].get("reason") == "event_conflict",
                "event identity accepted changed content")
        require(request({**payload, "collector_id": str(uuid.uuid4())})[0] == 403,
                "collector could impersonate another identity")
        cli("disable", collector)
        require(request()[0] == 401, "collector revocation did not take effect")
        cli("enable", collector)
        rotated = cli("rotate", collector).splitlines()[0].removeprefix("token: ")
        require(request()[0] == 401, "rotated token remained valid")
        require(request(headers={"X-WeeWX-Token": rotated})[0] == 200, "rotated token failed")
        log.flush()
        log.seek(0)
        require(token not in log.read(), "server log exposed native token")
        app_log = work / "data/log/weewx-php.log"
        if app_log.exists():
            require(token not in app_log.read_text() and rotated not in app_log.read_text(), "application log exposed native token")
    finally:
        loops.close()
        simulator.closePort()
        with contextlib.suppress(ProcessLookupError):
            os.killpg(server.pid, signal.SIGTERM)
        with contextlib.suppress(subprocess.TimeoutExpired):
            server.wait(timeout=5)
        if server.poll() is None:
            os.killpg(server.pid, signal.SIGKILL)
            server.wait(timeout=5)
        log.close()
