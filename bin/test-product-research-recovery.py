#!/usr/bin/env python3
"""Creates a disposable local Compose project; never restores into dev or production."""
import json
import os
from pathlib import Path
import subprocess
import tempfile
import time
import uuid

root = Path(__file__).resolve().parents[1]
project = 'research-recovery-' + uuid.uuid4().hex[:12]
(root / 'var').mkdir(exist_ok=True)
with tempfile.TemporaryDirectory(prefix=project + '-', dir=root / 'var') as folder:
    work = Path(folder)
    receipt = work / 'receipt'
    receipt.mkdir(mode=0o777)
    receipt.chmod(0o777)  # Only synthetic UUIDs, written by uid10001 inside the dev image.
    override = work / 'compose.json'
    override.write_text(json.dumps({'services': {'restore-postgres': {
        'image': 'postgres:16-alpine',
        'environment': {'POSTGRES_USER': 'app', 'POSTGRES_PASSWORD': 'secret', 'POSTGRES_DB': 'app'},
        'networks': ['internal'],
        'volumes': ['restore_data:/var/lib/postgresql/data', str(root / 'services/docker/postgres/init') + ':/docker-entrypoint-initdb.d:ro'],
    }}, 'volumes': {'restore_data': {}, 'go_mod': {'name': project + '_go_mod'}, 'go_build': {'name': project + '_go_build'}}}))
    compose = ['docker', 'compose', '-p', project, '-f', str(root / 'docker-compose.yml'), '-f', str(override), '--profile', 'product-research']
    env = dict(os.environ)
    # No operator COMPOSE_FILE/project name can redirect this disposable project.
    env.pop('COMPOSE_FILE', None)
    env.pop('COMPOSE_PROFILES', None)
    def call(args, **kwargs):
        return subprocess.run([*compose, *args], cwd=root, env=env, check=True, timeout=180, **kwargs)
    config = json.loads(subprocess.check_output([*compose, 'config', '--format', 'json'], cwd=root, env=env))
    assert config['name'] == project
    assert all(v['name'].startswith(project + '_') for v in config['volumes'].values())
    for name in ('postgres', 's3', 'restore-postgres'):
        assert not config['services'][name].get('ports')
        for volume in config['services'][name].get('volumes', []):
            if volume['type'] == 'volume':
                assert config['volumes'][volume['source']]['name'].startswith(project + '_')
    started = False
    try:
        started = True
        call(['up', '-d', 'postgres', 's3', 'restore-postgres'])
        for service in ('postgres', 'restore-postgres'):
            deadline = time.monotonic() + 45
            while True:
                result = subprocess.run([*compose, 'exec', '-T', '-e', 'PGPASSWORD=product_research_dev', service, 'psql', '-h', '127.0.0.1', '-U', 'svc_product_research_ozon', '-d', 'product_research_ozon_test', '-Atc', 'SELECT 1'], cwd=root, env=env, capture_output=True, timeout=10)
                if result.returncode == 0 and result.stdout.strip() == b'1':
                    break
                if time.monotonic() >= deadline:
                    raise RuntimeError('local_database_not_ready')
                time.sleep(.5)
        call(['build', 'product-research-ozon-dev'], stdout=subprocess.DEVNULL)
        peer = ['run', '--rm', '--no-deps', '-T', '-v', str(receipt) + ':/receipt:rw']
        wait_s3 = '''import socket,time
until=time.monotonic()+45
while True:
 try:
  with socket.create_connection(('s3',8333),timeout=2): break
 except OSError:
  if time.monotonic()>=until: raise RuntimeError('local_storage_not_ready')
  time.sleep(.5)
'''
        call([*peer, 'product-research-ozon-dev', 'python', '-c', wait_s3])
        call([*peer, 'product-research-ozon-dev', 'python', 'tests/recovery_fixture.py', 'prepare'])
        dump = work / 'research.dump'
        with dump.open('wb') as stream:
            call(['exec', '-T', '-e', 'PGPASSWORD=product_research_dev', 'postgres', 'pg_dump', '-h', '127.0.0.1', '-U', 'svc_product_research_ozon', '-d', 'product_research_ozon_test', '-Fc', '--no-owner', '--no-acl'], stdout=stream)
        dump.chmod(0o600)
        with dump.open('rb') as stream:
            call(['exec', '-T', '-e', 'PGPASSWORD=product_research_dev', 'restore-postgres', 'pg_restore', '-h', '127.0.0.1', '-U', 'svc_product_research_ozon', '-d', 'product_research_ozon_test', '--no-owner', '--no-acl', '--exit-on-error'], stdin=stream)
        target = 'postgresql://svc_product_research_ozon:product_research_dev@restore-postgres:5432/product_research_ozon_test'
        call([*peer, '-e', 'RESEARCH_DATABASE_URL=' + target, 'product-research-ozon-dev', 'python', 'tests/recovery_fixture.py', 'verify'])
        print('PASS: disposable recovery project; source and target volumes isolated; no existing DB overwritten')
    finally:
        if started:
            # Only this random, prevalidated project; keeps the main sandbox volumes intact.
            call(['down', '--volumes', '--remove-orphans'], stdout=subprocess.DEVNULL)
