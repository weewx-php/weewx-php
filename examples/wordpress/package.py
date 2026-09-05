"""Build the installable plugin from the single maintained widget implementation."""
from pathlib import Path
from zipfile import ZipFile, ZIP_DEFLATED

root = Path(__file__).resolve().parents[2]
output = root / 'data/artifacts/weewx-weather.zip'
output.parent.mkdir(parents=True, exist_ok=True)
with ZipFile(output, 'w', ZIP_DEFLATED) as archive:
    for path in sorted((root / 'examples/wordpress/weewx-weather').iterdir()):
        if path.is_file():
            archive.write(path, f'weewx-weather/{path.name}')
    for name in ['weather-widget.js', 'weather-widget.css', 'feed-client.js']:
        archive.write(root / 'public/assets' / name, f'weewx-weather/assets/{name}')
    archive.write(root / 'LICENSE', 'weewx-weather/LICENSE')
print(output)
