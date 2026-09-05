#!/usr/bin/env python3
"""Every conformance check, one after another, one exit code.

A check compares what the PHP side computes with what WeeWX computes, in one
process tree: the PHP helpers under tests/conformance/php are run through the
PHP CLI, and WeeWX is imported right here. That is why these live in the
WeeWX image rather than in PHPUnit -- a check that cannot reach the thing it
was transcribed from is an opinion, not a test.

    python tests/conformance/run.py            # everything
    python tests/conformance/run.py --list     # what would run
    python tests/conformance/run.py units sun  # only those

Each check is a module in tests/conformance/checks with a function
`run(ctx: Context) -> None`. It raises `Failure` with a message to fail,
`Skip` with a reason to be skipped, and prints whatever detail it wants
indented by two spaces. Context, Failure and Skip come from harness.py.
"""

from __future__ import annotations

import argparse
import importlib
import sys
import tempfile
import time
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
HERE = Path(__file__).resolve().parent
CHECKS = HERE / "checks"

sys.path.insert(0, str(HERE))

from harness import Context, Failure, Skip  # noqa: E402


def discover() -> list[str]:
    """The check names, in the order they run."""
    return sorted(path.stem for path in CHECKS.glob("*.py") if not path.stem.startswith("_"))


def run_one(name: str, ctx: Context) -> tuple[str, str]:
    """Run one check. Returns (verdict, detail) with verdict PASS, FAIL or SKIP."""
    module = importlib.import_module(f"checks.{name}")
    try:
        module.run(ctx)
    except Skip as exc:
        return "SKIP", str(exc)
    except Failure as exc:
        return "FAIL", str(exc)
    return "PASS", ""


def main(argv: list[str]) -> int:
    parser = argparse.ArgumentParser(description=__doc__,
                                     formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("names", nargs="*", help="run only the checks whose name is listed")
    parser.add_argument("--list", action="store_true", help="print the check names and exit")
    args = parser.parse_args(argv)

    names = discover()
    if args.names:
        unknown = [one for one in args.names if one not in names]
        if unknown:
            parser.error(f"no such check: {', '.join(unknown)}")
        names = [one for one in names if one in args.names]
    if args.list:
        print("\n".join(names))
        return 0

    failed = 0
    with tempfile.TemporaryDirectory(prefix="weewx-php-conformance-") as work:
        ctx = Context(root=ROOT, work=Path(work))
        for name in names:
            print(f"== {name}")
            started = time.monotonic()
            verdict, detail = run_one(name, ctx)
            seconds = time.monotonic() - started
            tail = f"  {detail}" if detail else ""
            print(f"{verdict} {name} ({seconds:.1f}s){tail}")
            if verdict == "FAIL":
                failed += 1
    print()
    print(f"{failed} check(s) failed" if failed else "all checks passed")
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
