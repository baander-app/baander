from pathlib import Path
import sys
import tempfile
import unittest
from unittest.mock import Mock, patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from dev_environment.build import Builder
from dev_environment.model import StateStore


class ReleaseImageRetentionTest(unittest.TestCase):
    def setUp(self):
        temporary = tempfile.TemporaryDirectory()
        self.addCleanup(temporary.cleanup)
        self.store = StateStore(Path(temporary.name))
        self.instance = {'id': 'persistent', 'kind': 'persistent', 'project': 'owned-project',
                         'repository': 'local-source', 'docker': {}}
        self.store.save('persistent', self.instance)
        self.builder = Builder(self.store, None)

    def prepare(self, instance):
        docker = Mock()
        with patch('dev_environment.build.Docker', return_value=docker), \
                patch('dev_environment.build.checkout_revision', return_value=(None, 'same-revision')), \
                patch.object(self.builder, 'build', side_effect=lambda docker, source, target, revision: target + '-id'), \
                patch.object(self.builder, 'frontend'), \
                patch.object(self.builder, 'image_id', side_effect=lambda docker, name: name + '-id'):
            release = self.builder.prepare(instance)
        return release, docker

    def test_every_final_image_is_retained_with_a_unique_build_tag(self):
        first, docker = self.prepare(self.instance)
        second, _ = self.prepare(self.instance)
        expected_keys = {'tool_image', 'app_image', 'worker_image', 'operator_image',
                         'postgres_image', 'nginx_image', 'redis_image'}
        self.assertEqual(expected_keys, set(first['image_pins']))
        for key, pin in first['image_pins'].items():
            self.assertIn('owned-project-' + Path(first['source']).name, pin)
            docker.run.assert_any_call('image', 'tag', first[key], pin)
            self.assertNotEqual(pin, second['image_pins'][key])

    def test_build_exports_use_unique_tags_before_inspection_or_final_retention(self):
        docker = Mock()
        with patch.object(self.builder, 'image_id', return_value='built-id') as inspect:
            for identity in ('first-source', 'second-source'):
                self.builder.build(docker, Path('/builds') / identity, 'worker', 'same-revision')
                tag = 'baander-development-worker:same-revision-' + identity
                command = docker.run.call_args.args
                self.assertEqual(tag, command[command.index('--tag') + 1])
                inspect.assert_called_with(docker, tag)
        first, first_docker = self.prepare(self.instance)
        second, second_docker = self.prepare(self.instance)
        for release, client in ((first, first_docker), (second, second_docker)):
            postgres_build = next(call.args for call in client.run.call_args_list
                                  if call.args[0] == 'build')
            tag = postgres_build[postgres_build.index('--tag') + 1]
            self.assertEqual('baander-development-postgres:same-revision-' + Path(release['source']).name, tag)

    def test_storage_pins_follow_existing_instance_then_previous_release_precedence(self):
        previous = {'postgres_image': 'previous-postgres', 'redis_image': 'previous-redis'}
        for configured, expected in (({}, previous),
                ({'postgres_image': 'adopted-postgres', 'redis_image': 'adopted-redis'},
                 {'postgres_image': 'adopted-postgres', 'redis_image': 'adopted-redis'})):
            with self.subTest(configured=configured):
                release, docker = self.prepare({**self.instance, 'release': previous, **configured})
                for key, image in expected.items():
                    self.assertEqual(image, release[key])
                    docker.run.assert_any_call('image', 'tag', image, release['image_pins'][key])


if __name__ == '__main__':
    unittest.main()
