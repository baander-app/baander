from importlib.util import module_from_spec, spec_from_file_location
from pathlib import Path
import json
import subprocess
import sys
import tarfile
import tempfile
import unittest
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from dev_environment.model import WorkflowError
from dev_environment.process import Docker, Runner

spec = spec_from_file_location('backend_checks', Path(__file__).resolve().parents[1] / 'check-backend-container.py')
checks = module_from_spec(spec)
spec.loader.exec_module(checks)


class CheckoutTest(unittest.TestCase):
    def test_archive_has_current_code_configuration_and_dependencies_without_host_secrets(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory) / 'repo'
            root.mkdir()
            def git(*arguments):
                subprocess.run(['git', '-C', str(root), *arguments], check=True, capture_output=True)
            git('init', '-b', 'master')
            git('config', 'user.email', 'dev@baander.app')
            git('config', 'user.name', 'Development fixture')
            for name in ('src/Marker.php', 'config/services.yaml', '.env'):
                path = root / name
                path.parent.mkdir(parents=True, exist_ok=True)
                path.write_text('baked source')
            git('add', '.')
            git('commit', '-m', 'fixture')
            for name in ('src/Marker.php', 'config/services.yaml'):
                (root / name).write_text('current checkout')
            for name in ('vendor/autoload.php', 'config/secrets/oauth/private.key',
                         'config/secrets/test/test.decrypt.private.php', '.env.local'):
                path = root / name
                path.parent.mkdir(parents=True, exist_ok=True)
                path.write_text('host private' if name != 'vendor/autoload.php' else 'current dependencies')
            (root / '.env').write_text('host private')
            (root / 'composer.lock').write_text(json.dumps({'packages': [], 'packages-dev': []}))
            (root / 'vendor/composer').mkdir()
            (root / 'vendor/composer/installed.json').write_text(json.dumps({'packages': []}))
            target = Path(directory) / 'check'
            target.mkdir()
            _, _, archive = checks.prepare_checkout(Runner(), root, target)
            with tarfile.open(archive) as contents:
                names = {name.removeprefix('./') for name in contents.getnames()}
                self.assertEqual(b'current checkout', contents.extractfile('./src/Marker.php').read())
                self.assertEqual(b'current checkout', contents.extractfile('./config/services.yaml').read())
                self.assertEqual(b'current dependencies', contents.extractfile('vendor/autoload.php').read())
                self.assertEqual(b'baked source', contents.extractfile('./.env').read())
                self.assertFalse(any(name.startswith('config/secrets/') for name in names))
                self.assertNotIn('.env.local', names)

    def test_dependency_versions_and_references_must_match_lock(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / 'vendor/composer').mkdir(parents=True)
            package = {'name': 'baander/fixture', 'version': '1.0', 'dist': {'reference': 'current'}}
            (root / 'composer.lock').write_text(json.dumps({'packages': [package], 'packages-dev': []}))
            metadata = root / 'vendor/composer/installed.json'
            metadata.write_text(json.dumps({'packages': [package]}))
            checks.validate_dependencies(root)
            for change in ({'version': '0.9'}, {'dist': {'reference': 'stale'}}):
                metadata.write_text(json.dumps({'packages': [package | change]}))
                with self.assertRaisesRegex(WorkflowError, 'composer install'):
                    checks.validate_dependencies(root)
            metadata.unlink()
            with self.assertRaises(WorkflowError):
                checks.validate_dependencies(root)

    def test_dependencies_are_required_before_running_docker(self):
        with tempfile.TemporaryDirectory() as directory:
            with self.assertRaisesRegex(WorkflowError, 'Install Composer'):
                checks.prepare_checkout(Runner(), Path(directory), Path(directory))


class ContainerTest(unittest.TestCase):
    def run_check(self, result=7, provision_error=False):
        self.calls = []
        class FakeDocker:
            def run(inner, *arguments, **kwargs):
                self.calls.append((arguments, kwargs))
                if provision_error and arguments[:1] == ('cp',):
                    raise WorkflowError('copy failed')
                return subprocess.CompletedProcess(arguments, result if kwargs.get('interactive') else 0)
        return checks.run_check(FakeDocker(), 'cached-runtime', Path('/tmp/current.tar'), ['php', 'current.php'])

    def test_cached_image_is_replaced_before_current_command_and_cleanup_preserves_exit_status(self):
        self.assertEqual(7, self.run_check())
        create = self.calls[0][0]
        self.assertIn('cached-runtime', create)
        self.assertEqual('none', create[create.index('--network') + 1])
        self.assertIn('APP_ENV=test', create)
        provision = self.calls[3][0][-1]
        self.assertIn('rm -rf /var/www/html', provision)
        self.assertIn('tar -xf /tmp/checkout.tar', provision)
        self.assertIn('openssl genrsa', provision)
        self.assertIn('openssl rsa', provision)
        execute, options = self.calls[4]
        self.assertEqual(('exec', '--user', 'www-data'), execute[:3])
        self.assertEqual(('php', 'current.php'), execute[-2:])
        self.assertFalse(options['check'])
        self.assertEqual(('rm', '--force'), self.calls[-1][0][:2])

    def test_container_is_removed_when_provisioning_fails(self):
        with self.assertRaises(WorkflowError):
            self.run_check(provision_error=True)
        self.assertEqual(('rm', '--force'), self.calls[-1][0][:2])

    def test_docker_context_and_clean_environment_survive_host_overrides(self):
        with patch.dict('os.environ', {'DOCKER_HOST': 'tcp://baander.app:2375', 'APP_ENV': 'prod'}):
            with patch('subprocess.run', return_value=subprocess.CompletedProcess([], 0)) as run:
                Docker(Runner(), {'executable': '/usr/bin/docker', 'context': 'isolated'}).run('info')
        self.assertEqual(['/usr/bin/docker', '--context', 'isolated', 'info'], run.call_args.args[0])
        self.assertNotIn('DOCKER_HOST', run.call_args.kwargs['env'])
        self.assertNotIn('APP_ENV', run.call_args.kwargs['env'])


if __name__ == '__main__':
    unittest.main()
