from pathlib import Path
import json
import os
import subprocess
import shlex
import uuid
import sys
import tempfile
import unittest
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from dev_environment.model import StateStore, WorkflowError, atomic_json
from dev_environment.process import Runner
from dev_environment.workers import Workers


class WorkerLifecycleTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.store = StateStore(Path(self.temp.name))
        self.instance = {'id': 'persistent', 'phase': 'activating', 'workers': {'directory': '/saved', 'operator_image': 'sha256:saved'}}
        self.store.save('persistent', self.instance)
        self.workers = Workers(self.store, None)

    def test_uncertain_create_is_not_retried_or_started(self):
        with patch.object(self.workers, 'invoke', side_effect=WorkflowError('unconfirmed')) as invoke:
            with self.assertRaises(WorkflowError):
                self.workers.start(self.instance)
        self.assertEqual(['create'], [call.args[1] for call in invoke.call_args_list])
        self.assertEqual('create_unconfirmed', self.store.load('persistent')['workers']['phase'])
        with patch.object(self.workers, 'invoke') as invoke:
            with self.assertRaises(WorkflowError):
                self.workers.start(self.store.load('persistent'))
            invoke.assert_not_called()

    def test_failed_retirement_preserves_saved_controller(self):
        with patch.object(self.workers, 'invoke', side_effect=WorkflowError('unconfirmed')):
            with self.assertRaises(WorkflowError):
                self.workers.retire(self.instance)
        saved = self.store.load('persistent')['workers']
        self.assertEqual('sha256:saved', saved['operator_image'])
        self.assertEqual('retirement_unconfirmed', saved['phase'])

    def test_success_does_not_claim_readiness_before_observation(self):
        with patch.object(self.workers, 'invoke', return_value={'state': 'created'}) as invoke:
            self.workers.start(self.instance)
        self.assertEqual(['create', 'start'], [call.args[1] for call in invoke.call_args_list])
        self.assertEqual('started', self.store.load('persistent')['workers']['phase'])
        self.assertNotIn('readiness', self.store.load('persistent'))


class OperatorTransferTest(unittest.TestCase):
    def test_private_documents_travel_only_in_bounded_stdin(self):
        with tempfile.TemporaryDirectory() as temporary:
            directory = Path(temporary)
            atomic_json(directory / 'manifest.json', {'mediaMounts': []})
            atomic_json(directory / 'credentials.json', {'private': 'do-not-print'})
            instance = {'project': 'worker-transfer', 'docker': {'executable': 'docker', 'context': 'owned', 'daemon_id': 'daemon'},
                        'workers': {'directory': temporary, 'socket': '/var/run/docker.sock', 'operator_image': 'operator'}}
            runner = unittest.mock.Mock()
            runner.run.side_effect = [subprocess.CompletedProcess([], 0, '{"ID":"daemon"}', ''),
                subprocess.CompletedProcess([], 0, '{"success":true,"action":"status","result":{"retirement":"unregistered"}}', '')]
            result = Workers(None, runner).invoke(instance, 'status')
            arguments = runner.run.call_args.args[0]
            options = runner.run.call_args.kwargs
            self.assertEqual('unregistered', result['retirement'])
            self.assertIn('-i', arguments)
            self.assertIn('ALL', arguments)
            self.assertFalse(any('/input' in argument or 'do-not-print' in argument for argument in arguments))
            self.assertEqual({'private': 'do-not-print'}, json.loads(json.loads(options['input'])['credentials']))
            self.assertIn("fopen('/operator/'", shlex.split(arguments[-3])[2])
            self.assertEqual(0o700, directory.stat().st_mode & 0o777)
            self.assertEqual(0o600, (directory / 'credentials.json').stat().st_mode & 0o777)

    def test_adopted_readonly_source_bind_is_preserved_in_worker_manifest(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            library = root / 'library'
            library.mkdir()
            store = StateStore(root / 'state')
            atomic_json(store._prepare('persistent') / 'env.json', {'APP_SECRET': 'test', 'DATABASE_URL': 'test'})
            instance = {'id': 'persistent', 'project': 'adopt-source', 'kind': 'persistent',
                        'hostname': 'adopt.baander.app', 'docker': {'daemon_id': 'daemon'},
                        'state_mounts': {'media': {'type': 'bind', 'source': str(library),
                                                 'target': '/storage/media', 'read_only': True}}}
            release = {key + '_image': 'image' for key in ('app', 'worker', 'operator', 'postgres', 'redis', 'nginx')}
            with patch('dev_environment.workers.Docker') as factory:
                docker = factory.return_value
                docker.validate.return_value = {'NCPU': 2, 'MemTotal': 16 * 1024 ** 3}
                def run(*args, **kwargs):
                    if args[:2] == ('context', 'inspect'):
                        value = json.dumps([{'Endpoints': {'docker': {'Host': 'unix:///var/run/docker.sock'}}}])
                    else:
                        value = '{}'
                    return subprocess.CompletedProcess(args, 0, value, '')
                instance['docker']['context'] = 'test'
                docker.run.side_effect = run
                Workers(store, None).prepare(instance, release)
            manifest = json.loads((Path(instance['workers']['directory']) / 'manifest.json').read_text())
            source = next(mount for mount in manifest['mediaMounts'] if mount['target'] == '/storage/media')
            self.assertEqual({'source': str(library), 'target': '/storage/media', 'role': 'source', 'readOnly': True}, source)

    def test_oversize_credentials_are_refused_before_operator_launch(self):
        with tempfile.TemporaryDirectory() as temporary:
            directory = Path(temporary)
            atomic_json(directory / 'manifest.json', {'mediaMounts': []})
            (directory / 'credentials.json').write_text('x' * 8193)
            instance = {'project': 'worker-transfer', 'docker': {'executable': 'docker', 'context': 'owned', 'daemon_id': 'daemon'},
                        'workers': {'directory': temporary, 'socket': '/var/run/docker.sock', 'operator_image': 'operator'}}
            runner = unittest.mock.Mock()
            runner.run.return_value = subprocess.CompletedProcess([], 0, '{"ID":"daemon"}', '')
            with self.assertRaisesRegex(WorkflowError, 'bounded file size'):
                Workers(None, runner).invoke(instance, 'status')
            self.assertEqual(1, runner.run.call_count)


@unittest.skipUnless(os.environ.get('BAANDER_TEST_OPERATOR_IMAGE'), 'requires a qualified operator image and disposable Docker resources')
class GeneratedManifestParserTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='baander-worker-parser-')
        self.addCleanup(self.temporary.cleanup)
        self.store = StateStore(Path(self.temporary.name))
        self.runner = Runner()
        self.context = os.environ.get('BAANDER_TEST_DOCKER_CONTEXT', 'default')
        self.docker = ['docker', '--context', self.context]
        self.daemon = json.loads(self.runner.run(self.docker + ['info', '--format', '{{json .}}']).stdout)
        self.project = 'baander-worker-parser-' + uuid.uuid4().hex[:12]
        self.image = self.runner.run(self.docker + ['image', 'inspect', '--format', '{{.Id}}', os.environ['BAANDER_TEST_OPERATOR_IMAGE']]).stdout.strip()
        self.instance = {'id': 'persistent', 'kind': 'persistent', 'project': self.project,
            'hostname': 'workers.baander.app', 'network': os.environ.get('BAANDER_TEST_WORKER_NETWORK', self.project),
            'docker': {'executable': 'docker', 'context': self.context, 'daemon_id': self.daemon['ID']}}
        self.release = {key + '_image': self.image for key in ('app', 'worker', 'operator', 'postgres', 'redis', 'nginx', 'tool')}
        environment = {'APP_SECRET': 'worker-parser-test', 'DATABASE_URL': os.environ.get('BAANDER_TEST_WORKER_DATABASE_URL', 'postgresql://baander:test-only@database:5432/worker_operator_test?serverVersion=18&charset=utf8'),
            'REDIS_URL': 'redis://default:test-only@redis:6379', 'MESSENGER_TRANSPORT_DSN': 'redis://default:test-only@redis:6379/messages',
            'MEDIA_STORAGE_PATH': '/storage/media', 'CONVERT_STORAGE_PATH': '/storage/conversions',
            'VAPID_PRIVATE_KEY': 'instance-private-key', 'VAPID_PUBLIC_KEY': 'instance-public-key', 'STREAM_URL_HMAC_SECRET': 'instance-stream-secret'}
        atomic_json(self.store._prepare('persistent') / 'env.json', environment)
        self.workers = Workers(self.store, self.runner)
        self.addCleanup(self.cleanup_volumes)

    def cleanup_volumes(self):
        result = self.runner.run(self.docker + ['volume', 'ls', '--format', '{{.Name}}'], check=False)
        for name in result.stdout.splitlines():
            if name.startswith(self.project + '-'):
                self.runner.run(self.docker + ['volume', 'rm', name], check=False)

    def assert_generated_topology(self):
        self.workers.prepare(self.instance, self.release)
        directory = Path(self.instance['workers']['directory'])
        manifest = json.loads((directory / 'manifest.json').read_text())
        mounts = {item['target']: item for item in manifest['mediaMounts']}
        self.assertEqual('source', mounts['/storage/media']['role'])
        self.assertTrue(mounts['/storage/media']['readOnly'])
        for target in ('/storage/conversions', '/var/www/html/storage', '/var/www/html/var/transcode_state'):
            self.assertEqual('derived', mounts[target]['role'])
            self.assertFalse(mounts[target]['readOnly'])
        environment = json.loads((directory / 'credentials.json').read_text())['runtimeEnvironment']
        self.assertEqual('instance-private-key', environment['VAPID_PRIVATE_KEY'])
        self.assertEqual('instance-public-key', environment['VAPID_PUBLIC_KEY'])
        self.assertEqual('instance-stream-secret', environment['STREAM_URL_HMAC_SECRET'])
        self.assertEqual('https://workers.baander.app', environment['APP_URL'])
        self.assertEqual(environment['APP_URL'], environment['DEFAULT_URI'])
        return mounts

    def test_default_empty_source_passes_both_production_recipes(self):
        self.assert_generated_topology()

    def test_explicit_source_bind_passes_both_production_recipes(self):
        source = Path(self.temporary.name) / 'library'
        source.mkdir()
        self.instance['media'] = [{'source': str(source), 'target': '/media/library'}]
        mounts = self.assert_generated_topology()
        self.assertEqual(str(source), mounts['/media/library']['source'])
        self.assertTrue(mounts['/media/library']['readOnly'])

    def test_explicit_source_can_replace_default_source_root(self):
        source = Path(self.temporary.name) / 'library'
        source.mkdir()
        self.instance['media'] = [{'source': str(source), 'target': '/storage/media'}]
        mounts = self.assert_generated_topology()
        self.assertEqual(str(source), mounts['/storage/media']['source'])

    def test_overlapping_source_fails_before_saving_worker_pointer(self):
        source = Path(self.temporary.name) / 'library'
        source.mkdir()
        self.instance['media'] = [{'source': str(source), 'target': '/storage/media/nested'}]
        with self.assertRaisesRegex(WorkflowError, 'overlapping media paths'):
            self.workers.prepare(self.instance, self.release)
        self.assertNotIn('workers', self.instance)

    def test_adopted_derived_bind_is_refused_before_saving_worker_pointer(self):
        self.instance['state_mounts'] = {'storage': {'type': 'bind', 'source': self.temporary.name, 'target': '/var/www/html/storage'}}
        with self.assertRaisesRegex(WorkflowError, 'requires a named volume'):
            self.workers.prepare(self.instance, self.release)
        self.assertNotIn('workers', self.instance)

    @unittest.skipUnless(os.environ.get('BAANDER_TEST_WORKER_DATABASE_URL'), 'requires disposable migrated PostgreSQL')
    def test_actual_invoke_reads_host_private_documents_without_dac_capabilities(self):
        self.assert_generated_topology()
        directory = Path(self.instance['workers']['directory'])
        self.assertEqual(os.getuid(), directory.stat().st_uid)
        self.assertNotEqual(0, os.getuid(), 'exercise the nonroot host to root operator boundary')
        self.assertEqual(0o700, directory.stat().st_mode & 0o777)
        self.assertEqual(0o600, (directory / 'credentials.json').stat().st_mode & 0o777)
        status = self.workers.invoke(self.instance, 'status')
        self.assertEqual('unregistered', status['retirement'])
        self.assertEqual('none', status['reservation'])


if __name__ == '__main__':
    unittest.main()
