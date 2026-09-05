"""The derived readings, against WeeWX's own `StdWXCalculate` machinery.

WeeWX's four xtypes -- the plain formulas, the pressure cooker, the rain
rater and the counter delta -- are instantiated the way the engine
instantiates them, over an archive this program wrote, and driven through
the very loop `StdWXCalculate.do_calculations` runs. The same packets go
through `Derived` on the PHP side, in the same order, with the same
archive to look back into. Every derived value is compared exactly, in the
US and the METRICWX system, on loop packets and on finished records.

Two readings are compared to a tolerance or not at all: `maxSolarRad`,
because WeeWX places the sun with pyephem and this program with its own
arithmetic; and `rain` across a counter reset, where this program follows
weewx-evo and books the new total while WeeWX books nothing. The packets
here have no reset, so `rain` is compared exactly everywhere.
"""

from __future__ import annotations

import json
import math
import time

from harness import Context, Failure

LATITUDE = 48.4596
LONGITUDE = 11.6539
ALTITUDE_M = 440.0
INTERVAL = 300
SOLAR_TOLERANCE = 0.5

# The order of WeeWX's default configuration, `rain` in front as this
# program has it; where it sits does not matter to WeeWX, whose rain
# rater only sees a packet after the calculations.
CALCULATIONS = [("rain", "prefer_hardware"), ("pressure", "prefer_hardware"), ("altimeter", "prefer_hardware"),
                ("appTemp", "prefer_hardware"), ("barometer", "prefer_hardware"), ("cloudbase", "prefer_hardware"),
                ("dewpoint", "prefer_hardware"), ("ET", "prefer_hardware"), ("heatindex", "prefer_hardware"),
                ("humidex", "prefer_hardware"), ("inDewpoint", "prefer_hardware"), ("maxSolarRad", "prefer_hardware"),
                ("rainRate", "prefer_hardware"), ("windchill", "prefer_hardware"), ("windrun", "prefer_hardware"),
                ("windDir", "software"), ("windGustDir", "software")]


def run(ctx: Context) -> None:
    import weewx

    problems: list[str] = []
    compared = 0
    for name, units in (("US", weewx.US), ("METRICWX", weewx.METRICWX)):
        found, count = _one_system(ctx, name, units)
        problems += found
        compared += count
        print(f"  {name}: {count} derived values compared")
    if problems:
        raise Failure("\n  ".join([f"{len(problems)} difference(s):", *problems[:30]]))


def _one_system(ctx: Context, name: str, units: int) -> tuple[list[str], int]:
    import weewx.manager
    import weewx.units
    import weewx.wxxtypes
    import weewx.xtypes

    path = ctx.work / f"derive-{name}.sdb"
    ctx.php_json("archive.php", "create", str(path))
    start = int(time.mktime((2026, 6, 14, 0, 0, 0, 0, 0, -1)))
    stored = _stored(start, units)
    answer = ctx.php_json("archive.php", "add-batch", str(path), stdin=json.dumps(stored))
    if answer != {"written": len(stored)}:
        return [f"{name}: expected {len(stored)} records stored, got {answer!r}"], 0

    first = start + 25 * 3600
    packets = _packets(first, units)
    records = _records(first + 60, units)

    altitude_vt = weewx.units.ValueTuple(ALTITUDE_M, "meter", "group_altitude")
    rater = weewx.wxxtypes.RainRater()
    ours = [weewx.wxxtypes.WXXTypes(altitude_vt, LATITUDE, LONGITUDE),
            weewx.wxxtypes.ETXType(altitude_vt, LATITUDE, LONGITUDE),
            weewx.wxxtypes.PressureCooker(altitude_vt),
            rater,
            weewx.wxxtypes.Delta({"rain": {"input": "dayRain"}})]
    saved = list(weewx.xtypes.xtypes)
    weewx.xtypes.xtypes.extend(ours)
    settings = {"SQLITE_ROOT": str(path.parent), "database_name": path.name, "driver": "weedb.sqlite"}
    manager = weewx.manager.Manager.open(settings)
    try:
        theirs_packets = []
        for packet in packets:
            data = dict(packet["data"])
            _calculate(data, manager)
            # StdRainRater is an xtype service and hears the packet after
            # StdWXCalculate, a process service, has finished with it.
            rater.add_loop_packet(data)
            theirs_packets.append(data)
        theirs_records = [_calculate(dict(record), manager) for record in records]
    finally:
        manager.close()
        weewx.xtypes.xtypes[:] = saved

    answer = ctx.php_json("derive.php", stdin=json.dumps({
        "archive": str(path),
        "site": {"latitude": LATITUDE, "longitude": LONGITUDE, "altitude": [ALTITUDE_M, "meter"]},
        "timezone": time.tzname[0] if not time.daylight else _zone_name(),
        "packets": packets,
        "records": records,
    }))
    if not isinstance(answer, dict):
        return [f"{name}: derive.php did not answer with an object"], 0

    problems: list[str] = []
    count = 0
    for label, theirs, mine in (("packet", theirs_packets, answer["packets"]), ("record", theirs_records, answer["records"])):
        if len(theirs) != len(mine):
            problems.append(f"{name}: {len(theirs)} {label}s from WeeWX, {len(mine)} from PHP")
            continue
        for index, (want, got) in enumerate(zip(theirs, mine, strict=True)):
            for key in sorted(set(want) | set(got)):
                count += 1
                a, b = want.get(key), got.get(key)
                if key == "maxSolarRad" and a is not None and b is not None:
                    if abs(a - b) > SOLAR_TOLERANCE:
                        problems.append(f"{name} {label} {index} {key}: WeeWX {a!r}, PHP {b!r}")
                elif not _same(a, b):
                    problems.append(f"{name} {label} {index} {key}: WeeWX {a!r}, PHP {b!r}")
    return problems, count


def _calculate(data: dict, manager) -> dict:
    """`StdWXCalculate.do_calculations`, line for line."""
    import weewx
    import weewx.units
    import weewx.xtypes

    for obs_type, how in CALCULATIONS:
        if how == "software" or (how == "prefer_hardware" and data.get(obs_type) is None):
            try:
                value = weewx.xtypes.get_scalar(obs_type, data, manager)
            except weewx.CannotCalculate:
                data[obs_type] = None
            except weewx.NoCalculate:
                pass
            except (weewx.UnknownType, weewx.UnknownAggregation):
                pass
            else:
                data[obs_type] = weewx.units.convertStd(value, data["usUnits"])[0]
    return data


def _zone_name() -> str:
    """The container's zone by name, which is what the PHP side is handed."""
    import os

    return os.environ.get("TZ") or "UTC"


def _stored(start: int, units: int) -> list[dict]:
    """Twenty-six hours of records, so that every packet has a temperature
    twelve hours back and an hour of aggregates before it. No rain in the
    last two hours: WeeWX would seed its rain rater from those rows, and
    this program from the packets, so neither side gets any."""
    us = units == 1
    made = []
    steps = int(26 * 3600 / INTERVAL)
    for step in range(steps):
        when = start + (step + 1) * INTERVAL
        swing = math.sin(step / 30.0)
        rain = (0.01 if us else 0.2) if step % 40 == 0 and step < steps - 30 else 0.0
        made.append({
            "dateTime": when, "usUnits": units, "interval": INTERVAL // 60,
            "outTemp": (55.0 + swing * 12.0) if us else (13.0 + swing * 7.0),
            "outHumidity": 60.0 + swing * 15.0,
            "barometer": (29.9 + swing * 0.2) if us else (1012.0 + swing * 7.0),
            "windSpeed": (4.0 + abs(swing) * 6.0) if us else (1.8 + abs(swing) * 2.7),
            "radiation": max(0.0, 600.0 * math.sin(math.pi * ((when - start) % 86400) / 86400.0)),
            "rain": rain,
        })
    return made


def _packets(first: int, units: int) -> list[dict]:
    """Forty loop packets sixteen seconds apart: a barometer and a rain
    counter but no station pressure and no rain, calm spells, a missing
    humidity and a missing wind speed."""
    us = units == 1
    made = []
    counter = 0.0
    for i in range(40):
        if i in (5, 6, 20):
            counter += 0.01 if us else 0.2
        calm = i % 7 == 0
        data = {
            "dateTime": first + i * 16, "usUnits": units,
            "outTemp": (68.0 + 3.0 * math.sin(i / 5.0)) if us else (18.0 + 3.0 * math.sin(i / 5.0)),
            "outHumidity": min(100.0, 55.0 + i),
            "windSpeed": 0.0 if calm else ((3.0 + i * 0.25) if us else (1.5 + i * 0.1)),
            "windDir": (i * 13) % 360,
            "windGust": 0.0 if i % 11 == 0 else ((5.0 + i * 0.25) if us else (2.5 + i * 0.1)),
            "windGustDir": (i * 17) % 360,
            "barometer": (29.92 + i * 0.001) if us else (1013.2 + i * 0.03),
            "dayRain": counter,
            "inTemp": 70.0 if us else 21.0, "inHumidity": 45.0,
            "radiation": 300.0 + i * 5.0,
        }
        if i == 9:
            del data["outHumidity"]
        if i == 15:
            data["windSpeed"] = None
        made.append({"sender": "primary", "data": data})
    return made


def _records(first: int, units: int) -> list[dict]:
    """Three finished records, none at a stored timestamp, with rain but no
    rate, a barometer but no pressure."""
    us = units == 1
    made = []
    for k in range(3):
        made.append({
            "dateTime": first + k * INTERVAL, "usUnits": units, "interval": INTERVAL // 60,
            "outTemp": (66.0 + k) if us else (19.0 + k), "outHumidity": 58.0 + k,
            "windSpeed": (5.5 + k) if us else (2.4 + k * 0.4), "windDir": 200 + k,
            "windGust": (9.0 + k) if us else (4.0 + k), "windGustDir": 210 + k,
            "barometer": (29.95 + k * 0.01) if us else (1014.0 + k * 0.3),
            "rain": (0.02 if us else 0.4) if k == 1 else 0.0,
            "inTemp": 70.0 if us else 21.0, "inHumidity": 45.0,
            "radiation": 400.0 + k * 10.0,
        })
    return made


def _same(a: object, b: object) -> bool:
    if a is None or b is None:
        return a is None and b is None
    if isinstance(a, (int, float)) and isinstance(b, (int, float)):
        if math.isnan(float(a)):
            return isinstance(b, float) and math.isnan(b)
        return float(a) == float(b)
    return a == b
