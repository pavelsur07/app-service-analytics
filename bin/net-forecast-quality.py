#!/usr/bin/env python3
"""Finite operator for one immutable B16 protocol; no arbitrary commands."""
import argparse
import datetime as dt
import fcntl
import hashlib
import json
import os
from pathlib import Path
import re
import signal
import stat
import subprocess
import tempfile
import uuid

COMPOSE_ROOT = Path('/opt/conwix')
BRIDGE = COMPOSE_ROOT / 'net-forecast-quality/net-forecast-quality-bridge.php'
UTC = dt.timezone.utc


def utc(value):
    if not isinstance(value, str) or not re.fullmatch(r'\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{6}Z', value):
        raise ValueError('Expected exact UTC timestamp')
    return dt.datetime.fromisoformat(value.replace('Z', '+00:00'))


def identifier(value):
    if not isinstance(value, str):
        raise ValueError('Expected UUIDv7')
    parsed = uuid.UUID(value)
    if str(parsed) != value or parsed.version != 7:
        raise ValueError('Expected UUIDv7')
    return value


def load_private(path):
    path = Path(path)
    flags = os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK
    try:
        fd = os.open(path, flags)
    except OSError as exc:
        raise ValueError('Private file unavailable') from exc
    with os.fdopen(fd, 'r') as handle:
        info = os.fstat(handle.fileno())
        if not stat.S_ISREG(info.st_mode) or info.st_mode & 0o077 or info.st_uid != os.geteuid() or not 0 < info.st_size <= 1048576:
            raise ValueError('Private owned bounded file required')
        return json.load(handle)


def save_private(path, value):
    fd, temporary = tempfile.mkstemp(prefix='.quality-', dir=path.parent)
    try:
        with os.fdopen(fd, 'w') as handle:
            json.dump(value, handle, separators=(',', ':'))
            handle.write('\n')
            handle.flush()
            os.fsync(handle.fileno())
        os.replace(temporary, path)
        directory = os.open(path.parent, os.O_RDONLY | os.O_DIRECTORY)
        try:
            os.fsync(directory)
        finally:
            os.close(directory)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


def jobs(config):
    if not re.fullmatch(r'ghcr\.io/pavelsur07/app-service-analytics/api@sha256:[a-f0-9]{64}', config['image']):
        raise ValueError('Pinned project producer required')
    if not re.fullmatch('[a-f0-9]{64}', config['epochHash']):
        raise ValueError('Expected epoch hash')
    ref, data, operations = config['protocol'], config['data'], config['operations']
    if ref['kind'] != 'quality-protocol' or identifier(ref['requestId']) != data['protocolId'] or not re.fullmatch('[a-f0-9]{64}', ref['artifactHash']) or not re.fullmatch('[a-f0-9]{40}', data['codeCommit']):
        raise ValueError('Protocol reference mismatch')
    if set(operations) != {'captureInventory', 'selection', 'truthInventory', 'evaluation'}:
        raise ValueError('Four explicit operation identities required')
    result = []
    inputs = {identifier(s['requestId']) for s in data['slots']}
    for op, key in (('capture', 'slots'), ('truth', 'truthSlots')):
        for slot in data[key]:
            if op == 'truth' and slot['inputId'] not in inputs:
                raise ValueError('Truth requires registered input')
            at = utc(slot['scheduledAtUtc'])
            result.append({'operation': op, 'id': identifier(slot['requestId']), 'at': at,
                           'until': at + dt.timedelta(minutes=15)})
    capture_end, truth_end = utc(data['captureToUtc']), utc(data['truthCutoffUtc'])
    for op, end, delay, until in (
            ('captureInventory', capture_end, 1, truth_end), ('selection', capture_end, 2, truth_end),
            ('truthInventory', truth_end, 1, truth_end + dt.timedelta(days=1)),
            ('evaluation', truth_end, 3, truth_end + dt.timedelta(days=1))):
        result.append({'operation': op, 'id': identifier(operations[op]), 'at': end + dt.timedelta(minutes=delay), 'until': until})
    ids = [job['id'] for job in result]
    if len(ids) > 404 or len(set(ids)) != len(ids) or ref['requestId'] in ids:
        raise ValueError('Duplicate or excessive job identities')
    return sorted(result, key=lambda job: (job['at'], job['operation'], job['id']))


def calendar(config):
    dates = sorted({job['at'] for job in jobs(config)})
    return '[Timer]\n' + ''.join('OnCalendar=' + date.strftime('%Y-%m-%d %H:%M:%S UTC') + '\n' for date in dates)


class DockerBridge:
    def __init__(self, config, directory):
        self.config, self.directory = config, directory

    def __call__(self, operation, request_id=None, recover=False):
        stop_active(self.config, self.directory)
        name = 'conwix-net-quality-' + (request_id or str(uuid.uuid4()))
        save_private(self.directory / 'active.json', {'name': name, 'protocol': self.config['protocol']['requestId']})
        attempt = self.directory / ('attempt-' + str(uuid.uuid4()))
        env = dict(os.environ, IMAGE=self.config['image'])
        argv = ['docker', 'compose', '-f', 'docker-compose.prod.yml', '-f', str(COMPOSE_ROOT / 'net-forecast-quality/compose.yml'), 'run', '--rm', '--no-deps', '-T',
                '--name', name, '-v', str(BRIDGE) + ':/tmp/net-forecast-quality-bridge.php:ro',
                '--label', 'conwix.net-quality.protocol=' + self.config['protocol']['requestId'],
                'api', 'php', '/tmp/net-forecast-quality-bridge.php']
        payload = json.dumps({'config': self.config, 'operation': operation, 'requestId': request_id, 'recoverOnly': recover}).encode()
        with open(str(attempt) + '.stdout', 'xb') as out, open(str(attempt) + '.stderr', 'xb') as err:
            os.chmod(out.name, 0o600)
            os.chmod(err.name, 0o600)
            try:
                result = subprocess.run(argv, input=payload, stdout=out, stderr=err, cwd=COMPOSE_ROOT, env=env, timeout=360, check=False)
            except BaseException:
                # Includes SIGTERM/SystemExit; Docker daemon outlives its CLI.
                stop_active(self.config, self.directory)
                raise
        stop_active(self.config, self.directory)
        body = Path(str(attempt) + '.stdout').read_bytes()
        if result.returncode or len(body) > 65536:
            raise RuntimeError('Pinned bridge failed; private attempt logs retained')
        value = json.loads(body)
        if not isinstance(value, dict):
            raise ValueError('Invalid bridge output')
        return value


def stop_active(config, directory):
    marker = directory / 'active.json'
    if not marker.exists():
        return
    active = load_private(marker)
    name, protocol = active['name'], config['protocol']['requestId']
    if active['protocol'] != protocol or not re.fullmatch(r'conwix-net-quality-[a-f0-9-]{36}', name):
        raise ValueError('Invalid active container identity')
    result = subprocess.run(['docker', 'ps', '-a', '--filter', 'name=^/'+name+'$', '--format',
                             '{{.Names}} {{.Label "conwix.net-quality.protocol"}}'],
                            capture_output=True, text=True, timeout=30, check=True)
    if result.stdout.strip():
        if result.stdout.strip() != name+' '+protocol:
            raise ValueError('Active container ownership label mismatch')
        subprocess.run(['docker', 'stop', '--time', '15', name], stdout=subprocess.DEVNULL,
                       stderr=subprocess.DEVNULL, timeout=30, check=True)
    marker.unlink()


def stopped(signum, frame):
    signal.signal(signal.SIGTERM, signal.SIG_IGN)
    raise SystemExit(128 + signum)


def run_due(path, now, backend=None, clock=None):
    path = Path(path).absolute()
    directory = path.parent
    info = directory.lstat()
    if directory.resolve() != directory or not stat.S_ISDIR(info.st_mode) or info.st_mode & 0o077 or info.st_uid != os.geteuid():
        raise ValueError('Private owned directory required')
    config = load_private(path)
    scheduled = jobs(config)
    fingerprint = hashlib.sha256(json.dumps(config, sort_keys=True, separators=(',', ':')).encode()).hexdigest()
    if clock is None:
        clock = (lambda: dt.datetime.now(UTC)) if backend is None else (lambda: now)
    fd = os.open(directory / 'run.lock', os.O_WRONLY | os.O_CREAT | os.O_NOFOLLOW, 0o600)
    with os.fdopen(fd, 'w') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        invoke = backend or DockerBridge(config, directory)
        if invoke('audit').get('status') != 'verified':
            raise ValueError('Protocol audit failed')
        state_file = directory / 'state.json'
        state = load_private(state_file) if state_file.exists() else {}
        if state and state.get('_configHash') != fingerprint:
            raise ValueError('Configuration changed after execution')
        state['_configHash'] = fingerprint
        result = {'executed': 0, 'failed': 0, 'missing': 0, 'future': 0}
        for job in scheduled:
            now = clock()
            if job['at'] > now:
                result['future'] += 1
                continue
            previous = state.get(job['id'], {})
            if previous.get('status') in ('sealed', 'missing'):
                result['missing'] += previous['status'] == 'missing'
                continue
            try:
                response = invoke(job['operation'], job['id'], now >= job['until'])
                if response.get('status') not in ('sealed', 'missing') or (response['status'] == 'missing' and now < job['until']):
                    raise ValueError('Incomplete bridge result')
                if response['status'] == 'sealed':
                    artifact = response.get('artifact', {})
                    kind = {'capture': 'input', 'truth': 'truth', 'captureInventory': 'quality-inventory',
                            'truthInventory': 'quality-inventory', 'selection': 'quality-selection', 'evaluation': 'quality-report'}[job['operation']]
                    if artifact.get('kind') != kind or artifact.get('requestId') != job['id'] or not re.fullmatch('[a-f0-9]{64}', artifact.get('artifactHash', '')) or response.get('numericRolloutAllowed') is not False:
                        raise ValueError('Verified publication reference required')
                record = {'status': response['status'], 'result': response}
                result['executed' if response['status'] == 'sealed' else 'missing'] += 1
            except Exception as exc:
                # Provider errors may contain scoped identifiers/financial bodies.
                record = {'status': 'failed', 'failureClass': type(exc).__name__}
                result['failed'] += 1
            record.update(operation=job['operation'], checkedAtUtc=now.strftime('%Y-%m-%dT%H:%M:%S.%fZ'),
                          attempts=previous.get('attempts', 0) + 1)
            state[job['id']] = record
            save_private(state_file, state)
            save_private(directory / ('receipt-' + job['id'] + '-' + str(uuid.uuid4()) + '.json'), record)
        return result


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('mode', choices=['check', 'calendar', 'run', 'stop'])
    parser.add_argument('config', type=Path)
    args = parser.parse_args()
    os.umask(0o077)
    signal.signal(signal.SIGTERM, stopped)
    try:
        config = load_private(args.config)
        if args.mode == 'stop':
            stop_active(config, args.config.absolute().parent)
            print('{"status":"stopped"}')
            return 0
        scheduled = jobs(config)
        if args.mode == 'calendar':
            print(calendar(config), end='')
            return 0
        if args.mode == 'check':
            directory = args.config.absolute().parent
            fd = os.open(directory / 'run.lock', os.O_WRONLY | os.O_CREAT | os.O_NOFOLLOW, 0o600)
            with os.fdopen(fd, 'w') as lock:
                fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
                response = DockerBridge(config, directory)('audit')
            if response.get('status') != 'verified':
                raise ValueError('Protocol audit failed')
            now = dt.datetime.now(UTC)
            print(json.dumps({'status': 'verified', 'jobs': len(scheduled), 'due': sum(j['at'] <= now for j in scheduled),
                              'nextUtc': next((j['at'].isoformat() for j in scheduled if j['at'] > now), None)}))
            return 0
        result = run_due(args.config, dt.datetime.now(UTC))
        print(json.dumps(result))
        return 1 if result['failed'] or result['missing'] else 0
    except Exception as exc:
        print(json.dumps({'status': 'failed', 'failureClass': type(exc).__name__}))
        return 1


if __name__ == '__main__':
    raise SystemExit(main())
