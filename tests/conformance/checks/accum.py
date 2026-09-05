"""The accumulator, against WeeWX's own, over packets that move.

The same packets go through `weewx.accum.Accum` here and through the PHP
accumulator, and the record and every statistics tuple must agree exactly.
Exactly: the transcription performs the same operations in the same order,
so the doubles are the same doubles. A tolerance would hide a transposed
line, which is precisely the kind of mistake a transcription makes.

Four shapes of case, because they exercise different paths:

  * LOOP packets at weight 1, highs and lows included, into an interval:
    what the archiver builds a record from.
  * Archive records at weight 60 * interval into a day: what the daily
    summaries are built from, including a day primed from stored rows.
  * Wind with and without gusts, directions and calms.
  * Values that are not numbers, and extractor overrides.
"""

from __future__ import annotations

import json
import math
import random

from harness import Context, Failure

SEED = 20260905


def run(ctx: Context) -> None:
    import weewx.accum
    import weeutil.weeutil

    rng = random.Random(SEED)
    cases = [_loop_case(rng, index) for index in range(12)]
    cases += [_day_case(rng, index) for index in range(6)]
    cases.append(_odd_values_case())
    cases.append(_override_case())

    answer = ctx.php_json("accum.php", stdin=json.dumps({"cases": cases}))
    if not isinstance(answer, dict):
        raise Failure("accum.php did not answer with an object")

    problems: list[str] = []
    compared = 0
    for index, (case, ours) in enumerate(zip(cases, answer["cases"], strict=True)):
        overrides = {name: {"extractor": value} for name, value in case.get("extractors", {}).items()}
        weewx.accum.accum_dict.maps.insert(0, overrides)
        try:
            accum = weewx.accum.Accum(weeutil.weeutil.TimeSpan(case["start"], case["stop"]),
                                      case.get("unit_system"))
            for obs_type, tuple_ in case.get("stored", {}).items():
                accum.set_stats(obs_type, tuple(tuple_) if tuple_ is not None else None)
            for record in case["records"]:
                accum.addRecord(record, add_hilo=case.get("loop_hilo", True),
                                weight=case.get("weight", 1))
            want_record = accum.getRecord()
            want_stats = {obs_type: list(accum[obs_type].getStatsTuple()) for obs_type in accum}
        finally:
            weewx.accum.accum_dict.maps.pop(0)

        for key in sorted(set(want_record) | set(ours["record"])):
            compared += 1
            if not _same(want_record.get(key), ours["record"].get(key)):
                problems.append(f"case {index} record {key}: WeeWX {want_record.get(key)!r}, PHP {ours['record'].get(key)!r}")
        for obs_type in sorted(set(want_stats) | set(ours["stats"])):
            want = want_stats.get(obs_type)
            got = ours["stats"].get(obs_type)
            if want is None or got is None:
                problems.append(f"case {index} stats {obs_type}: WeeWX {want!r}, PHP {got!r}")
                continue
            for position, (a, b) in enumerate(zip(want, got, strict=True)):
                compared += 1
                if not _same(a, b):
                    problems.append(f"case {index} stats {obs_type}[{position}]: WeeWX {a!r}, PHP {b!r}")
    print(f"  {len(cases)} cases, {compared} values compared")
    if problems:
        raise Failure("\n  ".join([f"{len(problems)} difference(s):", *problems[:25]]))


def _loop_case(rng: random.Random, index: int) -> dict:
    start = 1_787_734_200 + index * 300
    units = rng.choice([1, 16, 17])
    records = []
    when = start + rng.randint(1, 16)
    while when <= start + 300:
        record = {"dateTime": when, "usUnits": units,
                  "outTemp": round(rng.uniform(-10, 35), 1),
                  "outHumidity": rng.randint(20, 100),
                  "barometer": round(rng.uniform(980, 1040), 2),
                  "windSpeed": round(rng.uniform(0, 12), 1),
                  "windDir": rng.choice([None, rng.randint(0, 359)]),
                  "rain": rng.choice([0.0, 0.0, 0.0, 0.1, 0.3]),
                  "dayRain": round(index * 0.7 + when / 1e7, 3),
                  "txBatteryStatus": rng.choice([0, 1]),
                  "radiation": rng.choice([None, round(rng.uniform(0, 900), 1)])}
        if rng.random() < 0.6:
            record["windGust"] = round(record["windSpeed"] + rng.uniform(0, 6), 1)
        if rng.random() < 0.4:
            record["windGustDir"] = rng.randint(0, 359)
        if rng.random() < 0.3:
            record["windSpeed"] = 0.0
        records.append(record)
        when += rng.randint(8, 40)
    return {"start": start, "stop": start + 300, "records": records,
            "loop_hilo": index % 4 != 3}


def _day_case(rng: random.Random, index: int) -> dict:
    # Local midnight in Europe/Berlin on 2026-05-14, the zone the image runs in.
    start = 1_778_709_600 + index * 86400
    interval = rng.choice([1, 5])
    records = []
    when = start + interval * 60
    while when <= start + 86400:
        records.append({"dateTime": when, "usUnits": 1, "interval": interval,
                        "outTemp": round(rng.uniform(40, 90), 2),
                        "windSpeed": round(rng.uniform(0, 15), 2),
                        "windDir": rng.choice([None, rng.randint(0, 359)]),
                        "windGust": round(rng.uniform(0, 25), 2),
                        "windGustDir": rng.randint(0, 359),
                        "rain": rng.choice([0.0, 0.0, 0.01]),
                        "ET": rng.choice([None, 0.001, 0.002])})
        when += interval * 60
    stored = None
    if index % 2 == 1:
        stored = {"outTemp": [45.5, start + 60, 88.0, start + 120, 130.0, 2, 39000.0, 600],
                  "rain": [0.0, start + 60, 0.0, start + 60, 0.0, 2, 0.0, 600],
                  "wind": [1.0, start + 60, 9.0, start + 120, 10.0, 2, 3000.0, 600,
                           270.0, -1500.0, 0.0, 600, 82.0, 24600.0],
                  "inTemp": None}
    case = {"start": start, "stop": start + 86400, "unit_system": 1, "weight": 60 * interval,
            "records": records}
    if stored is not None:
        case["stored"] = stored
    return case


def _odd_values_case() -> dict:
    start = 1_787_734_200
    return {"start": start, "stop": start + 300, "records": [
        {"dateTime": start + 10, "usUnits": 17, "outTemp": "None", "inTemp": "71.5",
         "model": "HP2561AE Pro", "windSpeed": "3.5", "windDir": "180", "rain": 0},
        {"dateTime": start + 20, "usUnits": 17, "outTemp": 20.5, "inTemp": None,
         "model": "HP2561AE Pro", "windSpeed": None, "windDir": 90, "rain": None},
        {"dateTime": start + 300, "usUnits": 17, "outTemp": 21.0, "windSpeed": 0, "windDir": None,
         "consBatteryVoltage": 3.1, "windSpeed10": 2.5, "windGust": 4.0},
    ]}


def _override_case() -> dict:
    start = 1_787_734_200
    return {"start": start, "stop": start + 300, "extractors": {"lightning_num": "last", "soilMoist1": "max"},
            "records": [
                {"dateTime": start + 60, "usUnits": 1, "lightning_num": 1, "soilMoist1": 40.0},
                {"dateTime": start + 120, "usUnits": 1, "lightning_num": 4, "soilMoist1": 42.0},
                {"dateTime": start + 180, "usUnits": 1, "lightning_num": 2, "soilMoist1": 41.0},
            ]}


def _same(want: object, got: object) -> bool:
    if want is None or got is None:
        return want is None and got is None
    if isinstance(want, str) or isinstance(got, str):
        return want == got
    if isinstance(want, bool) or isinstance(got, bool):
        return want == got
    if isinstance(want, (int, float)) and isinstance(got, (int, float)):
        if isinstance(want, float) and math.isnan(want):
            return isinstance(got, float) and math.isnan(got)
        return float(want) == float(got)
    if isinstance(want, (list, tuple)) and isinstance(got, (list, tuple)):
        return len(want) == len(got) and all(_same(a, b) for a, b in zip(want, got, strict=True))
    return want == got
