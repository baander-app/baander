import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from dev_environment.model import StateStore, WorkflowError, preview_id, resolve_instance, clean_environment
from dev_environment.cli import status
from dev_environment.process import Runner


class StateTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)
        self.store = StateStore(self.root / 'state')

    def test_worktree_identity_is_stable_and_distinct(self):
        first = preview_id(self.root / 'one' / 'feature', 'repo')
        self.assertEqual(first, preview_id(self.root / 'one' / 'feature', 'repo'))
        self.assertNotEqual(first, preview_id(self.root / 'two' / 'feature', 'repo'))
        self.assertNotEqual(first, preview_id(self.root / 'one' / 'feature', 'other'))

    def test_missing_preview_never_selects_persistent(self):
        self.store.save('persistent', {'id': 'persistent', 'kind': 'persistent'})
        with self.assertRaisesRegex(WorkflowError, 'preview-up'):
            resolve_instance(self.store, self.root, 'repo')
        self.assertEqual('persistent', resolve_instance(self.store, self.root, 'repo', 'persistent')['id'])

    def test_private_atomic_record_and_independent_disable_intent(self):
        self.store.save('persistent', {'id': 'persistent', 'phase': 'migrating'})
        self.store.set_enabled('persistent', True)
        with self.store.lock('persistent'):
            self.store.set_enabled('persistent', False)
            self.assertFalse(self.store.enabled('persistent'))
            self.assertEqual('migrating', self.store.load('persistent')['phase'])
        self.assertEqual(0o600, (self.store.directory('persistent') / 'state.json').stat().st_mode & 0o777)
        self.assertEqual(0o700, self.store.directory('persistent').stat().st_mode & 0o777)

    def test_corrupt_or_mismatched_record_is_not_reinitialized(self):
        self.store.save('persistent', {'id': 'persistent'})
        state = self.store.directory('persistent') / 'state.json'
        state.write_text('{')
        with self.assertRaises(WorkflowError):
            self.store.load('persistent')
        state.write_text(json.dumps({'id': 'elsewhere'}))
        with self.assertRaises(WorkflowError):
            self.store.load('persistent')

    def test_identifiers_and_symlinks_cannot_escape_owned_state(self):
        for name in ('../outside', '/tmp/outside', '', 'PERSISTENT'):
            with self.subTest(name=name), self.assertRaises(WorkflowError):
                self.store.directory(name)
        self.store.root.mkdir(parents=True, exist_ok=True)
        (self.store.root / 'persistent').symlink_to(self.root, target_is_directory=True)
        with self.assertRaises(WorkflowError):
            self.store.save('persistent', {'id': 'persistent'})

    def test_child_environment_excludes_ambient_targeting_and_secrets(self):
        env = clean_environment({'PATH': '/usr/bin', 'HOME': '/home/developer',
                                 'DOCKER_HOST': 'tcp://baander.app:2375',
                                 'COMPOSE_FILE': '/tmp/other.yml', 'DATABASE_URL': 'secret',
                                 'GIT_DIR': '/tmp/repo', 'APP_SECRET': 'secret',
                                 'SSH_AUTH_SOCK': '/tmp/agent'})
        self.assertEqual({'PATH', 'HOME', 'SSH_AUTH_SOCK'}, set(env))

    def test_process_lock_refuses_concurrent_mutation_but_allows_status(self):
        self.store.save('persistent', {'id': 'persistent', 'phase': 'preparing'})
        with self.store.lock('persistent'):
            code = "from pathlib import Path; from dev_environment.model import StateStore; s=StateStore(Path(__import__('sys').argv[1])); print(s.load('persistent')['phase']);\nwith s.lock('persistent', blocking=False): pass"
            child = subprocess.run([sys.executable, '-c', code, str(self.store.root)],
                                   env={**os.environ, 'PYTHONPATH': str(Path(__file__).resolve().parents[1])},
                                   capture_output=True, text=True)
            self.assertNotEqual(0, child.returncode)
            self.assertIn('preparing', child.stdout)
            self.assertIn('another operation', child.stderr)

    def test_status_does_not_publish_saved_credentials(self):
        record = {'id': 'persistent', 'phase': 'blocked', 'running_revision': 'old',
                  'target_revision': 'new', 'environment': {'APP_SECRET': 'private'},
                  'failure': {'step': 'migration', 'action': 'Inspect the checkpoint.'}}
        result = status(self.store, record)
        self.assertNotIn('private', json.dumps(result))
        self.assertEqual('old', result['running_revision'])
        self.assertEqual('new', result['target_revision'])
        self.assertEqual('migration', result['failure']['step'])

    def test_process_receives_only_explicit_target_environment(self):
        from unittest.mock import patch
        with patch.dict(os.environ, {'DATABASE_URL': 'unrelated', 'COMPOSE_PROJECT_NAME': 'wrong'}):
            result = Runner().run([sys.executable, '-c',
                'import json,os; print(json.dumps({k:v for k,v in os.environ.items() if k in ("DATABASE_URL","COMPOSE_PROJECT_NAME")}))'],
                env={'DATABASE_URL': 'selected'})
        self.assertEqual({'DATABASE_URL': 'selected'}, json.loads(result.stdout))


if __name__ == '__main__':
    unittest.main()
