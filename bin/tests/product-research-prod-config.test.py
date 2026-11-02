#!/usr/bin/env python3
"""Parse the optional stack with a missing test env file; never start containers."""
import json
import os
from pathlib import Path
import subprocess
import tempfile

root = Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='research-config-') as folder:
    env = {**os.environ, 'RESEARCH_ENV_FILE': str(Path(folder) / 'missing.env'), 'RESEARCH_IMAGE': 'example.invalid/research:test', 'RESEARCH_MEMORY_LIMIT': '256m'}
    command = ['docker', 'compose', '-f', str(root / 'docker-compose.research.prod.yml')]
    def config(profile):
        return json.loads(subprocess.check_output([*command, *profile, 'config', '--format', 'json'], env=env))
    assert config([])['services'] == {}
    active = config(['--profile', 'research'])
    assert set(active['services']) == {'product-research-ozon', 'product-research-ozon-worker'}
    for service in active['services'].values():
        assert service['image'] == 'example.invalid/research:test'
        assert not service.get('ports') and not service.get('privileged')
        assert service['read_only'] and service['user'] == '10001:10001'
        assert service['cap_drop'] == ['ALL']
        assert service['labels']['traefik.enable'] == 'false'
        assert set(service['networks']) == {'internal', 'egress'}
        assert service['healthcheck']['test'][0] == 'CMD'
    assert active['networks']['internal']['external']
    assert active['networks']['egress']['external']

    # Deployment uses the existing raw bucket credentials, overriding old env_file values.
    # Synthetic values exercise literal dollar/hash/quotes without ever reading real keys.
    research_env = Path(folder) / 'research.env'
    research_env.write_text('RESEARCH_S3_ACCESS_KEY=old-key\nRESEARCH_S3_SECRET_KEY=old-secret\n')
    research_env.chmod(0o600)
    before = research_env.read_bytes()
    shared = {
        'RAW_STORAGE_ENDPOINT': 'https://example.invalid',
        'RAW_STORAGE_BUCKET': 'existing-private-bucket',
        'RAW_STORAGE_ACCESS_KEY': 'test-key-$literal#value',
        'RAW_STORAGE_SECRET_KEY': "test-secret-$literal#'value",
    }
    env.update(shared)
    env['RESEARCH_ENV_FILE'] = str(research_env)
    for service in config(['--profile', 'research'])['services'].values():
        effective = service['environment']
        for suffix in ('ENDPOINT', 'BUCKET', 'ACCESS_KEY', 'SECRET_KEY'):
            # config serializes literal dollars as $$ for a reusable Compose document.
            assert effective['RESEARCH_S3_' + suffix] == shared['RAW_STORAGE_' + suffix].replace('$', '$$')
        assert 'MPSTATS_API_TOKEN' not in effective
    assert research_env.read_bytes() == before
    env['RAW_STORAGE_SECRET_KEY'] = ''
    for service in config(['--profile', 'research'])['services'].values():
        assert service['environment']['RESEARCH_S3_SECRET_KEY'] == ''
    assert config([])['services'] == {}
print('PASS: research off by default; opt-in services internal-only, nonroot, readonly, isolated config')
print('PASS: existing raw S3 credentials injected literally into API/worker; old env values overridden; missing secret stays empty; no MPStats secret')
