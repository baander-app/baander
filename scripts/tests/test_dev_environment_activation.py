"""Activation failure boundaries and unattended controller lifetime contracts."""
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import Mock, patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from dev_environment.bootstrap import Bootstrap
from dev_environment.model import StateStore, WorkflowError
from dev_environment.supervisor import Supervisor
from dev_environment.updater import Updater


class BoundaryRunner:
    """Record Docker boundaries without contacting a daemon."""

    def __init__(self):
        self.calls = []
        self.migrate = None
        self.inventory = {}
        self.refuse_stop = False

    def run(self, args, **kwargs):
        self.calls.append(args)
        output = ''
        if 'info' in args:
            output = json.dumps({'ID': 'daemon'})
        elif 'inspect' in args and '--format' in args:
            output = '0'
        elif 'inspect' in args:
            output = json.dumps([self.inventory[args[-1]]])
        elif 'stop' in args and args[-1] in self.inventory:
            if not self.refuse_stop:
                self.inventory[args[-1]]['State']['Running'] = False
        elif 'doctrine:migrations:migrate' in args and self.migrate:
            self.migrate()
        return subprocess.CompletedProcess(args, 0, output, '')


class ActivationSafetyTest(unittest.TestCase):
    def setUp(self):
        temporary = tempfile.TemporaryDirectory()
        self.addCleanup(temporary.cleanup)
        self.store = StateStore(Path(temporary.name) / 'state')
        self.old = {'revision': 'old', 'postgres_image': 'recorded-postgres'}
        self.new = {'revision': 'new', 'postgres_image': 'candidate-postgres', 'app_image': 'app'}
        self.instance = {
            'id': 'persistent', 'kind': 'persistent', 'repository': '/disposable/remote',
            'project': 'activation-test', 'compose_file': 'compose.json',
            'docker': {'executable': 'docker', 'context': 'test', 'daemon_id': 'daemon'},
            'phase': 'ready', 'running_revision': 'old', 'release': self.old,
            'readiness': {'workers_ready': True}, 'initialization': 'complete',
        }
        self.store.save('persistent', self.instance)
        self.store.set_enabled('persistent', True)
        self.runner = BoundaryRunner()
        self.updater = Updater(self.store, self.runner)
        self.updater.head = Mock(return_value='new')
        self.updater.builder.prepare = Mock(return_value=self.new)
        self.updater.runtime = Mock()
        self.updater.runtime.write.return_value = {}
        self.updater.runtime.ready.return_value = {'http_ready': True}
        self.updater.workers = Mock()
        self.updater.runtime.running.return_value = True
        self.updater.workers.running.return_value = True
        self.updater.gateway = Mock()
        self.updater.database.inspect = Mock(return_value={'fresh': False, 'pending': ['next']})
        self.updater.database.checkpoint = Mock(return_value={'target_revision': 'new', 'verified': False})
        self.updater.database.rehearse = Mock(return_value={'target_revision': 'new', 'verified': True})

    def test_host_restart_restores_dependencies_before_inspection_and_retires_before_fresh_boot(self):
        self.updater.database.inspect.return_value = {'fresh': False, 'pending': []}
        self.updater.runtime.running.return_value = False
        events = Mock()
        for name, operation in (('dependencies', self.updater.runtime.dependencies),
                ('inspect', self.updater.database.inspect), ('retire', self.updater.workers.retire),
                ('prepare', self.updater.workers.prepare), ('start', self.updater.workers.start)):
            events.attach_mock(operation, name)
        self.updater.update('persistent')
        names = [call[0] for call in events.mock_calls]
        self.assertLess(names.index('dependencies'), names.index('inspect'))
        self.assertLess(names.index('retire'), names.index('prepare'))
        self.assertLess(names.index('prepare'), names.index('start'))
        self.assertEqual('ready', self.store.load('persistent')['phase'])

    def test_incompatible_candidate_preserves_previous_runtime_and_revision(self):
        self.updater.database.inspect.side_effect = WorkflowError('incompatible history')
        with self.assertRaisesRegex(WorkflowError, 'incompatible'):
            self.updater.update('persistent')
        saved = self.store.load('persistent')
        self.assertEqual(('ready', 'old', self.old),
                         (saved['phase'], saved['running_revision'], saved['release']))
        self.assertEqual('new', saved['failed_revision'])
        self.assertEqual([], self.runner.calls)
        self.updater.runtime.up.assert_not_called()
        self.updater.workers.retire.assert_not_called()
        self.updater.gateway.route.assert_not_called()

    def test_ordinary_update_retains_recorded_data_service_images(self):
        instance = dict(self.instance, redis_image='original-redis', postgres_image='original-postgres')
        self.store.save('persistent', instance)
        self.updater.database.inspect.side_effect = WorkflowError('stop after read-only preflight')
        with self.assertRaises(WorkflowError):
            self.updater.update('persistent')
        release = self.store.load('persistent')['prepared_release']
        self.assertEqual('original-redis', release['redis_image'])
        self.assertEqual('original-postgres', release['postgres_image'])

    def test_stopped_instance_preflight_failure_does_not_publish_readiness(self):
        self.store.save('persistent', dict(self.instance, phase='stopped', running_revision=None, readiness=None))
        self.updater.database.inspect.side_effect = WorkflowError('incompatible history')
        with self.assertRaisesRegex(WorkflowError, 'incompatible'):
            self.updater.update('persistent')
        saved = self.store.load('persistent')
        self.assertEqual('stopped', saved['phase'])
        self.assertIsNone(saved['running_revision'])
        self.assertIsNone(saved['readiness'])
        self.updater.runtime.up.assert_not_called()

    def test_recovery_recreates_removed_dependencies_before_catalog_inspection(self):
        self.store.save('persistent', dict(self.instance, phase='blocked', running_revision=None,
                                         readiness=None, prepared_release=self.new, database_mutation_started=True))
        def inspect(instance, release):
            self.updater.runtime.dependencies.assert_called_once()
            self.updater.runtime.up.assert_not_called()
            return {'fresh': False, 'pending': [], 'migration_hashes': {'Version20261026120000.php': 'committed-hash'}}
        self.updater.database.inspect.side_effect = inspect
        self.updater.recover('persistent')
        self.assertEqual('ready', self.store.load('persistent')['phase'])
        self.assertEqual('new', self.store.load('persistent')['running_revision'])
        self.assertEqual({'Version20261026120000.php': 'committed-hash'},
                         self.store.load('persistent')['migration_hashes'])

    def test_recovery_never_replays_uncertain_pending_migrations(self):
        self.store.save('persistent', dict(self.instance, phase='blocked', running_revision=None,
                                         readiness=None, prepared_release=self.new, database_mutation_started=True))
        self.updater.database.inspect.return_value = {'fresh': False, 'pending': ['next']}
        with self.assertRaisesRegex(WorkflowError, 'reconciliation'):
            self.updater.recover('persistent')
        self.updater.runtime.up.assert_not_called()
        self.updater.workers.start.assert_not_called()
        self.assertEqual('blocked', self.store.load('persistent')['phase'])

    def test_recovery_of_untransferred_adoption_uses_original_ownership(self):
        inventory = {role: {'Id': role, 'Image': role + '-image', 'Mounts': [],
                            'State': {'Running': False}} for role in ('postgres', 'redis', 'app')}
        self.runner.inventory = inventory
        adopted = dict(self.instance, phase='blocked', prepared_release=self.new, release=None,
                       adoption={'containers': {role: role for role in inventory}, 'worker_manifests': []},
                       adoption_inventory=inventory, database_mutation_started=False)
        del adopted['compose_file']
        self.store.save('persistent', adopted)
        self.updater.activate = Mock()
        self.updater.recover('persistent')
        self.updater.activate.assert_called_once()
        self.updater.runtime.write.assert_not_called()
        self.updater.runtime.dependencies.assert_not_called()
        starts = [args[-1] for args in self.runner.calls if 'start' in args]
        self.assertEqual(['postgres', 'redis'], starts)

    def test_checkpoint_or_rehearsal_failure_resumes_old_without_live_migration(self):
        for boundary in ('checkpoint', 'rehearse'):
            with self.subTest(boundary=boundary):
                self.store.save('persistent', self.instance)
                self.updater.runtime.reset_mock()
                self.runner.calls.clear()
                self.updater.database.checkpoint.side_effect = None
                self.updater.database.rehearse.side_effect = None
                getattr(self.updater.database, boundary).side_effect = WorkflowError('checkpoint unavailable')
                with self.assertRaisesRegex(WorkflowError, 'checkpoint unavailable'):
                    self.updater.update('persistent')
                saved = self.store.load('persistent')
                self.assertEqual(('ready', 'old', self.old),
                                 (saved['phase'], saved['running_revision'], saved['release']))
                self.assertEqual('new', saved['failed_revision'])
                self.updater.runtime.up.assert_called_once()
                self.assertEqual(self.old, self.updater.runtime.up.call_args.args[1])
                self.assertFalse(any('doctrine:migrations:migrate' in args for args in self.runner.calls))

    def test_live_migration_lost_confirmation_blocks_and_never_restarts_old_code(self):
        self.updater.database.sql = Mock(return_value={'writers': 0})

        def fail_after_commit():
            self.assertEqual('database_mutating', self.store.load('persistent')['phase'])
            raise WorkflowError('earlier migration committed; later migration failed')

        self.runner.migrate = fail_after_commit
        with self.assertRaisesRegex(WorkflowError, 'later migration failed'):
            self.updater.update('persistent')
        saved = self.store.load('persistent')
        self.assertEqual('blocked', saved['phase'])
        self.assertIsNone(saved['running_revision'])
        self.assertEqual(self.old, saved['release'])
        self.assertTrue(saved['checkpoint']['verified'])
        self.updater.runtime.up.assert_not_called()
        self.updater.workers.start.assert_not_called()
        self.assertFalse(any(call.kwargs.get('maintenance') is False
                             for call in self.updater.gateway.route.call_args_list))

    def test_adoption_refuses_replacement_postgres_until_original_stop_is_confirmed(self):
        for refuses_stop in (True, False):
            with self.subTest(refuses_stop=refuses_stop):
                inventory = {role: {'Id': role, 'Image': role + '-image', 'Mounts': [],
                                    'State': {'Running': role in ('postgres', 'redis')}}
                             for role in ('app', 'postgres', 'redis')}
                adopted = dict(self.instance, release=None, readiness=None, initialization='adopted',
                               adoption={'containers': {'postgres': 'postgres', 'app': 'app'},
                                         'worker_manifests': []},
                               adoption_inventory=inventory, state_mounts={}, volumes={'var': 'var-volume'})
                self.store.save('persistent', adopted)
                self.runner.inventory = inventory
                self.runner.refuse_stop = refuses_stop
                self.runner.calls.clear()
                self.updater.runtime.reset_mock()
                self.updater.bootstrap = Bootstrap(self.store, self.runner)
                self.updater.bootstrap.initialize = Mock(side_effect=lambda instance, release: instance)
                self.updater.database.sql = Mock(return_value={'remaining': 0})
                self.updater.database.migrate = Mock(side_effect=lambda instance, release, checkpoint: instance)
                self.updater.database.rehearse.return_value = {
                    'target_revision': 'new', 'verified': True, 'directory': '/checkpoint/verified-attempt'}

                def dependencies(instance):
                    self.assertFalse(self.runner.inventory['postgres']['State']['Running'])
                    self.assertTrue(self.store.load('persistent')['ownership_transferred'])

                self.updater.runtime.dependencies.side_effect = dependencies
                if refuses_stop:
                    with self.assertRaisesRegex(WorkflowError, 'did not stop'):
                        self.updater.update('persistent')
                    self.updater.runtime.dependencies.assert_not_called()
                    self.updater.runtime.up.assert_not_called()
                    self.assertEqual('blocked', self.store.load('persistent')['phase'])
                else:
                    self.updater.update('persistent')
                    self.assertGreaterEqual(self.updater.runtime.dependencies.call_count, 1)
                    self.assertEqual('new', self.store.load('persistent')['running_revision'])

    def test_down_retains_unresolved_attempt_and_future_up_remains_blocked(self):
        evidence = {'directory': '/checkpoint/only-recovery-source', 'verified': True}
        for phase in ('blocked', 'database_mutating', 'checkpointing', 'quiescing', 'activating'):
            with self.subTest(phase=phase):
                self.store.save('persistent', dict(self.instance, phase=phase, checkpoint=evidence,
                                                 prepared_release=self.new, target_revision='new',
                                                 failure='activation confirmation lost', running_revision=None))
                self.updater.down('persistent')
                saved = self.store.load('persistent')
                self.assertFalse(self.store.enabled('persistent'))
                self.assertEqual('blocked', saved['phase'])
                self.assertEqual(evidence, saved['checkpoint'])
                self.assertEqual(self.new, saved['prepared_release'])
                self.assertEqual('new', saved['target_revision'])
                self.store.set_enabled('persistent', True)
                with self.assertRaisesRegex(WorkflowError, 'explicit recovery'):
                    self.updater.update('persistent')
                self.updater.builder.prepare.assert_not_called()
                self.updater.runtime.up.assert_not_called()


class SupervisorLifetimeTest(unittest.TestCase):
    def test_generated_service_preserves_absolute_launch_path_without_copying_secrets(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            store = StateStore(root / 'state')
            supervisor = Supervisor(store, Mock())
            launch_path = '/home/operator/.rd/bin:/opt/a "quote" %name $literal:/opt/back\\slash:/usr/bin:relative::/usr/bin'
            with patch.dict(os.environ, {'XDG_CONFIG_HOME': str(root / 'config'),
                    'PATH': launch_path, 'DOCKER_PASSWORD': 'must-not-appear'}):
                supervisor.install()
            unit = (root / 'config/systemd/user' / (supervisor.unit + '.service')).read_text()
            self.assertIn('Environment="PATH=/home/operator/.rd/bin:/opt/a \\"quote\\" %%name $literal:/opt/back\\\\slash:/usr/bin"', unit)
            self.assertNotIn('relative', unit)
            self.assertNotIn('must-not-appear', unit)
            self.assertNotIn('DOCKER_PASSWORD', unit)

    def test_path_without_absolute_entries_or_with_control_characters_is_rejected(self):
        for launch_path in ('relative::another', '/usr/bin:/opt/bad\nInjected=yes'):
            with self.subTest(path=launch_path), tempfile.TemporaryDirectory() as temporary:
                supervisor = Supervisor(StateStore(Path(temporary) / 'state'), Mock())
                with patch.dict(os.environ, {'XDG_CONFIG_HOME': str(Path(temporary) / 'config'), 'PATH': launch_path}):
                    with self.assertRaises(WorkflowError):
                        supervisor.install()

    def test_independent_controller_quotes_paths_and_disables_without_stopping_active_service(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            store = StateStore(root / 'state with "quote" $cash %specifier')
            runner = Mock()
            supervisor = Supervisor(store, runner)
            with patch.dict(os.environ, {'XDG_CONFIG_HOME': str(root / 'config')}):
                supervisor.install()
            controller = store.root / 'controller/scripts/dev-environment.py'
            self.assertTrue(controller.is_file())
            self.assertTrue((controller.parent / 'dev_environment/updater.py').is_file())
            unit = (root / 'config/systemd/user' / (supervisor.unit + '.service')).read_text()
            timer = (root / 'config/systemd/user' / (supervisor.unit + '.timer')).read_text()
            self.assertIn('Unit=' + supervisor.unit + '.service', timer)
            self.assertIn(store.scope, supervisor.unit)
            escaped_controller = str(controller).replace('"', '\\"').replace('$', '$$').replace('%', '%%')
            self.assertIn('"' + escaped_controller + '"', unit)
            self.assertNotIn(str(Path(__file__).resolve().parents[2] / 'scripts/dev-environment.py'), unit)
            store.set_enabled('persistent', True)
            runner.reset_mock()

            def timer_stop(args, **kwargs):
                self.assertFalse(store.enabled('persistent'))
                self.assertEqual(['systemctl', '--user', 'disable', '--now',
                                  supervisor.unit + '.timer'], args)

            runner.run.side_effect = timer_stop
            supervisor.disable()
            runner.run.assert_called_once()


if __name__ == '__main__':
    unittest.main()
