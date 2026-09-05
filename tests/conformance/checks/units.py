"""Every conversion in the table, against WeeWX, at nine values.

The failure this guards against is a quiet one: a factor out in the fourth
decimal produces a record that looks right and differs from what WeeWX
would have written. So the comparison is exact, not close: the PHP side
transcribes the same expressions in the same order, and a double computed
twice the same way is the same double.

Beyond the pairs: the group of every observation WeeWX knows, its unit in
each of the three systems, and whole records converted between the systems
the way `to_std_system` does it.
"""

from __future__ import annotations

import json
import math

from harness import Context, Failure

SAMPLES = (0.0, 1.0, -1.0, 7.5, 100.0, -40.0, 1013.25, 0.001, 98765.4321)

RECORDS = (
    {"dateTime": 1787734200, "usUnits": 1, "interval": 5, "outTemp": 71.3, "outHumidity": 60.0,
     "barometer": 30.03, "pressure": 28.54, "windSpeed": 2.24, "windDir": 231.0, "rain": 0.01,
     "rainRate": 0.0, "radiation": 640.7, "UV": 2.0, "altitude": 1443.6, "windrun": 0.09,
     "lightning_distance": 1.0, "vpd": 0.13, "model": "HP2561AE Pro", "inTemp": None},
    {"dateTime": 1787734500, "usUnits": 16, "interval": 5, "outTemp": 21.8, "barometer": 1016.9,
     "windSpeed": 3.6, "rain": 0.03, "rainRate": 0.1, "cloudbase": 817.0, "windrun": 0.3},
    {"dateTime": 1787734800, "usUnits": 17, "interval": 5, "outTemp": 21.8, "barometer": 1016.9,
     "windSpeed": 1.0, "rain": 0.3, "rainRate": 1.0, "ET": 0.02},
)


def run(ctx: Context) -> None:
    import weewx
    import weewx.units as wu

    # wxformulas adds beaufort targets to the table on import. They are a
    # deprecated observation type, not a unit, and the PHP side has none.
    pairs = [(source, target) for source in wu.conversionDict
             for target in wu.conversionDict[source] if "beaufort" not in target]
    obs = sorted(wu.obs_group_dict.keys())
    answer = ctx.php_json("units.php", stdin=json.dumps(
        {"samples": SAMPLES, "pairs": pairs, "obs": obs, "records": RECORDS}))
    if not isinstance(answer, dict):
        raise Failure("units.php did not answer with an object")

    problems: list[str] = []
    checked = 0
    for source, target in pairs:
        got = answer["conversions"].get(f"{source}>{target}")
        if got is None:
            problems.append(f"{source} -> {target}: missing")
            continue
        function = wu.conversionDict[source][target]
        for sample, ours in zip(SAMPLES, got, strict=True):
            checked += 1
            want = function(sample)
            if not _same(want, ours):
                problems.append(f"{source} -> {target} at {sample!r}: WeeWX {want!r}, PHP {ours!r}")
    print(f"  {len(pairs)} conversions, {checked} values compared")

    systems = {1: weewx.US, 16: weewx.METRIC, 17: weewx.METRICWX}
    for name in obs:
        group = wu.obs_group_dict[name]
        if answer["groups"].get(name) != group:
            problems.append(f"group of {name}: WeeWX {group}, PHP {answer['groups'].get(name)!r}")
        for key, system in systems.items():
            want = wu.getStandardUnitType(system, name)[0]
            got = answer["units"].get(name, {}).get(str(key))
            if got != want:
                problems.append(f"unit of {name} in {key}: WeeWX {want!r}, PHP {got!r}")
    print(f"  {len(obs)} observation types, groups and units in three systems")

    for index, record in enumerate(RECORDS):
        for key, system in systems.items():
            want = wu.to_std_system(dict(record), system)
            got = answer["records"][index][str(key)]
            for field, value in want.items():
                if not _same(value, got.get(field)):
                    problems.append(f"record {index} to {key}, {field}: WeeWX {value!r}, PHP {got.get(field)!r}")
            if set(got) != set(want):
                problems.append(f"record {index} to {key}: fields differ {sorted(set(got) ^ set(want))}")
    print(f"  {len(RECORDS)} records converted into each system")

    if problems:
        raise Failure("\n  ".join([f"{len(problems)} difference(s):", *problems[:25]]))


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
    return want == got
