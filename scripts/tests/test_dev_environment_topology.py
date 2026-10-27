import json
from pathlib import Path
import shlex
import subprocess
import sys
import tempfile
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from dev_environment.model import StateStore, WorkflowError
from dev_environment.runtime import Runtime
from dev_environment.host import Gateway


class RecordingRunner:
    def __init__(self):
        self.calls = []
        self.fail_validation = False

    def run(self, arguments, **kwargs):
        self.calls.append((arguments, kwargs))
        if self.fail_validation and 'nginx' in arguments and '-t' in arguments:
            raise WorkflowError('invalid nginx configuration')
        output = '{}'
        if 'info' in arguments:
            output = json.dumps({'ID': 'daemon'})
        elif 'ps' in arguments:
            output = 'nginx-container'
        elif 'inspect' in arguments and 'network' not in arguments:
            output = json.dumps({'baander-persistent': {'IPAddress': '172.20.0.3'}})
        elif 'network' in arguments and 'inspect' in arguments:
            owner = 'gateway-' + arguments[-1].removeprefix('baander-development-gateway-') if arguments[-1].startswith('baander-development-gateway-') else 'persistent'
            output = json.dumps([{'Labels': {'app.baander.development.owner': owner}, 'IPAM': {'Config': [{'Subnet': '172.20.0.0/16'}]}}])
        return subprocess.CompletedProcess(arguments, 0, output, '')


class TopologyTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.store = StateStore(Path(self.temporary.name))
        self.runner = RecordingRunner()
        self.runtime = Runtime(self.store, self.runner)
        self.instance = {'id': 'persistent', 'kind': 'persistent', 'project': 'baander-persistent',
                         'hostname': 'baander.test', 'docker': {'executable': '/bin/docker',
                         'context': 'owned', 'daemon_id': 'daemon'}, 'media': [],
                         'network': 'baander-persistent', 'volumes': {}}
        self.release = {key + '_image': 'sha256:' + key for key in
                        ('app', 'worker', 'postgres', 'redis', 'nginx', 'tool')}
        self.release['revision'] = 'a' * 40
        directory = self.store._prepare('persistent')
        (directory / 'env.json').write_text(json.dumps({'APP_SECRET': 'secret', 'REDIS_PASSWORD': 'pass'}))

    def test_stopping_uses_recorded_context_and_never_removes_data(self):
        instance = self.runtime.write(self.instance, self.release)
        self.runtime.down(instance)
        command = self.runner.calls[-1][0]
        self.assertEqual(['/bin/docker', '--context', 'owned', 'compose'], command[:4])
        self.assertEqual(['down', '--remove-orphans'], command[-2:])
        self.assertNotIn('--volumes', command)

    def test_start_observes_frontend_address_before_starting_web(self):
        instance = self.runtime.write(self.instance, self.release)
        self.runtime.up(instance, self.release)
        model = json.loads(Path(instance['compose_file']).read_text())
        self.assertEqual('172.20.0.3', model['services']['app']['environment']['TRUSTED_PROXIES'])
        starts = [arguments for arguments, _ in self.runner.calls if 'up' in arguments]
        self.assertEqual(['nginx', 'app'], [arguments[-1] for arguments in starts])
        self.assertFalse(any('postgres' in arguments for arguments in starts))

    def test_preview_refuses_daemon_source_mismatch_before_start(self):
        source = Path(self.temporary.name) / 'checkout'
        (source / 'ui/web').mkdir(parents=True)
        (source / 'composer.lock').write_text('{}')
        (source / 'ui/web/package.json').write_text('{}')
        # The selected daemon sees different content from the host checkout.
        preview = dict(self.instance, kind='preview', worktree=str(source))
        with self.assertRaisesRegex(WorkflowError, 'Docker sees different preview source'):
            self.runtime.ensure_networks(preview, dict(self.release, source=str(source)))
        self.assertFalse(any('up' in arguments for arguments, _ in self.runner.calls))

    def test_preview_code_is_read_only_with_private_generated_state(self):
        preview = dict(self.instance, id='preview-0123456789abcdef', kind='preview',
                       project='baander-preview-0123456789abcdef', hostname='p0123456789abcdef.baander.test',
                       worktree=str(Path(self.temporary.name) / 'live-worktree'))
        self.store._prepare(preview['id']).joinpath('env.json').write_text('{}')
        Path(preview['worktree']).mkdir()
        release = dict(self.release, source=str(Path(self.temporary.name) / 'immutable-snapshot'))
        config = self.runtime.config(preview, release)
        mounts = config['services']['app']['volumes']
        source = next(mount for mount in mounts if mount['target'] == '/var/www/html')
        self.assertTrue(source['read_only'])
        self.assertEqual(preview['worktree'], source['source'])
        vite_source = next(mount for mount in config['services']['vite']['volumes']
                           if mount['target'] == '/var/www/html')
        self.assertEqual(preview['worktree'], vite_source['source'])
        self.runtime.prepare(preview, release)
        for relative in ('vendor', 'var', 'storage', 'config/secrets', 'ui/web/node_modules'):
            self.assertTrue((Path(preview['worktree']) / relative).is_dir(), relative)
        self.assertEqual([], list((Path(preview['worktree']) / 'ui/web/node_modules').iterdir()))
        install = next(arguments for arguments, _ in self.runner.calls if 'install' in arguments)
        self.assertIn(f'type=bind,src={preview["worktree"]},dst=/var/www/html,readonly', install)
        self.assertFalse(any(service.get('ports') for service in config['services'].values()))
        self.assertNotIn('gateway', config['services']['app']['networks'])
        self.assertIn('gateway', config['services']['nginx']['networks'])

    def test_preview_mountpoints_refuse_symlinked_checkout_directories(self):
        source = Path(self.temporary.name) / 'checkout'
        outside = Path(self.temporary.name) / 'unrelated'
        source.mkdir()
        outside.mkdir()
        (source / 'ui').symlink_to(outside, target_is_directory=True)
        preview = dict(self.instance, kind='preview', worktree=str(source))
        with self.assertRaisesRegex(WorkflowError, 'symlink'):
            self.runtime.prepare(preview, self.release)
        self.assertEqual([], list(outside.iterdir()))
        self.assertFalse(any('run' in arguments for arguments, _ in self.runner.calls))

    def test_preview_mountpoints_cannot_escape_checkout_through_parent_segments(self):
        source = Path(self.temporary.name) / 'checkout'
        source.mkdir()
        environment = self.store.directory(self.instance['id']) / 'env.json'
        environment.write_text(json.dumps({'CONVERT_STORAGE_PATH': '/var/www/html/../outside-preview'}))
        preview = dict(self.instance, kind='preview', worktree=str(source))
        with self.assertRaisesRegex(WorkflowError, 'parent'):
            self.runtime.prepare(preview, self.release)
        self.assertFalse((source.parent / 'outside-preview').exists())
        self.assertFalse(any('run' in arguments for arguments, _ in self.runner.calls))

    def test_generated_volume_refresh_removes_old_files_and_preserves_identity(self):
        self.runtime.prepare(self.instance, self.release)
        helpers = [arguments for arguments, _ in self.runner.calls if '--mount' in arguments]
        public = next(arguments for arguments in helpers if 'type=volume,src=baander-persistent-public,dst=/seed' in arguments)
        self.assertEqual('0:0', public[public.index('--user') + 1])
        self.assertIn('find /seed -mindepth 1 -maxdepth 1', public[-1])
        self.assertIn('chown -R www-data:www-data /seed', public[-1])
        secrets = next(arguments for arguments in helpers if 'type=volume,src=baander-persistent-secrets,dst=/seed' in arguments)
        self.assertNotIn('rm -rf', secrets[-1])
        self.assertIn('ls -A /seed', secrets[-1])
        seed = Path(self.temporary.name) / 'seed'
        built = Path(self.temporary.name) / 'built-public'
        seed.mkdir()
        built.mkdir()
        (seed / 'obsolete.js').write_text('old')
        (built / 'current.js').write_text('new')
        script = public[-1].replace('/var/www/html/public', shlex.quote(str(built)))
        script = script.replace('/seed', shlex.quote(str(seed)))
        subprocess.run(['/bin/sh', '-ec', 'chown() { :; }; ' + script], check=True)
        self.assertEqual(['current.js'], sorted(path.name for path in seed.iterdir()))
        identity = seed / 'identity.key'
        identity.write_text('retain-me')
        script = secrets[-1].replace('/var/www/html/config/secrets', shlex.quote(str(built)))
        script = script.replace('/seed', shlex.quote(str(seed)))
        subprocess.run(['/bin/sh', '-ec', 'chown() { :; }; ' + script], check=True)
        self.assertEqual('retain-me', identity.read_text())

    def test_removing_preview_route_keeps_persistent_route(self):
        gateway = Gateway(self.store, self.runner)
        gateway.configure(self.instance, self.release)
        gateway.route(self.instance)
        preview = dict(self.instance, id='preview-0123456789abcdef',
                       hostname='feature.baander.test', project='baander-feature')
        gateway.route(preview)
        gateway.remove(preview)
        self.assertIn('server_name baander.test;', gateway.configuration.read_text())
        self.assertNotIn('server_name feature.baander.test;', gateway.configuration.read_text())

    def test_hostname_ownership_prevents_another_instance_replacing_route(self):
        gateway = Gateway(self.store, self.runner)
        gateway.configure(self.instance, self.release)
        gateway.route(self.instance)
        with self.assertRaisesRegex(WorkflowError, 'owned by another'):
            gateway.route(dict(self.instance, id='preview-0123456789abcdef'))

    def test_failed_route_validation_preserves_other_routes(self):
        gateway = Gateway(self.store, self.runner)
        gateway.configure(self.instance, self.release)
        gateway.route(self.instance)
        old = gateway.configuration.read_text()
        self.runner.fail_validation = True
        with self.assertRaises(WorkflowError):
            gateway.route(dict(self.instance, hostname='other.baander.test'), maintenance=True)
        self.assertEqual(old, gateway.configuration.read_text())


if __name__ == '__main__':
    unittest.main()
