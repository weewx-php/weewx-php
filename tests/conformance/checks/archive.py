"""A database this program wrote, handed back to WeeWX.

The one rule of the whole project: an existing WeeWX database stays readable
and writable, by WeeWX itself. This does what somebody trying the program
out would do, in that order:

    1  weewx-php makes an archive from nothing and fills it with a day
    2  WeeWX's own DaySummaryManager opens it, with no schema to lean on
    3  WeeWX reads the records back, and the daily summaries through its
       own aggregate call
    4  WeeWX writes a record of its own, its own way
    5  weewx-php reads what WeeWX wrote, summaries included
    6  WeeWX drops the summaries and rebuilds them from our records, and
       every sum comes out the same

And the other way round: WeeWX creates the file, weewx-php writes into it,
WeeWX reads. Before any of that, `sqlite_master` of a file we created is
compared with one WeeWX created: the same tables, the same words.
"""

from __future__ import annotations

import json
import math
import sqlite3
import time

from harness import Context, Failure

INTERVAL = 300
HOURS = 24


def run(ctx: Context) -> None:
    import weewx
    import weewx.manager
    from weewx.schemas import wview_extended

    print(f"  WeeWX {weewx.__version__}")
    problems: list[str] = []

    # 0. The same tables, the same words.
    theirs = ctx.work / "theirs.sdb"
    ours = ctx.work / "ours.sdb"
    manager = weewx.manager.DaySummaryManager.open_with_create(_settings(theirs), schema=wview_extended.schema)
    manager.close()
    ctx.php_json("archive.php", "create", str(ours))
    problems += _compare_master(theirs, ours)
    print("  sqlite_master of a file we created reads as WeeWX's")

    # 1. Fill ours with a day.
    start = int(time.mktime((2026, 5, 14, 0, 0, 0, 0, 0, -1)))
    records = _a_day_of_weather(start)
    answer = ctx.php_json("archive.php", "add", str(ours), stdin=json.dumps(records))
    if answer != {"written": len(records)}:
        problems.append(f"expected {len(records)} records written, got {answer!r}")

    # 2. and 3. WeeWX opens without a schema and reads through its own accessors.
    manager = weewx.manager.DaySummaryManager.open(_settings(ours))
    try:
        got = manager.getSql("SELECT COUNT(*) FROM archive")[0]
        if got != len(records):
            problems.append(f"WeeWX counts {got} records, we wrote {len(records)}")
        wanted = records[len(records) // 2]
        record = manager.getRecord(wanted["dateTime"])
        if record is None or not _near(record.get("outTemp"), wanted["outTemp"]):
            problems.append(f"WeeWX read outTemp {record and record.get('outTemp')!r}, we stored {wanted['outTemp']!r}")
        if record is not None and record.get("usUnits") != 1:
            problems.append(f"WeeWX read usUnits {record.get('usUnits')!r}")

        from weeutil.weeutil import TimeSpan
        day = TimeSpan(records[0]["dateTime"] - 60, records[-1]["dateTime"] + 60)
        highest = max(one["outTemp"] for one in records)
        found = manager.getAggregate(day, "outTemp", "max")[0]
        if not _near(found, highest):
            problems.append(f"WeeWX's daily maximum {found!r} is not ours {highest!r}")
        rained = manager.getAggregate(day, "rain", "sum")[0]
        total = sum(one["rain"] for one in records)
        if not _near(rained, total, 1e-9):
            problems.append(f"WeeWX's rain total {rained!r} is not ours {total!r}")
        version = manager.version
        if version != "4.0":
            problems.append(f"WeeWX sees daily summary version {version!r}")
        print("  WeeWX opened the file, read a record and a daily maximum")

        # 4. WeeWX writes its own record, one interval after our last.
        after = start + (len(records) + 1) * INTERVAL
        fresh = dict(records[-1])
        fresh["dateTime"] = after
        fresh["outTemp"] = 61.5
        manager.addRecord(fresh)
        if manager.getSql("SELECT COUNT(*) FROM archive")[0] != len(records) + 1:
            problems.append("WeeWX's own record did not go in")
    finally:
        manager.close()

    # 5. We read what WeeWX wrote.
    ours_count = ctx.php_json("archive.php", "count", str(ours))
    if ours_count != {"count": len(records) + 1}:
        problems.append(f"we count {ours_count!r} after WeeWX wrote")
    theirs_record = ctx.php_json("archive.php", "record", str(ours), str(after))
    if not isinstance(theirs_record, dict) or not _near(theirs_record.get("outTemp"), 61.5):
        problems.append(f"we read WeeWX's record as {theirs_record!r}")
    print("  WeeWX wrote a record and we read it back")

    # 6. WeeWX rebuilds the summaries from our records: sums identical, no extreme sharper.
    before_temp = _summary_row(ours, "outTemp", start)
    before_rain = _summary_row(ours, "rain", start)
    before_wind = _summary_row(ours, "wind", start)
    manager = weewx.manager.DaySummaryManager.open(_settings(ours))
    try:
        manager.drop_daily()
    finally:
        manager.close()
    manager = _rebuilt(ours)
    try:
        manager.backfill_day_summary()
    finally:
        manager.close()
    after_temp = _summary_row(ours, "outTemp", start)
    after_rain = _summary_row(ours, "rain", start)
    after_wind = _summary_row(ours, "wind", start)
    for name, before, after_ in (("outTemp", before_temp, after_temp), ("rain", before_rain, after_rain),
                                  ("wind", before_wind, after_wind)):
        if not before or not after_:
            problems.append(f"no {name} summary row to compare")
            continue
        for column in ("sum", "count", "wsum", "sumtime", "xsum", "ysum", "dirsumtime", "squaresum", "wsquaresum"):
            if column in before and not _same(before[column], after_[column]):
                problems.append(f"{name}.{column}: ours {before[column]!r}, WeeWX's rebuild {after_[column]!r}")
        for column in ("min", "max", "mintime", "maxtime", "max_dir"):
            if column in before and not _same(before[column], after_[column]):
                problems.append(f"{name}.{column}: ours {before[column]!r}, WeeWX's rebuild {after_[column]!r}")
    print("  WeeWX rebuilt the daily summaries from our records and agreed")

    # And the other way round: WeeWX first, then us.
    manager = weewx.manager.DaySummaryManager.open_with_create(_settings(theirs), schema=wview_extended.schema)
    try:
        for record in records[:12]:
            manager.addRecord(record)
    finally:
        manager.close()
    answer = ctx.php_json("archive.php", "add", str(theirs), stdin=json.dumps(records[12:24]))
    if answer != {"written": 12}:
        problems.append(f"expected 12 records written into WeeWX's file, got {answer!r}")
    manager = weewx.manager.DaySummaryManager.open(_settings(theirs))
    try:
        if manager.getSql("SELECT COUNT(*) FROM archive")[0] != 24:
            problems.append("WeeWX does not see the records we added to its file")
        record = manager.getRecord(records[20]["dateTime"])
        if record is None or not _near(record.get("outTemp"), records[20]["outTemp"]):
            problems.append("WeeWX reads a record we added to its file differently")
        last_update = manager._read_metadata("lastUpdate")
        if last_update != str(records[23]["dateTime"]):
            problems.append(f"lastUpdate is {last_update!r}, not the last record we wrote")
    finally:
        manager.close()
    print("  WeeWX made a file, we added to it, WeeWX read our records")

    if problems:
        raise Failure("\n  ".join([f"{len(problems)} problem(s):", *problems[:25]]))


def _settings(path) -> dict:
    return {"SQLITE_ROOT": str(path.parent), "database_name": path.name, "driver": "weedb.sqlite"}


def _rebuilt(path):
    """WeeWX's manager opened the way `rebuild-daily` opens it, with a
    day-summary schema built from the archive's own columns."""
    import weewx.manager

    settings = _settings(path)
    with weewx.manager.Manager.open(settings, "archive") as plain:
        keys = plain.sqlkeys
    day_schema = [(one, "scalar") for one in keys if one not in ("dateTime", "usUnits", "interval")]
    if "windSpeed" in keys:
        day_schema += [("wind", "vector")]
    return weewx.manager.open_manager(
        {"database_dict": settings, "table_name": "archive",
         "manager": "weewx.manager.DaySummaryManager",
         "schema": {"day_summaries": day_schema}}, initialize=True)


def _a_day_of_weather(start: int) -> list[dict]:
    """Records that move, so an average is not the same as any one of them."""
    made = []
    for step in range(int(HOURS * 3600 / INTERVAL)):
        when = start + (step + 1) * INTERVAL
        swing = math.sin(step / 24.0)
        made.append({
            "dateTime": when, "usUnits": 1, "interval": INTERVAL // 60,
            "outTemp": 55.0 + swing * 12.0, "outHumidity": 60.0 + swing * 15.0,
            "barometer": 29.9 + swing * 0.2, "windSpeed": 4.0 + abs(swing) * 6.0,
            "windDir": (step * 7) % 360, "windGust": 6.0 + abs(swing) * 9.0,
            "windGustDir": (step * 7) % 360, "rain": 0.01 if step % 40 == 0 else 0.0,
            "inTemp": 68.0 + swing * 3.0, "inHumidity": 44.0,
        })
    return made


def _compare_master(theirs, ours) -> list[str]:
    problems = []
    a = _master(theirs)
    b = _master(ours)
    for name in sorted(set(a) | set(b)):
        if name not in b:
            problems.append(f"table {name}: WeeWX has it, we do not")
        elif name not in a:
            problems.append(f"table {name}: we have it, WeeWX does not")
        elif a[name] != b[name]:
            problems.append(f"table {name}: WeeWX {a[name]!r}, ours {b[name]!r}")
    return problems


def _master(path) -> dict[str, str]:
    conn = sqlite3.connect(f"file:{path.as_posix()}?mode=ro", uri=True)
    try:
        return {name: sql for name, sql in conn.execute(
            "SELECT name, sql FROM sqlite_master WHERE type = 'table'")}
    finally:
        conn.close()


def _summary_row(path, field: str, sod: int) -> dict:
    conn = sqlite3.connect(f"file:{path.as_posix()}?mode=ro", uri=True)
    try:
        cursor = conn.execute(f"SELECT * FROM archive_day_{field} WHERE dateTime = ?", (sod,))
        names = [d[0] for d in cursor.description]
        row = cursor.fetchone()
        return dict(zip(names, row, strict=True)) if row else {}
    except sqlite3.Error:
        return {}
    finally:
        conn.close()


def _near(got: object, want: float, tolerance: float = 1e-6) -> bool:
    return isinstance(got, (int, float)) and abs(float(got) - want) <= tolerance


def _same(a: object, b: object) -> bool:
    if a is None or b is None:
        return a is None and b is None
    if isinstance(a, (int, float)) and isinstance(b, (int, float)):
        return float(a) == float(b)
    return a == b
