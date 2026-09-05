"""Rewrite part of a real database and check that nothing moved.

`difftest` checks the arithmetic; this checks the writing. A copy of the
reference database, the last three days deleted -- archive records and
daily summaries alike -- and written back through the normal write path,
exactly as the archiver would during a catch-up.

The deletion is the point. Anything that merely appends would pass while
quietly relying on state a fresh installation does not have.

Three standards: the archive table must come back byte for byte; daily
sums must match exactly; daily extremes may come back duller, because the
stored ones include LOOP packets that no longer exist, and never sharper.
A rebuilt empty row for a day WeeWX had no row for is the row WeeWX
itself would write, and is allowed.
"""

from __future__ import annotations

import json
import sqlite3
import time

from harness import Context, Failure
from reference import SUM_COLUMNS, copy_reference, day_tables, table_rows

DAYS = 3


def run(ctx: Context) -> None:
    work = copy_reference(ctx.work / "roundtrip.sdb")
    conn = sqlite3.connect(work)
    try:
        tables = day_tables(conn)
        before = {"archive": table_rows(conn, "archive")}
        for name in tables:
            before[f"archive_day_{name}"] = table_rows(conn, f"archive_day_{name}")
        last_ts = conn.execute("SELECT max(dateTime) FROM archive").fetchone()[0]
        cutoff = _start_of_archive_day(last_ts) - (DAYS - 1) * 86400
        cursor = conn.execute("SELECT * FROM archive WHERE dateTime > ? ORDER BY dateTime", (cutoff,))
        columns = [d[0] for d in cursor.description]
        records = [{c: v for c, v in zip(columns, row, strict=True) if v is not None} for row in cursor]
        conn.execute("DELETE FROM archive WHERE dateTime > ?", (cutoff,))
        for name in tables:
            conn.execute(f'DELETE FROM "archive_day_{name}" WHERE dateTime >= ?', (cutoff,))
        conn.commit()
    finally:
        conn.close()
    print(f"  rewriting {len(records)} records from {DAYS} day(s) after {cutoff}")

    answer = ctx.php_json("archive.php", "add-batch", str(work), stdin=json.dumps(records))
    if answer != {"written": len(records)}:
        raise Failure(f"expected {len(records)} records written back, got {answer!r}")

    conn = sqlite3.connect(work)
    try:
        after = {table: table_rows(conn, table) for table in before}
    finally:
        conn.close()

    counts: dict[str, int] = {}
    examples: dict[str, list[str]] = {}

    def note(kind: str, detail: str) -> None:
        counts[kind] = counts.get(kind, 0) + 1
        examples.setdefault(kind, [])
        if len(examples[kind]) < 6:
            examples[kind].append(detail)

    for table, snapshot in before.items():
        is_daily = table != "archive"
        for key in sorted(set(snapshot) | set(after[table])):
            if key not in after[table]:
                note("missing row", f"{table}: row {key} vanished")
                continue
            if key not in snapshot:
                row = after[table][key]
                if row.get("count") == 0 and row.get("min") is None and row.get("max") is None:
                    note("empty row filled in", f"{table}: empty row {key} added")
                else:
                    note("extra row", f"{table}: row {key} appeared")
                continue
            for column, want in snapshot[key].items():
                got = after[table][key].get(column)
                if want == got:
                    continue
                where = f"{table}.{column} @ {key}: was {want!r}, now {got!r}"
                if not is_daily or column in SUM_COLUMNS:
                    note("archive" if not is_daily else "sum", where)
                elif column in ("min", "max") and want is not None and got is not None:
                    sharper = (column == "min" and got < want) or (column == "max" and got > want)
                    note("sharper extreme" if sharper else "duller extreme", where)
                else:
                    note("duller extreme", where)

    print(f"  archive table differences: {counts.get('archive', 0)}")
    print(f"  daily sum differences:     {counts.get('sum', 0)}")
    print(f"  extremes sharper:          {counts.get('sharper extreme', 0)}")
    print(f"  extremes duller:           {counts.get('duller extreme', 0)}   (expected: LOOP highs and lows)")
    for kind in ("missing row", "extra row", "empty row filled in"):
        if counts.get(kind):
            print(f"  {kind}: {counts[kind]}")

    fatal = [kind for kind in ("archive", "sum", "sharper extreme", "missing row", "extra row") if counts.get(kind)]
    if fatal:
        lines = [f"{kind}: " + "; ".join(examples[kind]) for kind in fatal]
        raise Failure("\n  ".join(lines))


def _start_of_archive_day(ts: int) -> int:
    """WeeWX's startOfArchiveDay in the container's zone: midnight closes the previous day."""
    local = time.localtime(ts)
    sod = int(time.mktime((local.tm_year, local.tm_mon, local.tm_mday, 0, 0, 0, 0, 0, -1)))
    if sod == ts:
        earlier = time.localtime(ts - 1)
        sod = int(time.mktime((earlier.tm_year, earlier.tm_mon, earlier.tm_mday, 0, 0, 0, 0, 0, -1)))
    return sod
