"""The reference database: a real archive WeeWX wrote, if one is here.

`reference/weewx.sdb` is not part of the repository -- it is somebody's
actual measurements -- so a check that needs it skips when it is absent
and says so. It is copied with SQLite's backup API, never with `cp`: the
file is in WAL mode, and a copied WAL is a torn state.
"""

from __future__ import annotations

import sqlite3
from pathlib import Path

from harness import Skip

REFERENCE = Path(__file__).resolve().parents[2] / "reference" / "weewx.sdb"


def copy_reference(target: Path) -> Path:
    """A private copy of the reference database, or a Skip when there is none."""
    if not REFERENCE.is_file():
        raise Skip(f"{REFERENCE} is not here; see docs on pulling a reference database")
    source = sqlite3.connect(f"file:{REFERENCE.as_posix()}?mode=ro", uri=True)
    try:
        destination = sqlite3.connect(target)
        try:
            source.backup(destination)
        finally:
            destination.close()
    finally:
        source.close()
    return target


def table_rows(conn: sqlite3.Connection, table: str) -> dict[int, dict]:
    """Every row of a table keyed on dateTime."""
    cursor = conn.execute(f'SELECT * FROM "{table}" ORDER BY dateTime')
    columns = [d[0] for d in cursor.description]
    return {row[0]: dict(zip(columns, row, strict=True)) for row in cursor}


def day_tables(conn: sqlite3.Connection, table: str = "archive") -> list[str]:
    prefix = f"{table}_day_"
    return sorted(
        name[len(prefix):] for (name,) in conn.execute(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name LIKE ?", (prefix + "%",))
        if name != f"{table}_day__metadata")


SUM_COLUMNS = frozenset({"sum", "count", "wsum", "sumtime", "xsum", "ysum", "dirsumtime",
                         "squaresum", "wsquaresum"})
EXTREME_COLUMNS = frozenset({"min", "mintime", "max", "maxtime", "max_dir"})
