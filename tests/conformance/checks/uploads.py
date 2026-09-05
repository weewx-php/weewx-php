"""What the uploads would send, against what WeeWX and weewx-evo send.

WeeWX's `restx` is the reference for the Ambient protocol, for WOW, for
the CWOP packet and for the three rain sums a service is given: the same
records through `AmbientThread.format_url`, `WOWThread.format_url`,
`CWOPThread.get_tnc_packet` and `RESTThread.get_record`, compared
parameter for parameter and character for character. What is ours by
design is left out of the comparison: the `softwaretype`, the APRS tocall
and the equipment tag, and the gust direction Weather Underground takes
though WeeWX does not send it.

Windy, Weathercloud, InfluxDB and MQTT have no WeeWX reference; their
shapes are weewx-evo's. When weewx-evo's package is mounted at /evo, the
same records go through its Python modules and are compared as well;
without it that half is noted and skipped, and the WeeWX half decides.
"""

from __future__ import annotations

import importlib
import json
import math
import queue
import re
import sys
import time
import urllib.parse
from pathlib import Path

from harness import Context, Failure

EVO = Path("/evo")
STATION = {"id": "DW1234", "password": "sec ret&", "latitude": 48.4596, "longitude": 11.6539}
OURS = ("softwaretype",)
INTERVAL = 300


def run(ctx: Context) -> None:
    import weewx.restx
    import weewx.units

    records = _records()
    path = ctx.work / "uploads.sdb"
    start = int(time.mktime((2026, 6, 14, 0, 0, 0, 0, 0, -1)))
    stored = _stored(start)
    ctx.php_json("archive.php", "create", str(path))
    ctx.php_json("archive.php", "add-batch", str(path), stdin=json.dumps(stored))
    times = [start + 3600, start + 12 * 3600, start + 86400, start + 86400 + 300, start + 90000]

    answer = ctx.php_json("uploads.php", stdin=json.dumps({
        "records": records, "station": STATION,
        "archive": {"path": str(path), "usUnits": 1, "times": times}}))
    if not isinstance(answer, dict):
        raise Failure("uploads.php did not answer with an object")
    ours = answer["records"]
    problems: list[str] = []

    # -- WeeWX --------------------------------------------------------------
    wu = weewx.restx.AmbientThread(queue.Queue(), None, STATION["id"], STATION["password"],
                                   weewx.restx.StdWunderground.pws_url, post_indoor_observations=True)
    wow = weewx.restx.WOWThread(queue.Queue(), None, STATION["id"], STATION["password"],
                                weewx.restx.StdWOW.archive_url)
    cwop = weewx.restx.CWOPThread(queue.Queue(), None, STATION["id"], "-1",
                                  STATION["latitude"], STATION["longitude"], "Test")
    compared = 0
    for index, (record, mine) in enumerate(zip(records, ours, strict=True)):
        theirs = _pairs(wu.format_url(record))
        problems += _compare_query(f"record {index} WU", theirs, mine["wu"], extra_ours={"windgustdir"})
        compared += len(theirs)
        theirs = _pairs(wow.format_url(record))
        problems += _compare_query(f"record {index} WOW", theirs, mine["wow"])
        compared += len(theirs)
        packet = cwop.get_tnc_packet(weewx.units.to_US(record)).strip()
        if _body(packet) != _body(mine["cwop"]):
            problems.append(f"record {index} CWOP: WeeWX {packet!r}, PHP {mine['cwop']!r}")
        compared += 1
    print(f"  {len(records)} records: {compared} Ambient parameters and CWOP packets compared with weewx.restx")

    # The rain sums, through the manager WeeWX hands its threads.
    import weewx.manager
    settings = {"SQLITE_ROOT": str(path.parent), "database_name": path.name, "driver": "weedb.sqlite"}
    thread = weewx.restx.RESTThread(queue.Queue(), "check")
    manager = weewx.manager.Manager.open(settings)
    try:
        for when in times:
            theirs = thread.get_record({"dateTime": when, "usUnits": 1}, manager)
            mine = answer["rain"][str(when)]
            for name in ("hourRain", "rain24", "dayRain"):
                if not _same(theirs.get(name), mine.get(name)):
                    problems.append(f"rain at {when} {name}: WeeWX {theirs.get(name)!r}, PHP {mine.get(name)!r}")
    finally:
        manager.close()
    print(f"  {len(times)} moments: hourRain, rain24 and dayRain compared with RESTThread.get_record")

    # -- weewx-evo, where it is mounted ---------------------------------------
    if (EVO / "weewx_evo" / "uploads").is_dir():
        problems += _against_evo(records, ours)
    else:
        print("  weewx-evo is not mounted at /evo; Windy, Weathercloud, InfluxDB and MQTT are compared with its modules only when it is")

    if problems:
        raise Failure("\n  ".join([f"{len(problems)} difference(s):", *problems[:30]]))


def _against_evo(records: list[dict], ours: list[dict]) -> list[str]:
    sys.path.insert(0, str(EVO))
    windy_module = importlib.import_module("weewx_evo.uploads.windy")
    weathercloud_module = importlib.import_module("weewx_evo.uploads.weathercloud")
    influx_module = importlib.import_module("weewx_evo.uploads.influx")
    mqtt_module = importlib.import_module("weewx_evo.uploads.mqtt")
    homeassistant = importlib.import_module("weewx_evo.uploads.homeassistant")

    windy = windy_module.WindyUpload(api_key=STATION["password"])
    weathercloud = weathercloud_module.WeathercloudUpload(wid=STATION["id"], key=STATION["password"], indoor=True)
    influx = influx_module.InfluxUpload(url="http://influxdb:8086", bucket="weewx", token=STATION["password"],
                                        location="Kirchdorf an der Amper")
    mqtt = mqtt_module.MqttUpload(host="broker", client_id="c")
    problems: list[str] = []
    compared = 0
    for index, (record, mine) in enumerate(zip(records, ours, strict=True)):
        theirs = windy._observation(record)
        problems += _compare_dict(f"record {index} Windy", theirs, mine["windy"])
        compared += len(theirs)
        theirs = _pairs(weathercloud._query(record))
        problems += _compare_query(f"record {index} Weathercloud", theirs, mine["weathercloud"], ignore={"ver"})
        compared += len(theirs)
        line = influx.line(record)
        if not _same_line(line, mine["influx"]):
            problems.append(f"record {index} InfluxDB: evo {line!r}, PHP {mine['influx']!r}")
        compared += 1
        theirs = mqtt.message(record)
        problems += _compare_dict(f"record {index} MQTT", theirs, mine["mqtt"])
        compared += len(theirs)
    topic, payload = homeassistant.discovery("outTemp", "degree_C", "weather", "outTemp_C", "Kirchdorf an der Amper")
    mine_topic, mine_payload = ours[0]["discovery"]
    expected = json.loads(payload)
    got = json.loads(mine_payload)
    for key in ("name", "state_topic", "value_template", "device_class", "unit_of_measurement", "state_class", "expire_after"):
        if expected.get(key) != got.get(key):
            problems.append(f"Home Assistant discovery {key}: evo {expected.get(key)!r}, PHP {got.get(key)!r}")
    if topic.replace("weewx_evo", "weewx_php") != mine_topic:
        problems.append(f"Home Assistant topic: evo {topic!r}, PHP {mine_topic!r}")
    print(f"  {len(records)} records: {compared} Windy, Weathercloud, InfluxDB and MQTT values compared with weewx-evo")
    return problems


def _records() -> list[dict]:
    """Records in both unit systems, with gaps: readings a station may not have."""
    when = 1787734200
    full_us = {"dateTime": when, "usUnits": 1, "interval": 5, "outTemp": 68.35, "outHumidity": 61.0, "dewpoint": 54.02,
               "barometer": 29.921, "altimeter": 29.93, "pressure": 28.5, "windSpeed": 11.25, "windDir": 180.0,
               "windGust": 17.9, "windGustDir": 190.0, "rain": 0.02, "hourRain": 0.125, "rain24": 0.47,
               "dayRain": 0.235, "rainRate": 0.12, "radiation": 300.5, "UV": 4.25, "inTemp": 72.5,
               "inHumidity": 44.0, "soilTemp1": 55.5, "soilMoist1": 12.0, "leafWet1": 3.0, "pm2_5": 7.45,
               "windchill": 68.35, "heatindex": 69.3, "ET": 0.01}
    full_metric = {"dateTime": when + 300, "usUnits": 17, "interval": 5, "outTemp": 20.15, "outHumidity": 61.0,
                   "dewpoint": 12.25, "barometer": 1013.25, "altimeter": 1013.6, "windSpeed": 5.05, "windDir": 7.0,
                   "windGust": 8.5, "windGustDir": 350.0, "rain": 0.4, "hourRain": 2.35, "rain24": 12.05,
                   "dayRain": 5.75, "rainRate": 3.1, "radiation": 1200.5, "UV": 0.0, "inTemp": 21.0,
                   "inHumidity": 45.0, "windchill": 20.15, "heatindex": 20.15}
    calm_us = {"dateTime": when + 600, "usUnits": 1, "interval": 5, "outTemp": -5.4, "outHumidity": 99.6,
               "barometer": 30.5, "altimeter": 30.52, "windSpeed": 0.0}
    sparse_metric = {"dateTime": when + 900, "usUnits": 16, "interval": 5, "outTemp": 2.5, "windSpeed": 10.0,
                     "radiation": 999.5, "outHumidity": 100.0}
    return [full_us, full_metric, calm_us, sparse_metric]


def _stored(start: int) -> list[dict]:
    made = []
    for step in range(int(26 * 3600 / INTERVAL)):
        when = start + (step + 1) * INTERVAL
        rain = 0.01 if step % 7 == 0 else (0.02 if step % 50 == 3 else 0.0)
        made.append({"dateTime": when, "usUnits": 1, "interval": INTERVAL // 60, "outTemp": 60.0, "rain": rain})
    return made


def _pairs(url: str) -> dict[str, str]:
    """The query of a URL as pairs; a plus is a space, as a form-encoded query has it."""
    query = urllib.parse.urlsplit(url).query
    found = {}
    for part in query.split("&"):
        name, _, value = part.partition("=")
        found[urllib.parse.unquote_plus(name)] = urllib.parse.unquote_plus(value)
    return found


def _compare_query(label: str, theirs: dict, mine: dict, extra_ours: set[str] = frozenset(),
                   ignore: set[str] = frozenset()) -> list[str]:
    problems = []
    for name, value in theirs.items():
        if name in OURS or name in ignore:
            continue
        if str(mine.get(name)) != value:
            problems.append(f"{label} {name}: reference {value!r}, PHP {mine.get(name)!r}")
    for name in mine:
        if name not in theirs and name not in OURS and name not in extra_ours and name not in ignore:
            problems.append(f"{label} {name}: PHP sends it, the reference does not")
    return problems


def _compare_dict(label: str, theirs: dict, mine: dict) -> list[str]:
    problems = []
    for name in sorted(set(theirs) | set(mine)):
        if not _same(theirs.get(name), mine.get(name)):
            problems.append(f"{label} {name}: evo {theirs.get(name)!r}, PHP {mine.get(name)!r}")
    return problems


def _body(packet: str) -> str:
    """A CWOP packet without the parts that name the program: from the timestamp to the last reading."""
    _prefix, _, rest = packet.partition(",TCPIP*:")
    return rest.split(".weewx", 1)[0]


def _same_line(theirs: str | None, mine: str | None) -> bool:
    """Two lines of line protocol with their floats read as floats, since 1e-05 and 1.0e-5 are one number.

    Split on the separators the protocol means: a space or a comma with no
    backslash before it. A place with spaces in its name is one tag.
    """
    if theirs is None or mine is None:
        return theirs is None and mine is None
    a, b = re.split(r"(?<!\\) ", theirs), re.split(r"(?<!\\) ", mine)
    if len(a) != 3 or len(b) != 3 or a[0] != b[0] or a[2] != b[2]:
        return False
    fields_a = dict(f.split("=", 1) for f in re.split(r"(?<!\\),", a[1]))
    fields_b = dict(f.split("=", 1) for f in re.split(r"(?<!\\),", b[1]))
    return set(fields_a) == set(fields_b) and all(float(fields_a[k]) == float(fields_b[k]) for k in fields_a)


def _same(a: object, b: object) -> bool:
    if a is None or b is None:
        return a is None and b is None
    if isinstance(a, (int, float)) and isinstance(b, (int, float)) and not isinstance(a, bool):
        if math.isnan(float(a)):
            return isinstance(b, float) and math.isnan(b)
        return float(a) == float(b)
    return a == b
