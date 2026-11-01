#!/usr/bin/env python3
"""Local fixture-only cross-language smoke. Host Python uses only the standard library."""
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import time
import uuid

root = Path(__file__).resolve().parents[1]
(root/'var').mkdir(exist_ok=True)
compose = sys.argv[1:] or ['docker', 'compose']
name = 'research-stage5-http'
company_bytes = bytearray(uuid.uuid4().bytes)
company_bytes[6] = (company_bytes[6] & 15) | 112
company = str(uuid.UUID(bytes=bytes(company_bytes)))
with tempfile.TemporaryDirectory(prefix='research-http-', dir=root/'var') as folder:
    receipt = Path(folder)
    receipt.chmod(0o777)  # Only an ephemeral non-secret source UUID is written here by uid 10001.
    started = False
    try:
        subprocess.run([*compose, '--profile', 'product-research', 'run', '--rm', '--no-deps', '-d', '--name', name, '-v', str(receipt)+':/receipt:rw', 'product-research-ozon-dev', 'python', 'tests/run_http_smoke.py', company], cwd=root, check=True, timeout=30, stdout=subprocess.DEVNULL)
        started = True
        until = time.monotonic()+30
        while not (receipt/'source').is_file():
            if time.monotonic()>=until:
                raise RuntimeError('python_test_peer_startup_timeout')
            time.sleep(.1)
        source = (receipt/'source').read_text()
        # Wait until the socket listens, not merely until metadata was written.
        subprocess.run([*compose, 'exec', '-T', 'php-cli', 'php', '-r', "$end=microtime(true)+10; do { $s=@fsockopen('research-stage5-http',8080,$n,$m,1); if($s){fclose($s);exit(0);} usleep(100000); } while(microtime(true)<$end); exit(1);"], cwd=root, check=True, timeout=15)
        subprocess.run([*compose, 'exec', '-T', '-e', 'APP_ENV=test', 'php-cli', 'php', 'tests/Support/ProductResearchOzon/http-smoke.php'], input=json.dumps({'company':company,'source':source})+'\n',text=True,cwd=root,check=True,timeout=35)
    finally:
        if started:
            exists = subprocess.run(['docker','inspect',name],capture_output=True,timeout=10)
            if exists.returncode == 0:
                subprocess.run(['docker','stop','--time','5',name],check=True,timeout=15,stdout=subprocess.DEVNULL)
