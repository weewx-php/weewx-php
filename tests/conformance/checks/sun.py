"""The sun's position, against pyephem.

Six places from Tromso to Ushuaia, five days that include the four turning
points of the year, every hour. The elevation feeds `maxSolarRad`, and the
radiation depends on the sine of it, so the tolerance is stated in degrees
of elevation and the check also says how far off the worst hour was.

Twice over: the geometric elevation against pyephem with refraction turned
off, and the apparent one against pyephem at the pressure and temperature
WeeWX's almanac assumes, which is what WeeWX hands to `solar_rad_RS`.
"""

from __future__ import annotations

import json
import math

from harness import Context, Failure

PLACES = [("Kirchdorf", 48.4596, 11.6539), ("Seattle", 47.6, -122.3), ("Tromso", 69.65, 18.96),
          ("Quito", -0.18, -78.47), ("Wellington", -41.29, 174.78), ("Ushuaia", -54.8, -68.3)]
DAYS = ["2026/03/20", "2026/06/21", "2026/09/22", "2026/12/21", "2026/08/26"]
ELEVATION_TOLERANCE = 0.05     # degrees
DISTANCE_TOLERANCE = 0.0002    # astronomical units; squared, that is 0.04 % of the radiation
ALMANAC_PRESSURE = 1010.0      # what weewx.almanac.Almanac assumes, in mbar
ALMANAC_TEMPERATURE = 15.0     # and in degrees Celsius


def run(ctx: Context) -> None:
    import ephem

    epoch = ephem.Date("1970/1/1 00:00:00")
    times = [int((ephem.Date(f"{day} {hour:02d}:00:00") - epoch) * 86400.0)
             for day in DAYS for hour in range(24)]
    answer = ctx.php_json("sun.php", stdin=json.dumps(
        {"places": [[lat, lon] for _, lat, lon in PLACES], "times": times}))
    if not isinstance(answer, dict):
        raise Failure("sun.php did not answer with an object")

    worst_elevation = 0.0
    worst_apparent = 0.0
    worst_distance = 0.0
    problems: list[str] = []
    for (name, lat, lon), row in zip(PLACES, answer["positions"], strict=True):
        geometric = ephem.Observer()
        geometric.lat = str(lat)
        geometric.lon = str(lon)
        geometric.pressure = 0
        almanac = ephem.Observer()
        almanac.lat = str(lat)
        almanac.lon = str(lon)
        almanac.pressure = ALMANAC_PRESSURE
        almanac.temp = ALMANAC_TEMPERATURE
        for when, (elevation, distance, apparent) in zip(times, row, strict=True):
            geometric.date = ephem.Date(epoch + when / 86400.0)
            almanac.date = geometric.date
            sun = ephem.Sun(geometric)
            want_elevation = math.degrees(float(sun.alt))
            want_distance = float(sun.earth_distance)
            want_apparent = math.degrees(float(ephem.Sun(almanac).alt))
            off = abs(want_elevation - elevation)
            off_apparent = abs(want_apparent - apparent)
            worst_elevation = max(worst_elevation, off)
            worst_apparent = max(worst_apparent, off_apparent)
            worst_distance = max(worst_distance, abs(want_distance - distance))
            if off > ELEVATION_TOLERANCE:
                problems.append(f"{name} at {when}: pyephem {want_elevation:.4f}, PHP {elevation:.4f}")
            if off_apparent > ELEVATION_TOLERANCE:
                problems.append(f"{name} at {when}: apparent pyephem {want_apparent:.4f}, PHP {apparent:.4f}")
            if abs(want_distance - distance) > DISTANCE_TOLERANCE:
                problems.append(f"{name} at {when}: distance pyephem {want_distance:.6f}, PHP {distance:.6f}")
    print(f"  {len(PLACES)} places, {len(times)} moments each")
    print(f"  worst elevation {worst_elevation:.4f} deg, refracted {worst_apparent:.4f} deg, "
          f"distance {worst_distance:.6f} AU")
    if problems:
        raise Failure("\n  ".join([f"{len(problems)} difference(s):", *problems[:20]]))
