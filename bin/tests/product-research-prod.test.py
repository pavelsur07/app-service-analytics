#!/usr/bin/env python3
"""Real isolated PostgreSQL provisioning and fail-closed stack wrapper tests."""
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import time
import uuid

root = Path(__file__).resolve().parents[2]
project = 'research-provision-' + uuid.uuid4().hex[:12]
with tempfile.TemporaryDirectory(prefix=project + '-', dir=root / 'var') as folder:
    work = Path(folder)
    calls = work / 'calls'
    resolved = work / 'resolved'
    fake = work / 'compose.sh'
    fake.write_text('#!/bin/sh\nif [ "$*" = "config --environment" ]; then\ncat "' + str(resolved) + '"\nelse\nprintf "%s\\n" "$*" >> "' + str(calls) + '"\nfi\n')
    fake.chmod(0o700)
    wrapper = ['sh', str(root / 'bin/product-research-ozon-compose.sh')]
    env = {**os.environ, 'RESEARCH_COMPOSE': str(fake)}
    for flag, expected in (('true', 0), ('false', 1), ('', 1), ('TRUE', 2)):
        resolved.write_text('SECRET=do-not-print\nRESEARCH_STACK_ENABLED=' + flag + '\n')
        result = subprocess.run([*wrapper, '--enabled'], env=env, capture_output=True)
        assert result.returncode == expected
        result = subprocess.run([*wrapper, 'up', '-d'], env=env, capture_output=True)
        assert result.returncode == expected
        assert b'do-not-print' not in result.stdout + result.stderr
        if expected == 0:
            assert calls.read_text().strip() == '--profile research up -d'
            calls.unlink()
        else:
            assert not calls.exists()
    resolved.unlink()
    result = subprocess.run([*wrapper, 'up', '-d'], env=env, capture_output=True)
    assert result.returncode == 2 and not calls.exists()

    # A fresh stock PostgreSQL with no service init scripts. All volumes are disposable.
    config = work / 'compose.json'
    config.write_text(json.dumps({'services': {'postgres': {
        'image': 'postgres:16-alpine',
        'environment': {'POSTGRES_USER': 'app', 'POSTGRES_PASSWORD': 'local-test', 'POSTGRES_DB': 'app'},
        'volumes': ['data:/var/lib/postgresql/data'],
    }}, 'volumes': {'data': {}}}))
    compose = ['docker', 'compose', '-p', project, '-f', str(config)]
    runtime = {key: value for key, value in os.environ.items() if key not in ('COMPOSE_FILE', 'COMPOSE_PROFILES')}
    parsed = json.loads(subprocess.check_output([*compose, 'config', '--format', 'json'], env=runtime))
    assert parsed['name'] == project
    assert all(value['name'].startswith(project + '_') for value in parsed['volumes'].values())
    assert not parsed['services']['postgres'].get('ports')
    for source in ('bin/product-research-ozon-prod-db.sh', 'services/docker/postgres/init/04-product-research-ozon-hba.sh'):
        shutil.copyfile(root / source, work / Path(source).name)
    secret = 'a' * 64  # Synthetic test credential, never a production value.
    dotenv = work / 'research.env'
    dotenv.write_text('RESEARCH_DB_PASSWORD=' + secret + '\n')
    dotenv.chmod(0o600)
    provision = ['sh', str(work / 'product-research-ozon-prod-db.sh')]
    runtime.update(COMPOSE=' '.join(compose), RESEARCH_ENV_FILE=str(dotenv))

    def call(args, **kwargs):
        return subprocess.run([*compose, *args], env=runtime, check=True, timeout=60, **kwargs)

    started = False
    try:
        started = True
        call(['up', '-d', 'postgres'], stdout=subprocess.DEVNULL)
        deadline = time.monotonic() + 40
        while True:
            probe = subprocess.run([*compose, 'exec', '-T', 'postgres', 'pg_isready', '-h', '127.0.0.1', '-U', 'app', '-d', 'app'], env=runtime, capture_output=True, timeout=5)
            if probe.returncode == 0:
                break
            if time.monotonic() >= deadline:
                raise RuntimeError('isolated_postgres_not_ready')
            time.sleep(.5)
        # Invalid input is rejected before PostgreSQL receives any mutation.
        for value in ('short', secret + "'", secret + '\nRESEARCH_DB_PASSWORD=' + secret):
            dotenv.write_text('RESEARCH_DB_PASSWORD=' + value + '\n')
            result = subprocess.run(provision, env=runtime, capture_output=True, timeout=20)
            assert result.returncode != 0
            assert secret.encode() not in result.stdout + result.stderr
        dotenv.write_text('RESEARCH_DB_PASSWORD=' + secret + '\n')
        for _ in range(2):
            result = subprocess.run(provision, env=runtime, capture_output=True, timeout=20)
            assert result.returncode == 0, result.stderr
            assert secret.encode() not in result.stdout + result.stderr
        call(['exec', '-T', 'postgres', 'psql', '-U', 'app', '-d', 'app', '-v', 'ON_ERROR_STOP=1', '-c', 'CREATE DATABASE future_conwix'], stdout=subprocess.DEVNULL)
        runtime['PGPASSWORD'] = secret
        def connect(database, sql='SELECT 1'):
            return subprocess.run([*compose, 'exec', '-T', '-e', 'PGPASSWORD', 'postgres', 'psql', '-h', '127.0.0.1', '-U', 'svc_product_research_ozon', '-d', database, '-Atc', sql], env=runtime, capture_output=True, timeout=10)
        result = connect('product_research_ozon', 'SELECT rolsuper,rolcreatedb,rolcreaterole,rolbypassrls FROM pg_roles WHERE rolname=current_user')
        assert result.returncode == 0 and result.stdout.strip() == b'f|f|f|f'
        for database in ('app', 'postgres', 'future_conwix'):
            assert connect(database).returncode != 0
        print('PASS: explicit own DB/role; idempotent provisioning; current/future foreign DB denied; no bucket creation or migration')
    finally:
        if started:
            call(['down', '--volumes'], stdout=subprocess.DEVNULL)
print('PASS: stack defaults closed; invalid/unknown configuration refuses commands without exposing environment')
