"""The derived readings' formulas, against `weewx.wxformulas`.

A grid of inputs through both sides, compared exactly wherever the
expressions are transcribed one for one, and to a stated tolerance where
WeeWX takes a road this program cannot: the solar elevation, which WeeWX
gets from pyephem and this program from NOAA's arithmetic.

Evapotranspiration is compared with the day of year and time of day
handed in explicitly, because WeeWX derives them from the process clock's
zone and that is the container's; the PHP side derives them the same way
from the archive's zone, and that derivation is checked in PHPUnit.
"""

from __future__ import annotations

import json
import math
import time

from harness import Context, Failure

TEMPS_F = (-40.0, 10.0, 32.0, 45.0, 68.0, 80.0, 86.0, 95.0, 100.0, 112.0)
TEMPS_C = (-40.0, -10.0, 0.0, 7.5, 20.0, 26.7, 30.0, 35.0, 44.4)
HUMIDITIES = (0.0, 5.0, 12.0, 40.0, 50.0, 85.0, 90.0, 100.0)
WINDS = (0.0, 2.0, 3.0, 3.1, 8.5, 25.0)
PRESSURES_INHG = (0.001, 24.692, 28.0, 29.921, 30.5)
PRESSURES_MBAR = (0.2, 836.0, 948.08, 1013.25, 1032.7)
ALTITUDES_M = (0.0, 304.8, 440.0, 1655.0)
SOLAR_TOLERANCE = 0.5          # W/m2, of values up to a thousand


def run(ctx: Context) -> None:
    import weewx.wxformulas as wf
    from weewx.uwxutils import uWxUtilsVP

    calls: list[tuple[str, list, object]] = []

    def call(name: str, function, *args) -> None:
        calls.append((name, list(args), function(*args)))

    for t in TEMPS_F:
        for rh in HUMIDITIES:
            call("dewpointF", wf.dewpointF, t, rh)
            call("heatindexF", wf.heatindexF, t, rh)
            call("humidexF", wf.humidexF, t, rh)
            for v in WINDS:
                call("apptempF", wf.apptempF, t, rh, v)
            for alt in (0.0, 1443.57):
                call("cloudbase_US", wf.cloudbase_US, t, rh, alt)
        for v in WINDS:
            call("windchillF", wf.windchillF, t, v)
    for t in TEMPS_C:
        for rh in HUMIDITIES:
            call("dewpointC", wf.dewpointC, t, rh)
            call("heatindexC", wf.heatindexC, t, rh)
            call("humidexC", wf.humidexC, t, rh)
            for v in WINDS:
                call("apptempC", wf.apptempC, t, rh, v)
            for alt in ALTITUDES_M:
                call("cloudbase_Metric", wf.cloudbase_Metric, t, rh, alt)
        for v in WINDS:
            call("windchillMetric", wf.windchillMetric, t, v)
            call("windchillMetricWX", wf.windchillMetricWX, t, v)
    call("dewpointF", wf.dewpointF, None, 50.0)
    call("humidexC", wf.humidexC, 30.0, None)
    call("apptempC", wf.apptempC, 20.0, 101.0, 1.0)
    call("windchillF", wf.windchillF, 20.0, None)

    for p in PRESSURES_INHG:
        for alt in (0.0, 1000.0, 1443.57, 5431.0):
            call("altimeter_pressure_US", wf.altimeter_pressure_US, p, alt)
            for t in (-10.0, 59.0, 95.0):
                call("sealevel_pressure_US", wf.sealevel_pressure_US, p, alt, t)
                if p > 1.0:
                    for t12 in (40.5, 59.0):
                        for rh in (0.0, 40.5, 90.0):
                            call("SeaLevelToSensorPressure_12", uWxUtilsVP.SeaLevelToSensorPressure_12,
                                 p, alt, t, t12, rh)
    # Temperatures that land on a half after uwxutils' `- 0.01`: Python's
    # round() takes those to the even neighbour, PHP's away from zero, and
    # a mean temperature one degree off moves the pressure.
    for t, t12 in ((60.51, 59.0), (59.0, 60.51), (60.51, 62.51), (-0.49, 0.51), (61.51, 59.0)):
        call("SeaLevelToSensorPressure_12", uWxUtilsVP.SeaLevelToSensorPressure_12, 29.921, 1000.0, t, t12, 40.5)
    for p in PRESSURES_MBAR:
        for alt in ALTITUDES_M:
            call("altimeter_pressure_Metric", wf.altimeter_pressure_Metric, p, alt)
            for t in (-20.0, 15.0, 35.0):
                call("sealevel_pressure_Metric", wf.sealevel_pressure_Metric, p, alt, t)

    # Evapotranspiration, with the day of year and the UTC time of day worked
    # out here the way WeeWX works them out, then handed to the PHP side.
    et_cases = []
    for stamp in (1475337600, 1475294400, 1469829600, 1787734200):
        doy = time.localtime(stamp)[7] - 1
        utc = time.gmtime(stamp)
        tod = utc.tm_hour + utc.tm_min / 60.0 + utc.tm_sec / 3600.0
        for tmin, tmax, rhmin, rhmax, rad, wind in ((38.0, 38.0, 52.0, 52.0, 680.56, 3.3), (28.0, 28.0, 90.0, 90.0, 0.0, 3.3),
                                                    (12.5, 21.0, 40.0, 95.0, 320.0, 0.0), (-5.0, 2.0, 70.0, 99.0, 50.0, 12.0)):
            want = wf.evapotranspiration_Metric(tmin, tmax, rhmin, rhmax, rad, wind, 2.0, 16.217, -16.25, 8.0, stamp)
            et_cases.append(("evapotranspiration_Metric", [tmin, tmax, rhmin, rhmax, rad, wind, 2.0, 16.217, -16.25, 8.0, doy, tod], want))
            want = wf.evapotranspiration_US(87.8, 89.1, 34.0, 38.0, 860.0, 9.58, 6.0, 45.7, -121.5, 700.0, stamp)
            et_cases.append(("evapotranspiration_US", [87.8, 89.1, 34.0, 38.0, 860.0, 9.58, 6.0, 45.7, -121.5, 700.0, doy, tod], want))
    calls += et_cases

    # Clear-sky radiation: WeeWX places the sun with pyephem, this program
    # with NOAA's arithmetic, so a tolerance rather than equality. Every
    # hour of a day, so that the sun passes the horizon, where the value
    # is smallest and its sensitivity to the elevation greatest.
    solar_calls = []
    for stamp in range(1782907200, 1782907200 + 86400, 3600):
        for lat, lon, alt in ((48.4596, 11.6539, 440.0), (-41.29, 174.78, 10.0)):
            solar_calls.append(("solar_rad_RS", [lat, lon, alt, stamp, 0.8], wf.solar_rad_RS(lat, lon, alt, stamp, 0.8)))

    answer = ctx.php_json("formulas.php", stdin=json.dumps(
        {"calls": [{"name": name, "args": args} for name, args, _ in calls + solar_calls]}))
    if not isinstance(answer, dict):
        raise Failure("formulas.php did not answer with an object")
    got = answer["answers"]

    problems: list[str] = []
    for (name, args, want), ours in zip(calls, got[:len(calls)], strict=True):
        if not _same(want, ours):
            problems.append(f"{name}{tuple(args)}: WeeWX {want!r}, PHP {ours!r}")
    worst = 0.0
    for (name, args, want), ours in zip(solar_calls, got[len(calls):], strict=True):
        if want is None or ours is None:
            problems.append(f"{name}{tuple(args)}: WeeWX {want!r}, PHP {ours!r}")
            continue
        off = abs(want - ours)
        worst = max(worst, off)
        if off > SOLAR_TOLERANCE:
            problems.append(f"{name}{tuple(args)}: WeeWX {want:.3f}, PHP {ours:.3f}")
    print(f"  {len(calls)} formula values compared exactly")
    print(f"  {len(solar_calls)} clear-sky radiations within {SOLAR_TOLERANCE} W/m2 of WeeWX with pyephem; "
          f"worst {worst:.3f}")
    if problems:
        raise Failure("\n  ".join([f"{len(problems)} difference(s):", *problems[:25]]))


def _same(want: object, got: object) -> bool:
    if want is None or got is None:
        return want is None and got is None
    if isinstance(want, (int, float)) and isinstance(got, (int, float)):
        if math.isnan(float(want)):
            return isinstance(got, float) and math.isnan(got)
        return float(want) == float(got)
    return want == got
