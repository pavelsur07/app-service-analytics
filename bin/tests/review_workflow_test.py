"""Real Git/Make workflow; only the external, paid model CLI is replaced."""
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

SOURCE = Path(__file__).resolve().parents[2]


class ReviewWorkflowTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)
        for name in ('bin/review-prepare.sh', 'bin/review.py', 'Makefile',
                     'docs/review-package-template.md'):
            dest = self.root / name
            dest.parent.mkdir(parents=True, exist_ok=True)
            if (SOURCE / name).exists():
                shutil.copy2(SOURCE / name, dest)
        (self.root / 'CLAUDE.md').write_text('## Обязательные правила\nTenant isolation.\n## Other\n')
        (self.root / 'AGENTS.md').write_text('Always independent Claude review.\n')
        (self.root / 'docs/adr').mkdir()
        (self.root / 'docs/adr/0001-test.md').write_text('ADR-001: test decision.\n')
        (self.root / '.gitignore').write_text('/var/\n')
        (self.root / 'feature.txt').write_text('original\n')
        (self.root / 'unrelated.txt').write_text('unrelated-original\n')
        self.git('init', '-q', '-b', 'master')
        self.git('config', 'user.email', 'test@example.invalid')
        self.git('config', 'user.name', 'Test')
        self.git('add', '.')
        self.git('commit', '-qm', 'base')
        self.base = self.git('rev-parse', 'HEAD').strip()
        self.git('switch', '-qc', 'task')
        (self.root / 'feature.txt').write_text('reviewed-change\n')
        (self.root / 'var').mkdir()
        (self.root / 'var/paths').write_text('feature.txt\n')
        self.env = dict(os.environ, TASK='Change feature', CRITERIA='Feature works',
                        CHECKS='synthetic verification', REVIEW_PATHS_FILE='var/paths',
                        ADR='0001', REVIEW_TIMEOUT='2')
        for key in ('REVIEW_BASE', 'REVIEW_PACKAGE', 'REVIEW_CONTEXT_FILE', 'REVIEW_RISK'):
            self.env.pop(key, None)
        fake = self.root / 'fake-bin'
        fake.mkdir()
        self.env['PATH'] = str(fake) + os.pathsep + os.environ['PATH']
        # Validate the CLI boundary; never let a regression invoke real Claude/Codex.
        model = '''#!/usr/bin/env python3
import json, os, re, sys, time
args=sys.argv[1:]
s=open(os.environ['REQUEST_CAPTURE'], 'w', encoding='utf-8')
request=sys.stdin.buffer.read().decode('utf-8'); s.write(request); s.close()
mode=os.environ.get('MODEL_MODE', 'complete')
assert not os.getcwd().startswith(os.environ['REVIEW_TEST_ROOT'] + os.sep)
if mode == 'git-error': os.rename(os.environ['REVIEW_TEST_ROOT']+'/.git', os.environ['REVIEW_TEST_ROOT']+'/.git-away')
if mode == 'timeout': time.sleep(10)
if mode == 'error': print('authentication failed', file=sys.stderr); sys.exit(1)
if mode == 'empty': sys.exit(0)
if mode == 'mutate': open(os.environ['MUTATE_PATH'], 'w').write('changed while reviewing')
sha=re.search(r'PACKAGE_SHA256: ([0-9a-f]{64})', request).group(1)
payload={'status':'complete','package_sha256':sha,'summary':'Reviewed all supplied changes.', 'findings':[]}
if mode == 'partial': payload['status']='incomplete'
if mode == 'wrong-hash': payload['package_sha256']='0'*64
if mode == 'findings': payload['findings']=[{'kind':'дефект','location':'feature.txt:1','detail':'Concrete defect and failure scenario.'}]
result=json.dumps(payload)
if mode == 'prose': result='I will review it later.'
if '--print' in args:
    assert args[args.index('--tools')+1] == ''
    assert not os.getcwd().startswith(os.environ['REVIEW_TEST_ROOT'] + os.sep)
    assert '--strict-mcp-config' in args and '--no-session-persistence' in args
    output={'type':'result','subtype':'success','is_error':False,'result':result, 'modelUsage':{'test-claude':{}}}
    if mode == 'api-error': output['is_error']=True
    if mode == 'no-model': output.pop('modelUsage')
    print(json.dumps(output))
else:
    assert args[0]=='exec' and args[args.index('--sandbox')+1]=='read-only'
    assert '--skip-git-repo-check' in args
    open(args[args.index('-o')+1], 'w').write(result)
'''
        for cli in ('claude', 'codex'):
            (fake / cli).write_text(model)
            (fake / cli).chmod(0o755)
        self.env['REQUEST_CAPTURE'] = str(self.root / 'var/request-captured')
        self.env['REVIEW_TEST_ROOT'] = str(self.root)

    def git(self, *args):
        return subprocess.check_output(['git', *args], cwd=self.root, text=True)

    def make(self, target, success=True, **env):
        result = subprocess.run(['make', '--no-print-directory', target], cwd=self.root,
                                env=dict(self.env, **env), text=True, capture_output=True)
        if success:
            self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        else:
            self.assertNotEqual(result.returncode, 0, result.stdout + result.stderr)
        return result

    def package(self):
        return self.root / 'var/review' / (self.root / 'var/review/current').read_text().strip()

    def runs(self):
        return sorted((self.root / 'var/review/runs').glob('*/metadata.json'))

    def test_scope_includes_new_and_committed_files_without_touching_index(self):
        self.git('add', 'feature.txt')
        self.git('commit', '-qm', 'feature change')
        (self.root / 'new file.txt').write_text('new-selected\n')
        (self.root / 'var/paths').write_text('feature.txt\nnew file.txt\n')
        (self.root / 'unrelated.txt').write_text('do-not-send\n')
        (self.root / 'outsider.txt').write_text('foreign-untracked\n')
        self.git('add', 'unrelated.txt')
        before = (self.root / '.git/index').read_bytes()
        self.make('review-prepare')
        self.assertEqual((self.root / '.git/index').read_bytes(), before)
        p = self.package()
        diff = (p / 'diff.patch').read_text()
        self.assertIn('+reviewed-change', diff)
        self.assertIn('+new-selected', diff)
        self.assertNotIn('do-not-send', diff)
        self.assertNotIn('foreign-untracked', diff)
        meta = json.loads((p / 'manifest.json').read_text())
        self.assertEqual(meta['base'], self.base)
        self.assertEqual(meta['head'], self.git('rev-parse', 'HEAD').strip())
        self.assertEqual(meta['sha256'], hashlib.sha256((p / 'package.md').read_bytes()).hexdigest())

    def test_no_base_fails_instead_of_hiding_committed_changes(self):
        self.git('branch', '-D', 'master')
        self.make('review-prepare', success=False)
        self.make('review-prepare', REVIEW_BASE=self.base)

    def test_explicit_scope_required_and_failed_prepare_clears_current(self):
        self.make('review-prepare')
        self.make('review-prepare', success=False, REVIEW_PATHS_FILE='')
        self.assertFalse((self.root / 'var/review/current').exists())
        self.make('review-claude', success=False)

    def test_scope_rejects_missing_paths_directories_and_traversal(self):
        for path in ('does-not-exist', '../escape', ':all', '.', 'var/paths'):
            with self.subTest(path=path):
                (self.root / 'var/paths').write_text(path + '\n')
                self.make('review-prepare', success=False)

    def test_deleted_file_is_included(self):
        (self.root / 'feature.txt').unlink()
        self.make('review-prepare')
        self.assertIn('-original', (self.package() / 'diff.patch').read_text())

    def test_current_snapshot_and_package_integrity_checked_before_model(self):
        self.make('review-prepare')
        (self.root / 'feature.txt').write_text('unreviewed revision\n')
        self.make('review-claude', success=False)
        self.assertFalse(Path(self.env['REQUEST_CAPTURE']).exists())
        self.make('review-prepare')
        with (self.package() / 'package.md').open('a') as f:
            f.write('tampered\n')
        self.make('review-claude', success=False)
        self.assertFalse(Path(self.env['REQUEST_CAPTURE']).exists())

    def test_changes_during_review_cannot_produce_current_completion(self):
        self.make('review-prepare')
        self.make('review-claude', success=False, MODEL_MODE='mutate',
                  MUTATE_PATH=str(self.root / 'feature.txt'))
        meta = json.loads(self.runs()[-1].read_text())
        self.assertEqual(meta['status'], 'failed')

    def test_committed_deletion_and_fix_round_remain_reviewable(self):
        (self.root / 'feature.txt').unlink()
        self.git('add', '-A')
        self.git('commit', '-qm', 'delete feature')
        self.make('review-prepare')
        self.make('review-claude')
        first = json.loads(self.runs()[-1].read_text())
        (self.root / 'feature.txt').write_text('fixed implementation\n')
        self.make('review-prepare')
        self.make('review-claude')
        second = json.loads(self.runs()[-1].read_text())
        self.assertNotEqual(first['package_sha256'], second['package_sha256'])
        self.assertEqual(len(self.runs()), 2)

    def test_git_failure_after_cli_is_recorded_as_failed_not_running(self):
        self.make('review-prepare')
        self.make('review-claude', success=False, MODEL_MODE='git-error')
        meta = json.loads(self.runs()[-1].read_text())
        self.assertEqual(meta['status'], 'failed')
        self.assertTrue(meta['finished_at'])

    def test_git_diagnostic_is_visible_to_author(self):
        result = self.make('review-prepare', success=False, REVIEW_BASE='missing-review-base')
        self.assertIn('fatal:', result.stderr)

    def test_missing_model_metadata_is_explicit(self):
        self.make('review-prepare')
        self.make('review-claude', MODEL_MODE='no-model')
        meta = json.loads(self.runs()[-1].read_text())
        self.assertFalse(meta['models_reported'])
        self.assertTrue(meta['model_note'])

    def test_empty_automatic_adrs_do_not_claim_author_confirmation(self):
        self.env.pop('ADR')
        self.make('review-prepare')
        p = self.package()
        meta = json.loads((p / 'manifest.json').read_text())
        self.assertEqual(meta['adr_source'], 'diff')
        self.assertNotIn('автор проверил применимость ADR', (p / 'package.md').read_text())
        self.make('review-prepare', ADR='')
        meta = json.loads((self.package() / 'manifest.json').read_text())
        self.assertEqual(meta['adr_source'], 'explicit')

    def test_environment_templates_and_versioned_defaults_can_be_reviewed(self):
        (self.root / '.env.example').write_text('EXAMPLE=placeholder\n')
        (self.root / 'var/paths').write_text('.env.example\n')
        self.make('review-prepare')
        self.assertIn('+EXAMPLE=placeholder', (self.package() / 'diff.patch').read_text())
        (self.root / '.env').write_text('DEFAULT=local\n')
        self.git('add', '.env')
        self.git('commit', '-qm', 'versioned defaults')
        (self.root / '.env').write_text('DEFAULT=updated-local\n')
        (self.root / 'var/paths').write_text('.env\n')
        self.make('review-prepare')
        (self.root / '.env.local').write_text('SECRET=do-not-send\n')
        (self.root / 'var/paths').write_text('.env.local\n')
        self.make('review-prepare', success=False)

    def test_unicode_filenames_are_readable_in_package_diff(self):
        (self.root / 'пример.txt').write_text('example\n')
        (self.root / 'var/paths').write_text('пример.txt\n')
        self.make('review-prepare')
        self.assertIn('b/пример.txt', (self.package() / 'diff.patch').read_text())

    def test_ascii_host_locale_does_not_break_utf8_review_artifacts(self):
        locale = {'LC_ALL': 'C', 'PYTHONUTF8': '0', 'PYTHONCOERCECLOCALE': '0'}
        self.make('review-prepare', **locale)
        self.make('review-claude', MODEL_MODE='findings', **locale)
        meta = json.loads(self.runs()[-1].read_text(encoding='utf-8'))
        self.assertEqual(meta['status'], 'complete')
        review = (self.runs()[-1].parent / 'review.md').read_text(encoding='utf-8')
        self.assertIn('дефект', review)

    def test_environment_named_directory_is_not_a_secret_file(self):
        (self.root / '.envs').mkdir()
        (self.root / '.envs/config.yml').write_text('public: placeholder\n')
        (self.root / 'var/paths').write_text('.envs/config.yml\n')
        self.make('review-prepare')
        self.assertIn('+public: placeholder', (self.package() / 'diff.patch').read_text())

    def test_changed_context_requires_new_package(self):
        (self.root / 'var/context').write_text('unrelated.txt\n')
        self.make('review-prepare', REVIEW_CONTEXT_FILE='var/context')
        (self.root / 'unrelated.txt').write_text('changed-context\n')
        self.make('review-claude', success=False)

    def test_complete_review_persists_model_and_never_overwrites_runs(self):
        self.make('review-prepare')
        self.make('review-claude')
        request = Path(self.env['REQUEST_CAPTURE']).read_text()
        self.assertIn('## 5C.', request)
        self.assertNotIn('## 5A.', request)
        self.assertNotIn('## 5B.', request)
        first = self.runs()
        meta = json.loads(first[0].read_text())
        self.assertEqual(meta['status'], 'complete')
        self.assertEqual(meta['models'], ['test-claude'])
        self.make('review-claude')
        self.assertEqual(len(self.runs()), 2)
        self.assertTrue(first[0].exists())

    def test_incomplete_and_failed_outputs_are_not_successful_reviews(self):
        self.make('review-prepare')
        for mode in ('partial', 'wrong-hash', 'prose', 'api-error', 'empty', 'error', 'timeout'):
            with self.subTest(mode=mode):
                self.make('review-claude', success=False, MODEL_MODE=mode, REVIEW_TIMEOUT='1')
                meta = json.loads(self.runs()[-1].read_text())
                self.assertNotEqual(meta['status'], 'complete')

    def test_findings_are_saved_for_resolution_not_automatic_approval(self):
        self.make('review-prepare')
        self.make('review-claude', MODEL_MODE='findings')
        meta = json.loads(self.runs()[-1].read_text())
        self.assertEqual(meta['findings_count'], 1)
        self.assertIn('Concrete defect', (self.runs()[-1].parent / 'review.md').read_text())

    def test_make_review_always_runs_claude_and_risk_adds_both_codex_roles(self):
        self.make('review', REVIEW_RISK='standard')
        self.assertEqual([json.loads(p.read_text())['role'] for p in self.runs()], ['claude'])
        self.make('review', REVIEW_RISK='high')
        roles = [json.loads(p.read_text())['role'] for p in self.runs()]
        self.assertEqual(roles.count('claude'), 2)
        self.assertIn('codex', roles)
        self.assertIn('defects', roles)
        self.make('review', success=False, REVIEW_RISK='typo')


if __name__ == '__main__':
    unittest.main(verbosity=2)
