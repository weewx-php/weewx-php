# Copyright (c) 2026 Manuel Hilgert <hilman2@gmail.com>

from pathlib import Path
import re
import shutil
import subprocess
import sys
import tempfile
import unittest
from zipfile import ZipFile


ROOT = Path(__file__).resolve().parents[2]


class WidgetPackageTest(unittest.TestCase):
    def test_widget_package_contains_its_module_dependencies(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            project = Path(directory)
            shutil.copytree(ROOT / 'examples/wordpress', project / 'examples/wordpress')
            shutil.copytree(ROOT / 'public/assets', project / 'public/assets')
            shutil.copyfile(ROOT / 'LICENSE', project / 'LICENSE')
            subprocess.run([sys.executable, str(project / 'examples/wordpress/package.py')],
                           check=True, capture_output=True)
            with ZipFile(project / 'data/artifacts/weewx-weather.zip') as archive:
                for name in archive.namelist():
                    if name.endswith('.js'):
                        source = archive.read(name).decode('utf-8')
                        for module in re.findall(r"from ['\"]\./([^'\"]+)['\"]", source):
                            self.assertIn(f'weewx-weather/assets/{module}', archive.namelist())
