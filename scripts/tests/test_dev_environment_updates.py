from pathlib import Path
import sys
import tempfile
import unittest
from unittest.mock import Mock, patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from dev_environment.build import SourceUnavailable
from dev_environment.model import StateStore, WorkflowError
from dev_environment.updater import Updater


class UpdateIntentTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.store = StateStore(Path(self.temp.name))
        self.instance = {'id': 'persistent', 'kind': 'persistent', 'repository': '/disposable/remote',
                         'phase': 'ready', 'running_revision': 'old', 'release': {'revision': 'old'}}
        self.store.save('persistent', self.instance)
        self.store.set_enabled('persistent', True)
        self.updater = Updater(self.store, Mock())
        self.updater.head = Mock(return_value='new')
        self.updater.builder = Mock()
        self.updater.activate = Mock()
        self.updater.runtime = Mock()
        self.updater.workers = Mock()
        self.updater.runtime.running.return_value = True
        self.updater.workers.running.return_value = True

    def test_healthy_unchanged_release_is_observed_without_build_or_activation(self):
        self.updater.head.return_value = 'old'
        self.updater.update('persistent', automatic=True)
        self.updater.runtime.running.assert_called_once()
        self.updater.workers.running.assert_called_once()
        self.updater.runtime.ready.assert_not_called()
        self.updater.workers.invoke.assert_not_called()
        self.updater.builder.prepare.assert_not_called()
        self.updater.activate.assert_not_called()

    def test_transient_functional_readiness_failure_does_not_replace_running_boot(self):
        self.updater.head.return_value = 'old'
        self.updater.runtime.ready.side_effect = WorkflowError('transient web failure')
        self.updater.workers.invoke.side_effect = WorkflowError('transient worker readiness')
        self.updater.update('persistent', automatic=True)
        self.updater.runtime.ready.assert_not_called()
        self.updater.workers.invoke.assert_not_called()
        self.updater.builder.prepare.assert_not_called()
        self.updater.activate.assert_not_called()
        self.assertEqual(self.instance, {key: value for key, value in self.store.load('persistent').items()
                                        if key != 'detected_revision'})

    def test_stopped_unchanged_release_resumes_retained_artifacts_without_rebuilding(self):
        self.updater.head.return_value = 'old'
        self.updater.runtime.running.return_value = False
        self.updater.update('persistent', automatic=True)
        self.updater.builder.prepare.assert_not_called()
        instance, release = self.updater.activate.call_args.args
        self.assertEqual({'revision': 'old'}, release)
        self.assertIsNone(instance['readiness'])

    def test_stale_worker_readiness_is_not_republished_after_resume_preflight_failure(self):
        self.updater.head.return_value = 'old'
        self.updater.workers.running.return_value = False
        def fail(instance, release):
            self.updater.save(instance, phase='preflight')
            raise WorkflowError('dependency unavailable')
        self.updater.activate.side_effect = fail
        with self.assertRaises(WorkflowError):
            self.updater.update('persistent', automatic=True)
        saved = self.store.load('persistent')
        self.assertIsNone(saved['readiness'])
        self.assertNotEqual('ready', saved['phase'])
        self.updater.builder.prepare.assert_not_called()

    def test_transient_retained_release_preflight_failure_retries_on_next_automatic_tick(self):
        self.updater.head.return_value = 'old'
        self.updater.runtime.running.return_value = False
        def fail(instance, release):
            self.updater.save(instance, phase='preflight')
            raise WorkflowError('dependency unavailable')
        self.updater.activate.side_effect = fail
        with self.assertRaises(WorkflowError):
            self.updater.update('persistent', automatic=True)
        self.updater.activate.side_effect = None
        self.updater.builder.prepare.return_value = {'revision': 'old'}
        self.updater.update('persistent', automatic=True)
        self.assertEqual(2, self.updater.activate.call_count)
        self.assertIsNone(self.store.load('persistent').get('failed_revision'))

    def test_queued_automatic_update_observes_disabled_intent(self):
        self.store.set_enabled('persistent', False)
        self.updater.update('persistent', automatic=True)
        self.updater.head.assert_not_called()
        self.updater.builder.prepare.assert_not_called()

    def test_preparation_failure_keeps_running_revision_and_skips_same_head(self):
        self.updater.builder.prepare.side_effect = WorkflowError('build failed')
        with self.assertRaises(WorkflowError):
            self.updater.update('persistent')
        state = self.store.load('persistent')
        self.assertEqual('old', state['running_revision'])
        self.assertEqual('new', state['failed_revision'])
        self.updater.update('persistent', automatic=True)
        self.assertEqual(1, self.updater.builder.prepare.call_count)
        self.updater.activate.assert_not_called()

    def test_interrupted_mutation_never_automatically_builds_or_activates(self):
        self.store.save('persistent', dict(self.instance, phase='database_mutating'))
        with self.assertRaises(WorkflowError):
            self.updater.update('persistent', automatic=True)
        self.assertEqual('blocked', self.store.load('persistent')['phase'])
        self.updater.builder.prepare.assert_not_called()
        self.updater.activate.assert_not_called()

    def test_disable_during_preparation_prevents_activation(self):
        def prepare(instance):
            self.store.set_enabled('persistent', False)
            return {'revision': 'new'}
        self.updater.builder.prepare.side_effect = prepare
        self.updater.update('persistent', automatic=True)
        self.updater.activate.assert_not_called()
        self.assertEqual('old', self.store.load('persistent')['running_revision'])

    def test_source_download_failure_retries_same_head_after_backoff(self):
        self.updater.builder.prepare.side_effect = [SourceUnavailable('network'), {'revision': 'new'}]
        with patch('dev_environment.updater.time.time', return_value=100):
            with self.assertRaises(SourceUnavailable):
                self.updater.update('persistent', automatic=True)
            saved = self.store.load('persistent')
            self.assertIsNone(saved.get('failed_revision'))
            self.assertEqual(('ready', 'old'), (saved['phase'], saved['running_revision']))
            self.updater.update('persistent', automatic=True)
            self.assertEqual(1, self.updater.builder.prepare.call_count)
        with patch('dev_environment.updater.time.time', return_value=401):
            self.updater.update('persistent', automatic=True)
        self.assertEqual(2, self.updater.builder.prepare.call_count)
        self.updater.activate.assert_called_once()

    def test_successful_activation_detects_next_merge(self):
        self.updater.head.side_effect = ['new', 'newer']
        self.updater.builder.prepare.return_value = {'revision': 'new'}
        self.updater.update('persistent')
        self.assertEqual('newer', self.store.load('persistent')['detected_revision'])
        self.updater.activate.assert_called_once()


if __name__ == '__main__':
    unittest.main()
