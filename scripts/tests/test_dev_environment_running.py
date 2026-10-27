from copy import deepcopy
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import Mock, patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from dev_environment.model import WorkflowError, atomic_json
from dev_environment.runtime import Runtime
from dev_environment.workers import Workers


class RunningContainerObservationTest(unittest.TestCase):
    def setUp(self):
        temporary = tempfile.TemporaryDirectory()
        self.addCleanup(temporary.cleanup)
        self.instance = {'project': 'owned-project', 'kind': 'persistent', 'docker': {},
            'workers': {'directory': temporary.name, 'boot_id': 'saved-boot'}}
        atomic_json(Path(temporary.name) / 'manifest.json',
                    {'namespace': 'owned-project', 'bootId': 'saved-boot', 'imageId': 'worker-image'})

    def observe(self, kind, containers):
        docker = Mock()
        response = subprocess.CompletedProcess([], 0, json.dumps(containers), '')
        identifiers = subprocess.CompletedProcess([], 0, '\n'.join(str(index) for index in range(len(containers))), '')
        docker.compose.return_value = identifiers
        docker.run.side_effect = [identifiers, response] if kind == 'workers' else [response]
        with patch('dev_environment.' + kind + '.Docker', return_value=docker):
            target = Workers(None, None) if kind == 'workers' else Runtime(None, None)
            result = target.running(self.instance)
        docker.validate.assert_called_once()
        if kind == 'workers':
            self.assertEqual(('ps', '--all', '--quiet', '--no-trunc',
                '--filter', 'label=app.baander.worker.namespace=owned-project',
                '--filter', 'label=app.baander.worker.boot-id=saved-boot'), docker.run.call_args_list[0].args)
        else:
            expected = ['app', 'nginx', 'postgres', 'redis'] + (['vite'] if self.instance['kind'] == 'preview' else [])
            docker.compose.assert_called_once_with(self.instance, 'ps', '--all', '--quiet', *expected)
        return result

    def runtime_containers(self):
        services = ['app', 'nginx', 'postgres', 'redis']
        if self.instance['kind'] == 'preview':
            services.append('vite')
        return [{'Config': {'Labels': {'com.docker.compose.project': 'owned-project',
                    'com.docker.compose.service': service}},
                 'State': {'Running': True, 'Health': {'Status': 'unhealthy'}}} for service in services]

    def worker_containers(self):
        return [{'Config': {'Labels': {'app.baander.worker.namespace': 'owned-project',
                    'app.baander.worker.boot-id': 'saved-boot', 'app.baander.worker.role': role}},
                 'Image': 'worker-image', 'State': {'Running': True}} for role in ('control', 'execution')]

    def test_all_owned_running_containers_survive_unhealthy_functional_status(self):
        self.assertTrue(self.observe('runtime', self.runtime_containers()))
        self.assertTrue(self.observe('workers', self.worker_containers()))
        self.instance['kind'] = 'preview'
        self.assertTrue(self.observe('runtime', self.runtime_containers()))

    def test_each_missing_or_stopped_service_and_worker_is_observed(self):
        self.instance['kind'] = 'preview'
        for kind, containers in (('runtime', self.runtime_containers()), ('workers', self.worker_containers())):
            for index in range(len(containers)):
                with self.subTest(kind=kind, index=index):
                    missing = deepcopy(containers)
                    missing.pop(index)
                    self.assertFalse(self.observe(kind, missing))
                    stopped = deepcopy(containers)
                    stopped[index]['State']['Running'] = False
                    self.assertFalse(self.observe(kind, stopped))
            self.assertFalse(self.observe(kind, []))

    def test_wrong_runtime_owner_or_worker_boot_is_not_restart_authority(self):
        runtime = self.runtime_containers()
        runtime[0]['Config']['Labels']['com.docker.compose.project'] = 'other'
        with self.assertRaises(WorkflowError):
            self.observe('runtime', runtime)
        workers = self.worker_containers()
        workers[0]['Config']['Labels']['app.baander.worker.boot-id'] = 'other'
        with self.assertRaises(WorkflowError):
            self.observe('workers', workers)


if __name__ == '__main__':
    unittest.main()
