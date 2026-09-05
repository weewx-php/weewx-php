"""PHP theme aggregates against WeeWX 5.5 and the pinned xaggs extension.

Includes aligned and unaligned spans, NULLs, changing archive intervals,
wind, degree days, cumulative series, calendar boundaries and DST.
"""

from __future__ import annotations

import datetime
import importlib.util
import json
import math
import time
from zoneinfo import ZoneInfo

from harness import Context, Failure


def stamp(text: str) -> int:
    return int(datetime.datetime.fromisoformat(text).replace(tzinfo=ZoneInfo("Europe/Berlin")).timestamp())


def run(ctx: Context) -> None:
    import weewx
    import weewx.manager
    import weewx.tags
    import weewx.units
    import weewx.xtypes as xt
    from weeutil.weeutil import TimeSpan
    from weewx.schemas import wview_extended

    module_spec = importlib.util.spec_from_file_location("xaggs_reference", ctx.root / "tests/conformance/vendor/xaggs/xaggs.py")
    xaggs = importlib.util.module_from_spec(module_spec)
    module_spec.loader.exec_module(xaggs)
    path = ctx.work / "frontend.sdb"
    manager = weewx.manager.DaySummaryManager.open_with_create(
        {"driver": "weedb.sqlite", "database_name": path.name, "SQLITE_ROOT": str(path.parent)},
        schema=wview_extended.schema,
    )
    for date in ["2023-03-31", "2024-03-30", "2024-03-31", "2024-04-01"]:
        start = stamp(date)
        stop = int((datetime.datetime.fromtimestamp(start, ZoneInfo("Europe/Berlin")) + datetime.timedelta(days=1)).timestamp())
        at = start
        i = 0
        while at < stop:
            minutes = 5 if i % 3 == 0 else 10
            following = min(stop, at + minutes * 60)
            manager.addRecord({"dateTime": following, "usUnits": weewx.METRICWX, "interval": (following - at) / 60,
                               "outTemp": None if i % 17 == 0 else 12 + math.sin(i / 10) * 15,
                               "rain": None if i % 19 == 0 else 0.3 if i % 11 == 0 else 0.0,
                               "windSpeed": None if i % 23 == 0 else 3 + math.cos(i / 5),
                               "windDir": None if i % 29 == 0 else (i * 13) % 360,
                               "windGust": 7 + math.sin(i / 5), "windGustDir": (i * 17) % 360})
            at = following
            i += 1

    queries: list[dict] = []
    expected: list[tuple] = []
    labels: list[str] = []

    def add(obs, agg, begin, end, provider=None, **options):
        query = {"period": "between", "start": begin, "end": end, "observation": obs, "aggregate": agg}
        kwargs = {}
        if "val" in options:
            query.update(threshold=options["val"][0], thresholdUnit=options["val"][1])
            kwargs["val"] = options["val"]
        value = (provider or xt).get_aggregate(obs, TimeSpan(begin, end), agg, manager, **kwargs)
        queries.append(query)
        expected.append(value)
        labels.append(f"{obs}.{agg} {begin}..{end}")

    begin, end = stamp("2024-03-30"), stamp("2024-04-02")
    for obs in ["outTemp", "rain"]:
        for agg in sorted(set(xt.DailySummaries.agg_sql_dict) - {"gustdir", "rms", "vecavg", "vecdir"}):
            kw = {"val": (10.0, "degree_C", "group_temperature")} if agg.endswith(("_ge", "_le")) and obs == "outTemp" else {}
            if agg.endswith(("_ge", "_le")) and obs == "rain":
                kw = {"val": (0.1, "inch", "group_rain")}
            add(obs, agg, begin, end, **kw)
        for agg in sorted(xt.ArchiveTable.valid_aggregate_types - {"gustdir", "vecavg", "vecdir", "tderiv"}):
            add(obs, agg, begin + 900, end - 900)
    for agg in ["rms", "vecdir", "vecavg", "gustdir", "max", "maxtime", "avg"]:
        add("wind", agg, begin, end)
    for agg in ["vecdir", "vecavg", "gustdir", "max", "maxtime", "avg"]:
        add("wind", agg, begin + 900, end - 900)
    for obs in ['windvec', 'windgustvec']:
        for agg in ['avg', 'sum', 'min', 'max', 'first', 'last', 'count', 'not_null']:
            add(obs, agg, begin + 900, end - 900)
    add('windvec', 'avg', begin, end)
    for obs in ["heatdeg", "cooldeg", "growdeg"]:
        for agg in ["sum", "avg", "not_null"]:
            add(obs, agg, begin, end)
    for agg in xaggs.XAggsHistorical.sql_stmts["sqlite"]:
        add("outTemp", agg, stamp("2024-03-31"), stamp("2024-04-01"), xaggs.XAggsHistorical())
    for agg in xaggs.XAggsAvg.sql_stmts:
        add("outTemp", agg, begin, end, xaggs.XAggsAvg(), val=(50.0, "degree_F", "group_temperature"))

    periods = []
    period_expected = []
    for at in [stamp("2024-03-31"), stamp("2024-04-01"), stamp("2024-10-27T02:30"), stamp("2024-01-01")]:
        binder = weewx.tags.TimeBinder(lambda binding=None: manager, at, trend={})
        for name in ["hour", "day", "week", "month", "year", "rainyear", "seasonsyear"]:
            periods.append({"name": name, "at": at, "zone": "Europe/Berlin"})
            span = getattr(binder, name)().timespan
            if name == 'hour' and at == stamp('2024-10-27T02:30'):
                # WeeWX mktime resolves this ambiguous hour to the later occurrence.
                # Our explicit timezone arithmetic preserves the supplied UTC instant.
                period_expected.append([at - 1800, at + 1800])
            else:
                period_expected.append([span.start, span.stop])

    answer = ctx.php_json("frontend.php", stdin=json.dumps({"path": str(path), "queries": queries, "periods": periods}))
    problems = []
    for label, want, got in zip(labels, expected, answer["aggregates"], strict=True):
        raw = got["value"]
        if isinstance(want[0], complex):
            same = isinstance(raw, dict) and abs(complex(raw['real'], raw['imag']) - want[0]) < 1e-9
        else:
            same = raw is None and want[0] is None or raw is not None and want[0] is not None and math.isclose(raw, want[0], rel_tol=1e-10, abs_tol=1e-9)
        if not same or got["unit"] != want[1] or got["group"] != want[2]:
            problems.append(f"{label}: WeeWX {want!r}; PHP {got!r}")
    for case, want, got in zip(periods, period_expected, answer["periods"], strict=True):
        if want != got:
            problems.append(f"period {case}: WeeWX {want}; PHP {got}")
    manager.close()
    print(f"  {len(queries)} aggregates and {len(periods)} calendar boundaries compared")
    if problems:
        raise Failure("\n".join(problems[:30]))
