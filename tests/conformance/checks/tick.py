"""The whole chain, end to end, and WeeWX at the end of it.

A configuration file, a journal fed with a day of synthetic packets from
two senders, and the command line's `tick` run the way cron runs it. Then
WeeWX opens the archive that came out: reads the records, rebuilds the
daily summaries from them with its own code, and every sum has to come
out the same and no extreme sharper. Finally the application's own
`verify` has to agree with itself.

What this proves that the other checks do not: the pieces fit together
in the order the tick calls them, the configuration reaches every one of
them, and a second sender's readings land where `[[[fields]]]` says and
nowhere else.
"""

from __future__ import annotations

import json
import math
import os
import sqlite3
import subprocess
import time

from harness import Context, Failure

INTERVAL = 300
SENDER_SECONDS = 16
SUMS = ("sum", "count", "wsum", "sumtime", "xsum", "ysum", "dirsumtime", "squaresum", "wsquaresum")


def run(ctx: Context) -> None:
    import weewx.manager

    root = ctx.work / "tick"
    root.mkdir()
    (root / "data").mkdir()
    config = root / "weewx-php.conf"
    config.write_text("\n".join([
        "data_dir = data",
        f"timezone = {os.environ.get('TZ') or 'UTC'}",
        "archive_interval = 300",
        "archive_delay = 15",
        "time_budget = 120",
        "max_intervals_per_run = 10000",
        "log_level = debug",
        "",
        "[Stations]",
        "    [[ecowitt]]",
        "        name = \"HP2561AE Pro\"",
        "        expected_interval = 16",
        "    [[dwd]]",
        "        name = \"DWD Freising\"",
        "        expected_interval = 3600",
        "",
        "[Archives]",
        "    [[kirchdorf]]",
        "        name = \"Kirchdorf an der Amper\"",
        "        latitude = 48.4596",
        "        longitude = 11.6539",
        "        altitude = 440, meter",
        "        unit_system = METRICWX",
        "        primary = ecowitt",
        "        senders = ecowitt, dwd",
        "        [[[members]]]",
        "            [[[[dwd]]]]",
        "                indoor = false",
        "        [[[fields]]]",
        "            [[[[dwd]]]]",
        "                outTemp = extraTemp1",
        "                outHumidity = extraHumid1",
        "        [[[qc]]]",
        "            outTemp = -40, 60, degree_C",
        "",
    ]))

    start = int(time.mktime((2026, 5, 14, 0, 0, 0, 0, 0, -1)))
    packets = _packets(start)
    answer = ctx.php_json("live.php", "add", str(root / "data" / "live.sdb"), str(INTERVAL), "kirchdorf",
                          stdin=json.dumps(packets))
    if answer != {"added": len(packets)}:
        raise Failure(f"expected {len(packets)} packets added, got {answer!r}")
    print(f"  {len(packets)} packets from two senders over a day")

    outcome = _weewx_php(ctx, config, "tick")
    if outcome.get("status") != "ok":
        raise Failure(f"tick answered {outcome!r}")
    records = outcome["archives"]["kirchdorf"]["records"]
    expected = 24 * 3600 // INTERVAL
    if records != expected:
        raise Failure(f"tick wrote {records} records, expected {expected}")
    if outcome["stations"]["ecowitt"]["status"] != "down" or outcome["stations"]["dwd"]["status"] != "down":
        raise Failure(f"stations judged {outcome['stations']!r}; a day later both should be down")
    print(f"  tick wrote {records} records and judged the stations")

    path = root / "data" / "archives" / "kirchdorf.sdb"
    problems: list[str] = []
    settings = {"SQLITE_ROOT": str(path.parent), "database_name": path.name, "driver": "weedb.sqlite"}
    manager = weewx.manager.DaySummaryManager.open(settings)
    try:
        count = manager.getSql("SELECT COUNT(*) FROM archive")[0]
        if count != expected:
            problems.append(f"WeeWX counts {count} records")
        noon = manager.getRecord(start + 12 * 3600)
        if noon is None:
            problems.append("no record at noon")
        else:
            if noon["usUnits"] != 17 or noon["interval"] != 5:
                problems.append(f"noon record has usUnits {noon['usUnits']}, interval {noon['interval']}")
            if noon.get("inTemp") is None:
                problems.append("the primary's inTemp is missing")
            if noon.get("dewpoint") is None or noon.get("rainRate") is None or noon.get("windrun") is None:
                problems.append("derived readings are missing from the noon record")
            if noon.get("rain") is None:
                problems.append("rain from the counter is missing")
        # The second sender reports half a minute past each hour, so its
        # readings sit in the record five minutes past, and in 24 of them.
        placed = manager.getRecord(start + 12 * 3600 + INTERVAL)
        if placed is None or not _same(placed.get("extraTemp1"), 9.0 + 12 * 0.1) or not _same(placed.get("extraHumid1"), 75.0):
            problems.append(f"the second sender's readings did not reach extraTemp1/extraHumid1: {placed and (placed.get('extraTemp1'), placed.get('extraHumid1'))!r}")
        hourly = manager.getSql("SELECT COUNT(*) FROM archive WHERE extraTemp1 IS NOT NULL")[0]
        if hourly != 24:
            problems.append(f"{hourly} record(s) carry the second sender's temperature, expected 24")
        # The second sender's room readings were dropped, so nothing of
        # them may be in inHumidity: the primary sends 44, the other 99.
        odd = manager.getSql("SELECT COUNT(*) FROM archive WHERE inHumidity > 50")[0]
        if odd:
            problems.append(f"{odd} record(s) carry the second sender's room humidity")
        # A reading beyond its limit was refused: nothing above 60 C.
        hot = manager.getSql("SELECT COUNT(*) FROM archive WHERE outTemp > 60")[0]
        if hot:
            problems.append(f"{hot} record(s) carry a temperature quality control should have refused")
        before = {name: _rows(path, name) for name in ("outTemp", "rain", "wind", "extraTemp1", "dewpoint")}
        manager.drop_daily()
    finally:
        manager.close()
    manager = _rebuilt(path)
    try:
        manager.backfill_day_summary()
    finally:
        manager.close()
    for name, rows in before.items():
        after = _rows(path, name)
        for sod, row in rows.items():
            theirs = after.get(sod)
            if theirs is None:
                if row.get("count"):
                    problems.append(f"{name} {sod}: WeeWX's rebuild has no row")
                continue
            for column in SUMS:
                if column in row and not _same(row[column], theirs[column]):
                    problems.append(f"{name} {sod}.{column}: ours {row[column]!r}, WeeWX's rebuild {theirs[column]!r}")
            for column, direction in (("min", -1), ("max", 1)):
                if column in row and row[column] is not None and theirs[column] is not None:
                    if (row[column] - theirs[column]) * direction < 0:
                        problems.append(f"{name} {sod}.{column}: ours {row[column]!r} is duller than WeeWX's {theirs[column]!r}")
    print("  WeeWX read the records and rebuilt the summaries with the same sums")

    verified = _weewx_php(ctx, config, "verify", "kirchdorf", json_output=False)
    if "0 problem(s)" not in verified:
        problems.append(f"verify said: {verified.strip()}")
    if problems:
        raise Failure("\n  ".join([f"{len(problems)} problem(s):", *problems[:25]]))


def _weewx_php(ctx: Context, config, *args: str, json_output: bool = True):
    command = [ctx.php, str(ctx.root / "bin" / "weewx-php"), "--config", str(config), *args]
    done = subprocess.run(command, capture_output=True, text=True, check=False)
    if done.returncode != 0:
        raise Failure(f"weewx-php {' '.join(args)} exited {done.returncode}: {done.stderr.strip()}\n{done.stdout[:800]}")
    if not json_output:
        return done.stdout
    try:
        return json.loads(done.stdout)
    except json.JSONDecodeError as exc:
        raise Failure(f"weewx-php {' '.join(args)} did not print JSON: {exc}\n{done.stdout[:400]}") from exc


def _packets(start: int) -> list[dict]:
    """A day of packets: the primary every sixteen seconds with a rain
    counter and one impossible temperature, the other sender hourly with
    readings that must not land by name."""
    made = []
    counter = 0.0
    for i in range(24 * 3600 // SENDER_SECONDS):
        when = start + 1 + i * SENDER_SECONDS
        hour = (when - start) / 3600.0
        if i % 900 == 450:
            counter += 0.2
        temperature = 12.0 + 8.0 * math.sin(math.pi * (hour - 6) / 12.0)
        if i == 1234:
            temperature = 85.0
        made.append({"dateTime": when, "usUnits": 17, "sender": "ecowitt", "data": {
            "outTemp": temperature, "outHumidity": 60.0 + 20.0 * math.cos(math.pi * hour / 12.0),
            "windSpeed": 0.0 if i % 50 == 0 else 1.0 + 3.0 * abs(math.sin(hour)), "windDir": (i * 7) % 360,
            "windGust": 2.0 + 4.0 * abs(math.sin(hour)), "windGustDir": (i * 11) % 360,
            "barometer": 1013.0 + 4.0 * math.sin(math.pi * hour / 24.0),
            "dayRain": counter, "inTemp": 21.0, "inHumidity": 44.0,
            "radiation": max(0.0, 700.0 * math.sin(math.pi * (hour - 6) / 12.0)),
            "wh65_batt": 0,
        }})
    for h in range(24):
        when = start + 3600 * h + 30
        made.append({"dateTime": when, "usUnits": 17, "sender": "dwd", "data": {
            "outTemp": 9.0 + h * 0.1, "outHumidity": 75.0, "inTemp": 30.0, "inHumidity": 99.0, "windSpeed": 9.0,
        }})
    made.sort(key=lambda one: one["dateTime"])
    return made


def _rebuilt(path):
    import weewx.manager

    settings = {"SQLITE_ROOT": str(path.parent), "database_name": path.name, "driver": "weedb.sqlite"}
    with weewx.manager.Manager.open(settings, "archive") as plain:
        keys = plain.sqlkeys
    day_schema = [(one, "scalar") for one in keys if one not in ("dateTime", "usUnits", "interval")]
    if "windSpeed" in keys:
        day_schema += [("wind", "vector")]
    return weewx.manager.open_manager(
        {"database_dict": settings, "table_name": "archive",
         "manager": "weewx.manager.DaySummaryManager",
         "schema": {"day_summaries": day_schema}}, initialize=True)


def _rows(path, field: str) -> dict[int, dict]:
    conn = sqlite3.connect(f"file:{path.as_posix()}?mode=ro", uri=True)
    try:
        cursor = conn.execute(f"SELECT * FROM archive_day_{field}")
        names = [d[0] for d in cursor.description]
        return {row[0]: dict(zip(names, row)) for row in cursor.fetchall()}
    finally:
        conn.close()


def _same(a: object, b: object) -> bool:
    if a is None or b is None:
        return a is None and b is None
    if isinstance(a, (int, float)) and isinstance(b, (int, float)):
        return math.isclose(float(a), float(b), rel_tol=1e-9, abs_tol=1e-12)
    return a == b
