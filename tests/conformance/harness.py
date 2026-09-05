"""What every conformance check is handed, and how it reports.

In its own module rather than in run.py: a check imports this, and run.py
is the program. Imported from a program that is itself `__main__`, a class
would exist twice, and an exception raised as one would not be caught as
the other.
"""

from __future__ import annotations

import json
import subprocess
from dataclasses import dataclass
from pathlib import Path


class Failure(Exception):
    """A check found a difference. The message says which."""


class Skip(Exception):
    """A check cannot run here. The message says what is missing."""


@dataclass(frozen=True)
class Context:
    """What every check gets handed."""

    root: Path
    work: Path
    php: str = "php"

    def run_php(self, script: str, *args: str, stdin: str | None = None) -> str:
        """Run one helper from tests/conformance/php and return its stdout.

        Args:
            script: File name of the helper, e.g. 'units.php'.
            args: Arguments after the script name.
            stdin: Text to hand the helper on its standard input, if any.

        Raises:
            Failure: If the helper exits with a non-zero status. Its stderr is
                part of the message, because that is where PHP puts the reason.
        """
        command = [self.php, str(self.root / "tests" / "conformance" / "php" / script), *args]
        done = subprocess.run(command, input=stdin, capture_output=True, text=True, check=False)
        if done.returncode != 0:
            raise Failure(f"{script} exited {done.returncode}: {done.stderr.strip()}")
        return done.stdout

    def php_json(self, script: str, *args: str, stdin: str | None = None) -> object:
        """Run a helper that prints JSON, and decode it."""
        text = self.run_php(script, *args, stdin=stdin)
        try:
            return json.loads(text)
        except json.JSONDecodeError as exc:
            raise Failure(f"{script} did not print JSON: {exc}\n{text[:400]}") from exc
