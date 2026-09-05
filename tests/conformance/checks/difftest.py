"""Rebuild a real database's daily summaries and compare them to what is stored.

The acceptance test for the arithmetic. If the PHP side cannot reproduce
the daily summaries of a database WeeWX itself wrote, nothing built on top
of it is trustworthy.

Two classes of column, two standards, and the difference is the point:

  * Sums (sum, count, wsum, sumtime, xsum, ysum, dirsumtime, squaresum,
    wsquaresum) come only from archive records. They must match exactly.
  * Extremes may differ. With `loop_hilo` WeeWX folds LOOP packets straight
    into the daily highs and lows, so a stored extreme can be sharper than
    any archive record. A rebuild can only be equal or duller, never
    sharper. Sharper is a bug, and that is what this reports.
"""

from __future__ import annotations

import math
import sqlite3

from harness import Context, Failure
from reference import EXTREME_COLUMNS, SUM_COLUMNS, copy_reference, day_tables, table_rows

REL_TOL = 1e-9


def run(ctx: Context) -> None:
    work = copy_reference(ctx.work / "difftest.sdb")
    conn = sqlite3.connect(work)
    try:
        before = {name: table_rows(conn, f"archive_day_{name}") for name in day_tables(conn)}
        n_records = conn.execute("SELECT count(*) FROM archive").fetchone()[0]
    finally:
        conn.close()
    print(f"  {n_records} records, {len(before)} daily tables")

    answer = ctx.php_json("archive.php", "rebuild-all", str(work))
    print(f"  rebuilt {answer.get('days') if isinstance(answer, dict) else answer!r} days")

    conn = sqlite3.connect(work)
    try:
        after = {name: table_rows(conn, f"archive_day_{name}") for name in before}
    finally:
        conn.close()

    checked = identical = duller = 0
    sum_diffs: list[str] = []
    sharper: list[str] = []
    missing: list[str] = []
    for name, stored_rows in before.items():
        for sod, stored in stored_rows.items():
            rebuilt = after[name].get(sod)
            if rebuilt is None:
                missing.append(f"{name} @ {sod}")
                continue
            for column, want in stored.items():
                if column == "dateTime":
                    continue
                got = rebuilt[column]
                checked += 1
                if _close(want, got):
                    identical += 1
                    continue
                where = f"{name}.{column} @ {sod}: stored={want!r} rebuilt={got!r}"
                if column in SUM_COLUMNS:
                    sum_diffs.append(where)
                elif column in EXTREME_COLUMNS:
                    if _is_sharper(column, want, got):
                        sharper.append(where)
                    else:
                        duller += 1

    print(f"  checked {checked} values: identical {identical}, sums differing {len(sum_diffs)},"
          f" extremes sharper {len(sharper)}, extremes duller {duller} (expected: LOOP highs and lows)")
    if missing:
        print(f"  days with no rebuilt row: {len(missing)}")
    if sum_diffs or sharper or missing:
        raise Failure("\n  ".join(["differences:", *sum_diffs[:15], *sharper[:15], *missing[:5]]))


def _close(a: object, b: object) -> bool:
    if a is None or b is None:
        return a is None and b is None
    if isinstance(a, (int, float)) and isinstance(b, (int, float)):
        return math.isclose(a, b, rel_tol=REL_TOL, abs_tol=1e-12)
    return a == b


def _is_sharper(column: str, want: object, got: object) -> bool:
    if not isinstance(want, (int, float)) or not isinstance(got, (int, float)):
        return False
    return (column == "min" and got < want) or (column == "max" and got > want)
