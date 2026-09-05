"""The image is what the other checks assume it is.

Not a check of our code: a check that a green run means something. A WeeWX
other than the one the arithmetic was transcribed from, or a PHP without the
SQLite driver, would let every other check pass for the wrong reason or skip
without saying why.
"""

from __future__ import annotations

from harness import Context, Failure

WANTED_WEEWX = "5.5.0"


def run(ctx: Context) -> None:
    import weewx

    version = getattr(weewx, "__version__", "?")
    print(f"  WeeWX {version}")
    if version != WANTED_WEEWX:
        raise Failure(f"WeeWX is {version}, the transcription is from {WANTED_WEEWX}")

    import ephem

    print(f"  ephem {ephem.__version__}")

    report = ctx.run_php("environment.php")
    print("  " + report.strip().replace("\n", "\n  "))
    if "sqlite3 yes" not in report:
        raise Failure("PHP has no sqlite3 extension")
