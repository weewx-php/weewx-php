"""Ten years of five-minute rows: summaries, bounded cold misses, and cache-only reads."""
import json
import sqlite3
from harness import Context, Failure

def run(ctx: Context) -> None:
    path = ctx.work / 'scale.sdb'
    start = 1136073600  # 2006-01-01 UTC
    days = 3652       # to 2016-01-01, including leap days
    count = days * 288
    db = sqlite3.connect(path)
    db.execute('CREATE TABLE archive(dateTime INTEGER PRIMARY KEY, usUnits INTEGER, interval INTEGER, rain REAL)')
    db.executemany('INSERT INTO archive VALUES(?,17,5,0.01)', ((start + i * 300,) for i in range(1, count + 1)))
    db.execute('CREATE TABLE archive_day_rain(dateTime INTEGER PRIMARY KEY, min REAL, mintime INTEGER, max REAL, maxtime INTEGER, sum REAL, count INTEGER, wsum REAL, sumtime INTEGER)')
    db.executemany('INSERT INTO archive_day_rain VALUES(?,0.01,?,0.01,?,2.88,288,864,86400)', ((start + day * 86400, start + day * 86400 + 300, start + day * 86400 + 300) for day in range(days)))
    db.execute('CREATE TABLE archive_day__metadata(name TEXT PRIMARY KEY, value TEXT)')
    db.executemany('INSERT INTO archive_day__metadata VALUES(?,?)', [('Version', '4.0'), ('lastUpdate', str(start + count * 300))])
    db.commit(); db.close()
    answer = ctx.php_json('frontend_scale.php', stdin=json.dumps(dict(path=str(path), start=start, end=start + count * 300)))
    if abs(answer['total'] - count * 0.01) > 1e-6 or answer['rawStatus'] != 'pending' or answer['warm'] != answer['total']:
        raise Failure(str(answer))
    if answer['rankCount'] != days or answer['rankStart'] != start or abs(answer['rankValue'] - 2.88) > 1e-9:
        raise Failure(str(answer))
    print(f"  {count:,} archive rows; {days:,} summaries; cold total {answer['coldMs']:.1f} ms; bounded raw miss {answer['rawMs']:.1f} ms; warm {answer['warmMs']:.1f} ms with zero archive-read budget")
    print(f"  ranking {days:,} complete days resumed across {answer['passes']} worker runs in {answer['analysisMs']:.1f} ms")
