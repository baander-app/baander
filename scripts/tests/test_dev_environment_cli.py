from pathlib import Path
import json
import io
import os
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from dev_environment.cli import configure, exec_command, main, parser
from dev_environment.model import StateStore, WorkflowError
from dev_environment.process import Runner


class StateIsolationTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.base = Path(self.temporary.name)
        self.root = self.base / 'checkout'
        self.root.mkdir()
        self.store = StateStore(self.base / 'private-state')

    def test_state_inside_checkout_is_rejected_before_creating_or_saving_state(self):
        for state in (self.root, self.root / 'private-state'):
            store = StateStore(state)
            with patch.object(store, 'lock') as lock, patch.object(store, 'save') as save:
                with self.assertRaisesRegex(WorkflowError, 'outside the worktree'):
                    configure(store, Runner(), self.root, 'repository', parser().parse_args(['up']))
            lock.assert_not_called()
            save.assert_not_called()

    def test_state_symlink_ancestor_inside_checkout_is_rejected(self):
        (self.base / 'linked-checkout').symlink_to(self.root, target_is_directory=True)
        store = StateStore(self.base / 'linked-checkout/private-state')
        with self.assertRaisesRegex(WorkflowError, 'outside the worktree'):
            configure(store, Runner(), self.root, 'repository', parser().parse_args(['up']))

    def test_host_setup_rejects_private_state_in_checkout_before_creating_host_files(self):
        subprocess.run(['git', 'init', '-q', str(self.root)], check=True, capture_output=True)
        with patch('dev_environment.cli.Path.cwd', return_value=self.root), \
                patch('dev_environment.cli.Host') as host, patch('sys.stderr', new=io.StringIO()):
            self.assertEqual(1, main(['--state-root', str(self.root / 'private-state'), 'host-setup']))
        host.assert_not_called()
        self.assertFalse((self.root / 'private-state').exists())

    def test_host_setup_outside_git_accepts_an_explicit_state_root(self):
        with patch('dev_environment.cli.Path.cwd', return_value=self.root), \
                patch('dev_environment.cli.Host') as host, patch('sys.stdout', new=io.StringIO()):
            host.return_value.instructions.return_value = self.store.root / 'host'
            self.assertEqual(0, main(['--state-root', str(self.store.root), 'host-setup']))
        host.return_value.instructions.assert_called_once_with()

    def test_up_does_not_print_ready_when_down_wins_before_activation(self):
        certificate = self.store.root / 'gateway/tls/cert.pem'
        certificate.parent.mkdir(parents=True)
        certificate.touch()
        instance = {'id': 'persistent', 'kind': 'persistent', 'hostname': 'dev.baander.app',
                    'phase': 'stopped', 'readiness': None}
        self.store.save('persistent', instance)
        output = io.StringIO()
        with patch('dev_environment.cli.repository_context', return_value=(self.root, 'repository')), \
                patch('dev_environment.cli.configure', return_value=instance), \
                patch('dev_environment.cli.Supervisor'), patch('dev_environment.cli.Updater'), \
                patch('sys.stdout', new=output), patch('sys.stderr', new=io.StringIO()):
            self.assertEqual(1, main(['--state-root', str(self.store.root), 'up', '--instance', 'persistent']))
        self.assertNotIn('Ready:', output.getvalue())

    def test_media_cannot_expose_state_or_its_private_subdirectories(self):
        self.store.root.mkdir()
        private = self.store.root / 'gateway'
        private.mkdir()
        for media in (self.base, self.store.root, private):
            with patch.object(self.store, 'save') as save, patch('dev_environment.cli.docker_target') as docker:
                with self.assertRaisesRegex(WorkflowError, 'Media directories'):
                    configure(self.store, Runner(), self.root, 'repository',
                              parser().parse_args(['up', '--media', str(media)]))
            save.assert_not_called()
            docker.assert_not_called()

    def test_inherited_and_existing_media_are_validated(self):
        self.store.save('persistent', {'id': 'persistent', 'repository': 'repository',
                                     'media': [{'source': str(self.base), 'target': '/media'}]})
        for arguments in (['up'], ['up', '--instance', 'persistent']):
            with patch.object(self.store, 'save') as save:
                with self.assertRaisesRegex(WorkflowError, 'Media directories'):
                    configure(self.store, Runner(), self.root, 'repository', parser().parse_args(arguments))
            save.assert_not_called()


class ExecCommandTest(unittest.TestCase):
    def test_normal_command_keeps_quoted_values_and_metacharacters_literal(self):
        args = parser().parse_args(['exec', '--command-env', 'BAANDER_COMMAND'])
        with patch.dict(os.environ, {'BAANDER_COMMAND': "printf '%s' 'quoted value' ';' '$(touch marker)'"}):
            self.assertEqual(['printf', '%s', 'quoted value', ';', '$(touch marker)'], exec_command(args))

    def test_shell_payload_is_passed_whole_to_container_bash(self):
        args = parser().parse_args(['exec', '--shell-command-env', 'BAANDER_COMMAND'])
        payload = '''printf '%s' "quoted value"; printf '%s' "$(pwd)"'''
        with patch.dict(os.environ, {'BAANDER_COMMAND': payload}):
            self.assertEqual(['bash', '-c', payload], exec_command(args))

    def test_arguments_remain_supported_and_conflicting_or_empty_sources_fail(self):
        self.assertEqual(['php', '-v'], exec_command(parser().parse_args(['exec', '--', 'php', '-v'])))
        with patch.dict(os.environ, {'BAANDER_COMMAND': ''}):
            with self.assertRaises(WorkflowError):
                exec_command(parser().parse_args(['exec', '--command-env', 'BAANDER_COMMAND']))
        with patch.dict(os.environ, {'BAANDER_COMMAND': 'php -v'}):
            with self.assertRaises(WorkflowError):
                exec_command(parser().parse_args(['exec', '--command-env', 'BAANDER_COMMAND', '--', 'php']))

    def test_make_exports_command_without_executing_host_metacharacters(self):
        makefile = Path(__file__).resolve().parents[2] / 'Makefile'
        with tempfile.TemporaryDirectory() as directory:
            base = Path(directory)
            capture = base / 'capture.py'
            result = base / 'result.json'
            marker = base / 'host-marker'
            capture.write_text('import json, os, sys\nfrom pathlib import Path\n'
                               'Path(os.environ["CAPTURE_RESULT"]).write_text(json.dumps([sys.argv[1:], os.environ["cmd"]]))\n')
            payload = f"printf '%s' 'quoted value'; touch {marker}; $(shell touch {marker})"
            for target, expected in (('exec', ['--command-env', 'cmd']),
                                     ('exec-by-root', ['--user', 'root', '--command-env', 'cmd']),
                                     ('exec-bash', ['--shell-command-env', 'cmd'])):
                run = subprocess.run(['make', '--no-print-directory', '-f', str(makefile), target,
                                      f'DEV_ENVIRONMENT={sys.executable} {capture}', 'cmd=' + payload],
                                     cwd=base, env=os.environ | {'CAPTURE_RESULT': str(result)},
                                     capture_output=True, text=True)
                self.assertEqual(0, run.returncode, run.stderr)
                arguments, exported = json.loads(result.read_text())
                self.assertEqual(['exec', *expected], arguments)
                self.assertEqual(payload, exported)
                self.assertFalse(marker.exists())
            inside_payload = f"{sys.executable} {capture} 'quoted value' ';' touch {marker}"
            run = subprocess.run(['make', '--no-print-directory', '-f', str(makefile), 'exec',
                                  'INSIDE_DOCKER_CONTAINER=1', 'cmd=' + inside_payload], cwd=base,
                                 env=os.environ | {'CAPTURE_RESULT': str(result)}, capture_output=True, text=True)
            self.assertEqual(0, run.returncode, run.stderr)
            self.assertEqual(['quoted value', ';', 'touch', str(marker)], json.loads(result.read_text())[0])
            self.assertFalse(marker.exists())


if __name__ == '__main__':
    unittest.main()
