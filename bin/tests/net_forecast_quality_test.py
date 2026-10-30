"""Finite B16 operator jobs. All identities below are synthetic."""
import datetime as dt
import importlib.util
import json
import os
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch
import subprocess

ROOT = Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location('quality', ROOT / 'bin/net-forecast-quality.py')


def identity(n):
    return f'01900000-0000-7000-8000-{n:012x}'


def fixture():
    slots = [{'requestId': identity(i + 10), 'scheduledAtUtc': f'2027-01-{18+i:02d}T09:00:00.000000Z'} for i in range(14)]
    truths = []
    for slot in slots:
        for lag in (7, 100):
            at = dt.datetime.fromisoformat(slot['scheduledAtUtc'].replace('Z', '+00:00')) + dt.timedelta(days=lag)
            truths.append({'requestId': identity(100 + len(truths)), 'inputId': slot['requestId'], 'scheduledAtUtc': at.strftime('%Y-%m-%dT%H:%M:%S.000000Z')})
    return {'protocol': {'kind': 'quality-protocol', 'requestId': identity(1), 'artifactHash': 'a' * 64},
            'data': {'protocolId': identity(1), 'codeCommit': 'b' * 40, 'slots': slots, 'truthSlots': truths,
                     'captureToUtc': '2027-01-31T21:00:00.000000Z', 'truthCutoffUtc': '2027-05-12T00:00:00.000000Z'},
            'image': 'ghcr.io/pavelsur07/app-service-analytics/api@sha256:' + 'c' * 64,
            'epochHash': 'd' * 64,
            'operations': {name: identity(i + 300) for i, name in enumerate(('captureInventory', 'selection', 'truthInventory', 'evaluation'))}}


class JobsTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.q = importlib.util.module_from_spec(SPEC)
        SPEC.loader.exec_module(cls.q)

    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.directory = Path(self.tmp.name)
        os.chmod(self.directory, 0o700)
        self.config = fixture()
        self.path = self.directory / 'automation.json'
        self.path.write_text(json.dumps(self.config))
        self.path.chmod(0o600)

    def backend(self, op, job=None, recover=False):
        self.calls.append((op, job, recover))
        if op == 'audit':
            return {'status': 'verified'}
        if recover:
            return {'status': 'missing'}
        kinds = {'capture': 'input', 'truth': 'truth', 'captureInventory': 'quality-inventory',
                 'truthInventory': 'quality-inventory', 'selection': 'quality-selection', 'evaluation': 'quality-report'}
        return {'status': 'sealed', 'artifact': {'kind': kinds[op], 'requestId': job, 'artifactHash': 'e' * 64}, 'numericRolloutAllowed': False}

    def run_at(self, at, backend=None):
        self.calls = []
        return self.q.run_due(self.path, self.q.utc(at), backend or self.backend)

    def test_future_slots_never_call_producer(self):
        result = self.run_at('2026-10-30T09:00:00.000000Z')
        self.assertEqual([c[0] for c in self.calls], ['audit'])
        self.assertEqual(result['executed'], 0)

    def test_calendar_covers_all_registered_slots_and_cutoffs(self):
        jobs = self.q.jobs(self.config)
        self.assertEqual(len(jobs), 46)
        times = self.q.calendar(self.config)
        self.assertEqual(times.count('OnCalendar='), 39)
        self.assertIn('2027-01-18 09:00:00 UTC', times)
        self.assertIn('2027-05-12 00:03:00 UTC', times)
        self.assertNotIn('OnBootSec', times)

    def test_success_is_not_executed_twice(self):
        at = '2027-01-18T09:00:01.000000Z'
        self.assertEqual(self.run_at(at)['executed'], 1)
        self.assertEqual(self.run_at(at)['executed'], 0)
        self.assertEqual([c[0] for c in self.calls], ['audit'])

    def test_lost_response_retries_same_registered_identity(self):
        published = set()

        def lose(op, job=None, recover=False):
            if op == 'audit':
                return {'status': 'verified'}
            if job not in published:
                published.add(job)
                raise TimeoutError('private financial body must never be printed')
            return self.backend(op, job, recover)

        self.assertEqual(self.run_at('2027-01-18T09:00:01.000000Z', lose)['failed'], 1)
        self.assertEqual(self.run_at('2027-01-18T09:01:00.000000Z', lose)['executed'], 1)
        self.assertEqual(published, {identity(10)})

    def test_missed_slots_only_recover_existing_artifacts(self):
        result = self.run_at('2027-01-18T09:15:00.000000Z')
        self.assertEqual(result['missing'], 1)
        self.assertEqual(self.calls[1], ('capture', identity(10), True))

    def test_recovered_publication_after_deadline_is_kept(self):
        def existing(op, job=None, recover=False):
            if op == 'audit':
                return {'status': 'verified'}
            return {'status': 'sealed', 'artifact': {'kind': 'input', 'requestId': job, 'artifactHash': 'e' * 64}, 'numericRolloutAllowed': False}
        result = self.run_at('2027-01-18T09:20:00.000000Z', existing)
        self.assertEqual(result['missing'], 0)
        self.assertEqual(result['executed'], 1)

    def test_backend_protocol_rejection_prevents_any_job(self):
        def reject(*args):
            raise ValueError('protocol hash mismatch')
        with self.assertRaises(ValueError):
            self.run_at('2027-01-18T09:00:01.000000Z', reject)
        self.assertFalse((self.directory / 'state.json').exists())

    def test_world_readable_or_symlink_configuration_rejected(self):
        self.path.chmod(0o644)
        with self.assertRaises(ValueError):
            self.run_at('2026-10-30T09:00:00.000000Z')
        self.path.chmod(0o600)
        target = self.directory / 'link.json'
        target.symlink_to(self.path)
        with self.assertRaises(ValueError):
            self.q.load_private(target)

    def test_concurrent_invocation_is_rejected(self):
        import fcntl
        with (self.directory / 'run.lock').open('w') as lock:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
            with self.assertRaises(BlockingIOError):
                self.run_at('2027-01-18T09:00:01.000000Z')
        self.assertEqual(self.calls, [])

    def test_invalid_image_or_duplicate_identity_rejected(self):
        self.config['image'] = 'arbitrary/producer:latest'
        with self.assertRaises(ValueError):
            self.q.jobs(self.config)
        self.config = fixture()
        self.config['data']['truthSlots'][0]['requestId'] = identity(10)
        with self.assertRaises(ValueError):
            self.q.jobs(self.config)

    def test_failed_truth_does_not_become_success_or_zero(self):
        def no_parent(op, job=None, recover=False):
            if op == 'audit':
                return {'status': 'verified'}
            if op == 'truth' and not recover:
                raise ValueError('missing input')
            return self.backend(op, job, recover)
        result = self.run_at('2027-01-25T09:00:01.000000Z', no_parent)
        self.assertEqual(result['failed'], 1)
        state = self.q.load_private(self.directory / 'state.json')
        self.assertEqual(state[identity(100)]['status'], 'failed')
        self.assertNotIn('quantity', state[identity(100)])

    def test_clock_is_refreshed_when_inventory_spans_the_evaluation_trigger(self):
        moment = [self.q.utc('2027-05-12T00:01:01.000000Z')]

        def slow_inventory(op, job=None, recover=False):
            if op == 'truthInventory':
                moment[0] = self.q.utc('2027-05-12T00:04:00.000000Z')
            return self.backend(op, job, recover)

        self.calls = []
        self.q.run_due(self.path, moment[0], slow_inventory, clock=lambda: moment[0])
        self.assertIn(('evaluation', self.config['operations']['evaluation'], False), self.calls)

    def test_changing_operation_ids_after_execution_is_rejected(self):
        self.run_at('2027-01-18T09:00:01.000000Z')
        self.config['operations']['evaluation'] = identity(999)
        self.path.write_text(json.dumps(self.config))
        with self.assertRaises(ValueError):
            self.run_at('2027-01-18T09:01:00.000000Z')

    def test_sealed_without_correct_publication_ref_is_not_success(self):
        def malformed(op, job=None, recover=False):
            return {'status': 'verified'} if op == 'audit' else {'status': 'sealed'}
        self.assertEqual(self.run_at('2027-01-18T09:00:01.000000Z', malformed)['failed'], 1)

    def test_interrupted_docker_client_stops_its_container(self):
        seen = []
        present = [True]
        def interrupted(argv, **kwargs):
            seen.append(argv)
            if argv[:2] == ['docker', 'compose']:
                self.assertIn(str(self.q.COMPOSE_ROOT / 'net-forecast-quality/compose.yml'), argv)
                raise SystemExit(143)
            if argv[:2] == ['docker', 'ps']:
                active = self.q.load_private(self.directory / 'active.json')
                return subprocess.CompletedProcess(argv, 0, active['name']+' '+self.config['protocol']['requestId']+'\n' if present[0] else '')
            if argv[:2] == ['docker', 'stop']:
                present[0] = False
            return subprocess.CompletedProcess(argv, 0, '')
        with patch.object(self.q.subprocess, 'run', side_effect=interrupted):
            with self.assertRaises(SystemExit):
                self.q.DockerBridge(self.config, self.directory)('audit')
        self.assertTrue(any(cmd[:2] == ['docker', 'stop'] for cmd in seen))
        self.assertFalse((self.directory / 'active.json').exists())

    def test_stop_post_never_stops_a_container_with_foreign_label(self):
        self.q.save_private(self.directory / 'active.json', {'name': 'conwix-net-quality-'+identity(10), 'protocol': self.config['protocol']['requestId']})
        def foreign(argv, **kwargs):
            if argv[:2] != ['docker', 'ps']:
                self.fail('A foreign container must not be stopped')
            return subprocess.CompletedProcess(argv, 0, 'conwix-net-quality-'+identity(10)+' '+identity(999)+'\n')
        with patch.object(self.q.subprocess, 'run', side_effect=foreign):
            with self.assertRaises(ValueError):
                self.q.stop_active(self.config, self.directory)
        self.assertTrue((self.directory / 'active.json').exists())

    def test_created_container_is_removed_before_marker_disappears(self):
        name = 'conwix-net-quality-'+identity(10)
        self.q.save_private(self.directory / 'active.json', {'name': name, 'protocol': self.config['protocol']['requestId']})
        present = [True]
        def created(argv, **kwargs):
            if argv[:2] == ['docker', 'ps']:
                return subprocess.CompletedProcess(argv, 0, name+' '+self.config['protocol']['requestId']+'\n' if present[0] else '')
            if argv[:2] == ['docker', 'rm']:
                self.assertTrue((self.directory / 'active.json').exists())
                present[0] = False
            return subprocess.CompletedProcess(argv, 0, '')
        with patch.object(self.q.subprocess, 'run', side_effect=created):
            self.q.stop_active(self.config, self.directory)
        self.assertFalse(present[0], 'docker stop alone leaves created containers behind')
        self.assertFalse((self.directory / 'active.json').exists())


if __name__ == '__main__':
    unittest.main()
