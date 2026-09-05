"""Full collector -> verified TLS -> real PHP, in separate Docker containers.

The fixture volume carries test provisioning/control only. Application databases
live in each container's own /tmp; observations travel exclusively over HTTPS.
No test route or control API is added to the PHP product.
"""

from __future__ import annotations

import collections
import contextlib
import hashlib
import http.client
import json
import math
import os
from pathlib import Path
import signal
import socket
import sqlite3
import ssl
import subprocess
import sys
import threading
import time
from datetime import UTC, datetime, timedelta
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

FIXTURE = Path('/fixture')
WORK = Path('/tmp/collector-e2e')
PHP_CONFIG = WORK / 'weather.conf'
PHP_ROOT = Path('/repo')


def write_json(path, value):
    temporary = path.with_suffix('.tmp')
    temporary.write_text(json.dumps(value))
    temporary.replace(path)


def read_json(path):
    return json.loads(path.read_text())


def rows(path, sql):
    if not path.exists():
        return []
    with contextlib.closing(sqlite3.connect(f'file:{path}?mode=ro', uri=True)) as db:
        db.row_factory = sqlite3.Row
        return [dict(row) for row in db.execute(sql)]


def php(*args):
    done = subprocess.run(['php', str(PHP_ROOT / 'bin/weewx-php'), '--config', str(PHP_CONFIG),
                           *args], capture_output=True, text=True, timeout=45)
    if done.returncode:
        raise AssertionError(f'PHP {args[0]} failed: {done.stdout} {done.stderr}')
    return done.stdout


def certificate():
    from cryptography import x509
    from cryptography.hazmat.primitives import hashes, serialization
    from cryptography.hazmat.primitives.asymmetric import rsa
    from cryptography.x509.oid import NameOID

    key = rsa.generate_private_key(public_exponent=65537, key_size=2048)
    name = x509.Name([x509.NameAttribute(NameOID.COMMON_NAME, 'receiver')])
    cert = (x509.CertificateBuilder().subject_name(name).issuer_name(name)
            .public_key(key.public_key()).serial_number(x509.random_serial_number())
            .not_valid_before(datetime.now(UTC) - timedelta(minutes=5))
            .not_valid_after(datetime.now(UTC) + timedelta(days=1))
            .add_extension(x509.SubjectAlternativeName([x509.DNSName('receiver')]), False)
            .sign(key, hashes.SHA256()))
    (FIXTURE / 'ca.pem').write_bytes(cert.public_bytes(serialization.Encoding.PEM))
    private = WORK / 'key.pem'
    private.write_bytes(key.private_bytes(serialization.Encoding.PEM,
                        serialization.PrivateFormat.PKCS8, serialization.NoEncryption()))
    context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    context.load_cert_chain(FIXTURE / 'ca.pem', private)
    return context


def receiver():
    WORK.mkdir()
    base_config = (f'data_dir = {WORK}/php-data\ntimezone = UTC\narchive_interval = 1m\n'
                   'archive_delay = 0\nlive_retention = 14d\nmax_intervals_per_run = 2000\n'
                   '[Ingest]\nenabled = true\ntick_mode = external\n'
                   'trusted_proxies = 127.0.0.1\n')
    PHP_CONFIG.write_text(base_config)
    provisioned = dict(line.split(': ', 1) for line in php('collector', 'add', 'Docker').splitlines())
    write_json(FIXTURE / 'provisioned.json', {'collector_id': provisioned['collector_id']})
    (FIXTURE / 'collector.token').write_text(provisioned['token'])
    (FIXTURE / 'collector.token').chmod(0o600)
    php_log = (WORK / 'php.log').open('w+')
    backend = None

    def start_backend():
        nonlocal backend
        backend = subprocess.Popen(['php', '-S', '127.0.0.1:8080', '-t', '/repo/public'],
                    env={**os.environ, 'WEEWX_PHP_CONF': str(PHP_CONFIG)},
                    stdout=php_log, stderr=php_log)
        deadline = time.monotonic() + 10
        while time.monotonic() < deadline:
            try:
                with socket.create_connection(('127.0.0.1', 8080), timeout=0.1):
                    return
            except OSError:
                time.sleep(0.05)
        raise AssertionError('PHP server did not start')

    def stop_backend():
        if backend and backend.poll() is None:
            backend.terminate()
            backend.wait(timeout=10)

    state = {'events': {}, 'statuses': collections.Counter(), 'lost': [], 'drop_next': False,
             'requests': 0, 'failures': 0, 'oversize': False}
    mutex = threading.Lock()

    class Proxy(BaseHTTPRequestHandler):
        def log_message(self, *_):
            pass

        def do_POST(self):
            if self.path != '/ingest/weewx.php':
                self.send_error(404)
                return
            size = int(self.headers.get('Content-Length', '0'))
            if not 0 < size <= 262144:
                state['oversize'] = True
                self.send_error(413)
                return
            body = self.rfile.read(size)
            payload = json.loads(body)
            with mutex:
                state['requests'] += 1
                for event in payload['packets']:
                    existing = state['events'].setdefault(event['event_id'], event)
                    assert existing == event, 'collector mutated an event across retries'
            connection = http.client.HTTPConnection('127.0.0.1', 8080, timeout=20)
            try:
                headers = {'Content-Type': 'application/json', 'X-Forwarded-Proto': 'https',
                           'X-Forwarded-For': self.client_address[0]}
                for key in ('Authorization', 'X-WeeWX-Token'):
                    if self.headers.get(key):
                        headers[key] = self.headers[key]
                connection.request('POST', self.path, body, headers)
                response = connection.getresponse()
                result = response.read()
                status = response.status
            except OSError:
                status, result = 503, b'{"error":"test_backend_down"}'
            finally:
                connection.close()
            with mutex:
                if status == 200:
                    reply = json.loads(result)
                    state['statuses'].update(r['status'] for r in reply['results'])
                    if state['drop_next'] and any(r['status'] == 'stored' for r in reply['results']):
                        state['drop_next'] = False
                        state['lost'] = [r['event_id'] for r in reply['results'] if r['status'] == 'stored']
                        # Real backend commit has completed; deliberately lose its ACK.
                        self.close_connection = True
                        return
                else:
                    state['failures'] += 1
            self.send_response(status)
            self.send_header('Content-Type', 'application/json')
            self.send_header('Content-Length', str(len(result)))
            self.end_headers()
            self.wfile.write(result)

    server = ThreadingHTTPServer(('0.0.0.0', 8443), Proxy)
    server.daemon_threads = True
    server.socket = certificate().wrap_socket(server.socket, server_side=True)
    threading.Thread(target=server.serve_forever, daemon=True).start()
    start_backend()
    (FIXTURE / 'ready').touch()
    live_path = WORK / 'php-data/live.sdb'
    stopping = threading.Event()
    signal.signal(signal.SIGTERM, lambda *_: stopping.set())
    try:
        while not stopping.wait(0.1):
            command_path = FIXTURE / 'command.json'
            if not command_path.exists():
                continue
            command = read_json(command_path)
            command_path.unlink()
            try:
                action = command['action']
                if action == 'adopt':
                    stations = rows(live_path, 'SELECT station,sender FROM weewx_station')
                    senders = {r['station']: r['sender'] for r in stations}
                    config = base_config + '[Archives]\n'
                    for key, station in command['stations'].items():
                        assert key in ('a', 'b')
                        php('collector', 'adopt', provisioned['collector_id'], station, key)
                        sender = senders[station]
                        config += (f'[[{key}]]\nprimary = {sender}\nsenders = {sender}\n'
                                   f'database = {WORK}/php-data/{key}.sdb\n'
                                   'unit_system = US\nauto_mapping = true\n')
                    PHP_CONFIG.write_text(config)
                    answer = senders
                elif action == 'stop_backend':
                    stop_backend()
                    answer = True
                elif action == 'start_backend':
                    start_backend()
                    answer = True
                elif action == 'drop_ack':
                    with mutex:
                        state['lost'] = []
                        state['drop_next'] = True
                    answer = True
                elif action == 'tick':
                    answer = json.loads(php('tick'))
                elif action == 'snapshot':
                    with mutex:
                        answer = {'requests': state['requests'], 'statuses': dict(state['statuses']),
                                  'lost': list(state['lost']), 'failures': state['failures']}
                    answer['stations'] = rows(live_path, 'SELECT station,sender,state,stored,duplicates FROM weewx_station')
                    answer['packets'] = rows(live_path, 'SELECT COUNT(*) AS count FROM packet')[0]['count']
                    answer['repairs'] = rows(live_path, 'SELECT archive,cursor,stop FROM weewx_replay')
                elif action == 'final':
                    with mutex:
                        answer = {'events': dict(state['events']), 'statuses': dict(state['statuses']),
                                  'requests': state['requests'], 'oversize': state['oversize']}
                    answer['packets'] = rows(live_path, 'SELECT * FROM packet ORDER BY dateTime,seq')
                    answer['receipts'] = rows(live_path, 'SELECT * FROM weewx_receipt')
                    answer['repairs'] = rows(live_path, 'SELECT * FROM weewx_replay')
                    answer['holds'] = rows(live_path, 'SELECT * FROM weewx_hold')
                    answer['archives'] = {key: {'records': rows(WORK / f'php-data/{key}.sdb', 'SELECT * FROM archive ORDER BY dateTime'),
                        'days': rows(WORK / f'php-data/{key}.sdb', 'SELECT * FROM archive_day_outTemp ORDER BY dateTime')} for key in ('a', 'b')}
                    answer['php_version'] = subprocess.check_output(['php', '-r', 'echo PHP_VERSION;'], text=True)
                    logs = '\n'.join(path.read_text() for path in WORK.rglob('*.log'))
                    answer['secret_in_log'] = provisioned['token'] in logs
                else:
                    raise AssertionError('Unknown fixture action')
                write_json(FIXTURE / 'reply.json', {'id': command['id'], 'answer': answer})
            except Exception as error:
                write_json(FIXTURE / 'reply.json', {'id': command['id'], 'error': str(error)})
    finally:
        stop_backend()
        server.shutdown()
        server.server_close()
        php_log.close()


def scenario():
    import weewx
    import weewx.accum
    from weeutil.weeutil import TimeSpan

    WORK.mkdir()
    start = time.monotonic()
    check_names = []

    def phase(name):
        print(f'[{time.monotonic() - start:.1f}s] {name}', flush=True)

    def passed(name):
        check_names.append(name)
        phase('PASS ' + name)

    serial = 0

    def control(action, **arguments):
        nonlocal serial
        serial += 1
        write_json(FIXTURE / 'command.json', {'id': serial, 'action': action, **arguments})
        end = time.monotonic() + 55
        while time.monotonic() < end:
            reply_path = FIXTURE / 'reply.json'
            if reply_path.exists():
                reply = read_json(reply_path)
                if reply['id'] == serial:
                    assert 'error' not in reply, reply
                    return reply['answer']
            time.sleep(0.1)
        raise AssertionError(f'Fixture {action} timed out')

    def wait_for(description, predicate, timeout=30):
        deadline, report = time.monotonic() + timeout, time.monotonic() + 20
        while time.monotonic() < deadline:
            value = predicate()
            if value:
                return value
            if time.monotonic() >= report:
                phase('Waiting: ' + description)
                report += 20
            time.sleep(0.25)
        raise AssertionError('Timed out: ' + description)

    provisioned = read_json(FIXTURE / 'provisioned.json')
    config = WORK / 'collector.toml'
    config.write_text('[collector]\n' + f'id = "{provisioned["collector_id"]}"\n'
        'endpoint = "https://receiver:8443/ingest/weewx.php"\n'
        'token_file = "/fixture/collector.token"\nca_file = "/fixture/ca.pem"\n'
        f'state_dir = "{WORK}/state"\nsend_interval = 1\ntimeout = 3\nbackoff_max = 4\n'
        'shutdown_timeout = 5\n')
    for key, interval in [('a', 0.4), ('b', 0.7)]:
        station = WORK / f'{key}.conf'
        station.write_text('[Station]\nstation_type = Simulator\nlatitude = 0\nlongitude = 0\n'
            'altitude = 0, meter\n[Simulator]\ndriver = weewx.drivers.simulator\n'
            f'mode = simulator\nloop_interval = {interval}\n'
            'observations = outTemp, outHumidity, rain, windSpeed, windDir, windGust, windGustDir\n')
        with config.open('a') as stream:
            stream.write(f'[stations.{key}]\nconfig = "{station}"\n'
                'startup_timeout = 15\nsilence_timeout = 10\n'
                'lifecycle_interval = 10\nlifecycle_delay = 1\n')
    base = [sys.executable, '-m', 'weewx_php_ingest', '--config', str(config)]

    def cli(action):
        done = subprocess.run([*base, action], capture_output=True, text=True, timeout=20)
        assert done.returncode == 0, (action, done.stdout, done.stderr)
        return done.stdout

    def status():
        return {s['key']: s for s in json.loads(cli('status'))['stations']}

    def queued():
        return {r['event_id']: json.loads(r['payload']) for path in (WORK / 'state/stations').glob('*.sqlite3')
                for r in rows(path, 'SELECT event_id,payload FROM events')}

    log = (WORK / 'collector.log').open('w+')
    process = None

    def launch():
        nonlocal process
        process = subprocess.Popen([*base, 'run'], stdout=log, stderr=log, start_new_session=True)

    def stop():
        if process and process.poll() is None:
            process.terminate()
            process.wait(timeout=12)
            assert process.returncode == 0, 'collector shutdown failed'

    try:
        phase('Starting two real Simulator workers and uploader over verified TLS')
        assert cli('check').strip() == 'Configuration OK'
        identities = {key: s['station_id'] for key, s in status().items()}
        assert len(set(identities.values())) == 2
        # A wrong trust store must fail; successful delivery below uses only the fixture CA.
        try:
            connection = http.client.HTTPSConnection('receiver', 8443, timeout=3)
            connection.request('POST', '/ingest/weewx.php', '{}')
            raise AssertionError('untrusted test certificate accepted')
        except ssl.SSLCertVerificationError:
            passed('TLS certificate validation')
        finally:
            connection.close()
        launch()
        wait_for('pending discovery', lambda: len(control('snapshot')['stations']) == 2)
        first = status()
        assert all(s['events'] > 0 for s in first.values())
        assert first['a']['worker_pid'] != first['b']['worker_pid']
        assert control('snapshot')['packets'] == 0
        passed('separate driver instances and pending admission without data loss')
        senders = control('adopt', stations=identities)
        assert len(set(senders.values())) == 2
        old_pid = first['a']['worker_pid']
        os.kill(old_pid, signal.SIGKILL)
        wait_for('worker restart', lambda: (s := status())['a']['worker_pid'] not in (None, old_pid)
                 and s['a']['last_collected'] > first['a']['last_collected'])
        assert status()['b']['worker_pid'] == first['b']['worker_pid']
        assert status()['b']['last_collected'] > first['b']['last_collected']
        passed('worker crash restarts only the affected station')
        wait_for('normal post-adoption retry (60-second minimum)',
                 lambda: all(s['stored'] > 10 for s in control('snapshot')['stations']), 90)
        control('tick')
        passed('real CLI supervisor and uploader deliver both stations')

        control('drop_ack')
        lost = wait_for('committed packet with deliberately lost ACK', lambda: control('snapshot')['lost'])
        stop()
        assert set(lost) <= queued().keys(), 'lost ACK released unconfirmed observations'
        launch()
        assert {key: s['station_id'] for key, s in status().items()} == identities
        wait_for('idempotent delivery after collector restart', lambda: not (set(lost) & queued().keys()))
        assert control('snapshot')['statuses'].get('duplicate', 0) >= len(lost)
        passed('lost ACK plus collector restart preserves IDs and avoids duplicates')

        control('stop_backend')
        baseline = control('snapshot')
        phase('PHP receiver stopped for 70 seconds; collection continues')
        outage_start = time.monotonic()
        while time.monotonic() - outage_start < 70:
            time.sleep(5)
            control('tick')  # Archive the data received before the interruption.
            if int(time.monotonic() - outage_start) % 20 < 5:
                phase(f'Outage spool: {sum(s["events"] for s in status().values())} events')
        assert control('snapshot')['packets'] == baseline['packets']
        stop()
        outage = queued()
        assert len(outage) > 150
        assert all(s['quarantined'] == 0 for s in status().values())
        launch()
        assert {key: s['station_id'] for key, s in status().items()} == identities
        assert all(queued().get(eid) == event for eid, event in outage.items())
        control('start_backend')
        wait_for('outage backlog drained', lambda: not (outage.keys() & queued().keys()), 45)
        passed('70-second PHP outage, persistent spool and restart recovery')
        stop()
        wait_for('last persisted readings confirmed', lambda: (cli('upload-once'), not queued())[1], 20)
        assert all(s['events'] == 0 and s['quarantined'] == 0 for s in status().values())
        wait_for('last archive interval closed', lambda: time.time() % 60 < 2, 62)
        wait_for('durable archive repair finished', lambda: (control('tick'), not control('snapshot')['repairs'])[1], 55)
        final = control('final')
        expected = final['events']
        assert not final['oversize'] and not final['secret_in_log']
        assert final['statuses'].get('rejected', 0) == 0
        assert not final['repairs'] and not final['holds']
        total_created = sum(rows(path, "SELECT seq FROM sqlite_sequence WHERE name='events'")[0]['seq']
            for path in (WORK / 'state/stations').glob('*.sqlite3'))
        assert len(expected) == len(final['packets']) == len(final['receipts']) == total_created
        digests = {hashlib.sha256(('weewx-event:' + eid).encode()).hexdigest(): e for eid, e in expected.items()}
        for packet in final['packets']:
            event = digests[packet['digest']]
            assert packet['identity'] == provisioned['collector_id'] + '/' + event['station_id']
            assert packet['sender'] == senders[event['station_id']]
            assert (packet['dateTime'], packet['usUnits'], json.loads(packet['data'])) == (
                event['dateTime'], event['usUnits'], event['data'])
        passed('every persisted event exactly once with original time, units and observations')

        weewx.accum.initialize({})
        compared = 0
        for key, archive in final['archives'].items():
            events = [e for e in expected.values() if e['station_id'] == identities[key]]
            groups = collections.defaultdict(list)
            for event in events:
                groups[((event['dateTime'] - 1) // 60 + 1) * 60].append(event)
            actual = {r['dateTime']: r for r in archive['records']}
            assert actual.keys() == groups.keys(), f'archive interval mismatch: {key}'
            for stamp, source in groups.items():
                accum = weewx.accum.Accum(TimeSpan(stamp - 60, stamp))
                for event in sorted(source, key=lambda e: e['dateTime']):
                    accum.addRecord({**event['data'], 'dateTime': event['dateTime'], 'usUnits': event['usUnits']})
                for field, value in accum.getRecord().items():
                    got = actual[stamp].get(field)
                    assert (got is None if value is None else got is not None and math.isclose(got, value, rel_tol=1e-9, abs_tol=1e-9)), (key, stamp, field, got, value)
                    compared += 1
            daily = collections.defaultdict(list)
            for event in events:
                daily[(event['dateTime'] - 1) // 86400 * 86400].append(event['data']['outTemp'])
            for day in archive['days']:
                assert math.isclose(day['min'], min(daily[day['dateTime']]), abs_tol=1e-9)
                assert math.isclose(day['max'], max(daily[day['dateTime']]), abs_tol=1e-9)
        passed('separate PHP archives match original WeeWX accumulation after replay')
        assert (FIXTURE / 'collector.token').read_text() not in (WORK / 'collector.log').read_text()
        report = {'status': 'passed', 'checks': check_names, 'python': sys.version.split()[0],
                  'weewx': weewx.__version__, 'php': final['php_version'],
                  'stations': 2, 'events': total_created, 'http_requests': final['requests'],
                  'duplicate_acks': final['statuses'].get('duplicate', 0), 'outage_backlog': len(outage),
                  'archive_records': {k: len(a['records']) for k, a in final['archives'].items()},
                  'archive_values_compared': compared, 'outage_seconds': 70,
                  'duration_seconds': round(time.monotonic() - start, 1)}
        write_json(FIXTURE / 'report.json', report)
        print(json.dumps(report, indent=2), flush=True)
    finally:
        stop()
        log.close()


if __name__ == '__main__':
    os.umask(0o077)
    {'receiver': receiver, 'scenario': scenario}[sys.argv[1]]()
