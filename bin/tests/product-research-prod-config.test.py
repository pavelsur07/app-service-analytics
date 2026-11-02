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
print('PASS: research off by default; opt-in services internal-only, nonroot, readonly, isolated config')
