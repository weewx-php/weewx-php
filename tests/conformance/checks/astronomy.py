"""Our existing numerical kernels and their new bindings, against PyEphem."""
from datetime import datetime, timezone
import json
import math
from harness import Context, Failure

def run(ctx: Context) -> None:
    import ephem
    epoch = float(ephem.Date('1970/1/1'))
    cases, expected, tolerances = [], [], []
    def add(case, want, tolerance):
        cases.append(case); expected.append(want); tolerances.append(tolerance)
    bodies = ['Sun', 'Moon', 'Mercury', 'Venus', 'Mars', 'Jupiter', 'Saturn', 'Uranus', 'Neptune', 'Pluto', 'Sirius', 'Polaris', 'Vega']
    dates = ['2000-01-01T12:00:00', '2024-03-20T14:00:00', '2026-06-21T12:00:00', '2035-12-21T22:00:00']
    for date in dates:
        at = int(datetime.fromisoformat(date).replace(tzinfo=timezone.utc).timestamp())
        for lat, lon in [(48.4596, 11.6539), (69.65, 18.96), (-41.29, 174.78)]:
            observer = ephem.Observer(); observer.lat = str(lat); observer.lon = str(lon)
            observer.date = epoch + at / 86400; observer.pressure = 1010; observer.temp = 15
            for name in bodies:
                body = getattr(ephem, name)() if hasattr(ephem, name) else ephem.star(name)
                body.compute(observer)
                base = dict(at=at, lat=lat, lon=lon, body=name.lower())
                for field, attr in [('altitude','alt'), ('azimuth','az'), ('geo_ra','g_ra'), ('geo_dec','g_dec'), ('topo_ra','ra'), ('topo_dec','dec'), ('astro_ra','a_ra'), ('astro_dec','a_dec')]:
                    add(dict(base, field=field), math.degrees(float(getattr(body, attr))), 0.08 if name == 'Moon' else 0.03)
                if name not in ['Sirius', 'Polaris', 'Vega']:
                    add(dict(base, field='phase'), float(body.phase), 0.3)
                # Events anchored at the supplied instant, including polar no-event results.
                for field, call in [('next_rising',observer.next_rising), ('next_setting',observer.next_setting), ('next_transit',observer.next_transit), ('previous_antitransit',observer.previous_antitransit)]:
                    try: want = round((float(call(body, start=observer.date)) - epoch) * 86400)
                    except (ephem.AlwaysUpError, ephem.NeverUpError): want = None
                    add(dict(base, field=field), want, 120 if name == 'Moon' else 45)
        for event in ['new_moon', 'first_quarter_moon', 'full_moon', 'last_quarter_moon', 'equinox', 'solstice', 'vernal_equinox', 'autumnal_equinox', 'summer_solstice', 'winter_solstice']:
            for direction in ['next', 'previous']:
                field = direction + '_' + event
                want = round((float(getattr(ephem, field)(epoch + at / 86400)) - epoch) * 86400)
                add(dict(at=at, field=field), want, 90)
    answer = ctx.php_json('astronomy.php', stdin=json.dumps(cases))
    problems = []
    worst_angle = worst_event = 0.0
    for case, got, want, tolerance in zip(cases, answer, expected, tolerances, strict=True):
        if got is None or want is None:
            # PHP's documented search is 48 h. PyEphem sometimes stops with
            # NeverUp before a following-day crossing; verify that crossing
            # independently from a later reference epoch rather than ignoring it.
            if got is not None and want is None and got - case['at'] > 86400:
                observer = ephem.Observer(); observer.lat = str(case['lat']); observer.lon = str(case['lon'])
                observer.pressure = 1010; observer.temp = 15
                observer.date = epoch + (case['at'] + 86400) / 86400
                body = getattr(ephem, case['body'].capitalize())()
                want = round((float(getattr(observer, case['field'])(body)) - epoch) * 86400)
                if abs(got - want) <= tolerance: continue
            if got != want: problems.append(f'{case}: PHP {got}, PyEphem {want}')
            continue
        off = abs(got - want)
        if tolerance < 1 and case['field'] != 'phase':
            off = min(off, abs(off - 360)); worst_angle = max(worst_angle, off)
        elif tolerance > 1: worst_event = max(worst_event, off)
        if off > tolerance: problems.append(f'{case}: PHP {got}, PyEphem {want}, off {off:.5f}')
    print(f'  {len(cases)} positions, events and phases; worst angle {worst_angle:.5f} deg, event {worst_event:.0f} s')
    if problems: raise Failure('\n'.join([f'{len(problems)} astronomy differences', *problems[:25]]))
