"""Coordinate the trusted controller without repeating uncertain lifecycle claims."""
from __future__ import annotations

import json
from pathlib import Path
import shlex
import time
import uuid

from .model import StateStore, WorkflowError, atomic_json
from .process import Docker, Runner
from .runtime import Runtime


class Workers:
    def __init__(self, store: StateStore, runner: Runner):
        self.store, self.runner = store, runner

    def prepare(self, instance: dict, release: dict) -> None:
        previous = instance.get('workers')
        if previous and previous.get('phase') != 'retired':
            raise WorkflowError('Retire the saved worker deployment before creating a fresh boot.')
        docker = Docker(self.runner, instance['docker'])
        information = docker.validate()
        if information.get('NCPU', 0) < 2 or information.get('MemTotal', 0) < 8448 * 1024 ** 2:
            raise WorkflowError('A worker pair requires at least two CPUs and 8448 MiB of daemon RAM.')
        endpoint = json.loads(docker.run('context', 'inspect', instance['docker']['context']).stdout)[0]['Endpoints']['docker']['Host']
        if not endpoint.startswith('unix://'):
            raise WorkflowError('The local worker controller requires a daemon-visible Unix socket.')
        boot = uuid.uuid4().hex
        directory = self.store.directory(instance['id']) / 'workers' / boot
        directory.mkdir(mode=0o700, parents=True)
        profile = json.loads(docker.run('run', '--rm', '--network', 'none', '--entrypoint', 'php',
            release['worker_image'], '-r', 'require "vendor/autoload.php"; echo (new \\App\\Shared\\Application\\DTO\\WorkerAutoscalingProfile())->toJson();').stdout)
        mounts = [{'source': str(Path(item['source']).resolve()), 'target': item['target'],
                   'role': 'source', 'readOnly': True} for item in instance.get('media', [])]
        runtime = Runtime(self.store, self.runner)
        environment = runtime.environment(instance)
        configuration = runtime.config(instance, release)
        volumes = configuration['volumes']
        app_mounts = configuration['services']['app']['volumes']
        for key, default_target, role in (('media', environment.get('MEDIA_STORAGE_PATH', '/storage/media'), 'source'),
                ('storage', '/var/www/html/storage', 'derived'),
                ('conversions', environment.get('CONVERT_STORAGE_PATH', '/storage/conversions'), 'derived'),
                ('transcode_state', '/var/www/html/var/transcode_state', 'derived')):
            target = instance.get('state_mounts', {}).get(key, {}).get('target', default_target)
            if role == 'source' and any(mount['target'] == target for mount in mounts):
                continue
            configured = next((mount for mount in app_mounts if mount['target'] == target), None)
            if configured and role == 'source' and configured['type'] == 'bind' and configured.get('read_only'):
                mounts.append({'source': str(Path(configured['source']).resolve()), 'target': target,
                               'role': 'source', 'readOnly': True})
                continue
            if configured is None or configured['type'] != 'volume':
                raise WorkflowError('Worker shared ' + key + ' requires a named volume; relocate adopted bind storage before enabling workers.')
            name = volumes[configured['source']]['name']
            docker.run('volume', 'create', '--label', 'app.baander.development.owner=' + instance['id'], name)
            mounts.append({'volume': name, 'target': target, 'role': role, 'readOnly': role == 'source'})
        ipc = instance['project'] + '-ipc-' + boot
        docker.run('volume', 'create', '--label', 'app.baander.development.owner=' + instance['id'], ipc)
        # This fresh volume bypasses image copy-up in both worker recipes.
        # Give its private root to the worker user before either unit starts.
        initialize_ipc = r"""$directory = '/run/baander-worker';
$user = posix_getpwnam('www-data');
if ($user === false || !chmod($directory, 0700)
    || !chgrp($directory, $user['gid']) || !chown($directory, $user['uid'])) { exit(1); }"""
        docker.run('run', '--rm', '--network', 'none', '--read-only', '--user', '0',
            '--cap-drop', 'ALL', '--cap-add', 'CHOWN', '--security-opt', 'no-new-privileges',
            '--mount', 'type=volume,src=' + ipc + ',dst=/run/baander-worker,volume-nocopy',
            '--entrypoint', 'php', release['worker_image'], '-r', initialize_ipc)
        manifest = {'version': 5, 'sourceMountPolicy': 'local-development', 'namespace': instance['project'],
                    'bootId': boot, 'daemonId': instance['docker']['daemon_id'], 'imageId': release['worker_image'],
                    'network': Runtime.network_name(instance), 'dockerBinary': '/usr/local/bin/docker',
                    'dockerEndpoint': 'unix:///var/run/docker.sock', 'controlMemoryMiB': 2944,
                    'executionMemoryMiB': 4480, 'controlNanoCpus': 1000000000, 'executionNanoCpus': 1000000000,
                    'controlCpuSet': [0], 'executionCpuSet': [1], 'controlPidsLimit': 129, 'executionPidsLimit': 256,
                    'autoscaling': profile, 'scheduledConsoleMiB': 320, 'ipcVolume': ipc, 'mediaMounts': mounts}
        allowed = ('APP_SECRET', 'DATABASE_URL', 'REDIS_URL', 'REDIS_PASSWORD', 'MESSENGER_TRANSPORT_DSN',
                   'MAILER_DSN', 'CONVERT_STORAGE_PATH', 'MEDIA_STORAGE_PATH', 'MAIL_FROM_ADDRESS', 'MAIL_FROM_NAME',
                   'VAPID_PRIVATE_KEY', 'VAPID_PUBLIC_KEY', 'STREAM_URL_HMAC_SECRET')
        credentials = {'controllerDatabaseUrl': environment['DATABASE_URL'], 'runtimeEnvironment': {
            **{key: environment[key] for key in allowed if key in environment}, 'APP_ENV': 'prod', 'APP_DEBUG': '0',
            'APP_URL': 'https://' + instance['hostname'], 'DEFAULT_URI': 'https://' + instance['hostname']}}
        for document in (manifest, credentials):
            if len((json.dumps(document, indent=2, sort_keys=True) + '\n').encode('utf-8')) > 8192:
                raise WorkflowError('Worker operator input exceeds its bounded file size.')
        validation = ['run', '--rm', '-i', '--network', 'none', '--user', '0', '--cap-drop', 'ALL',
                      '--security-opt', 'no-new-privileges', '--entrypoint', 'php']
        validation += self.source_mount_arguments(manifest)
        validator = r'''try {
require '/var/www/html/vendor/autoload.php';
$input = stream_get_contents(STDIN, 32769);
if ($input === false || strlen($input) > 32768) { exit(1); }
$document = json_decode($input, true, 16, JSON_THROW_ON_ERROR);
$manifest = \App\Shared\Infrastructure\Worker\DeploymentOperatorManifest::fromJson(json_encode($document['manifest'], JSON_THROW_ON_ERROR));
$environment = new \App\Shared\Infrastructure\Worker\DeploymentRuntimeEnvironment($document['environment']);
foreach (\App\Shared\Application\DTO\DeploymentUnit::cases() as $unit) { $manifest->recipe($unit, $environment); }
} catch (Throwable) { exit(1); }'''
        payload = json.dumps({'manifest': manifest, 'environment': credentials['runtimeEnvironment']})
        if len(payload.encode('utf-8')) > 32768:
            raise WorkflowError('Worker operator input exceeds its bounded transfer size.')
        result = docker.run(*validation, release['operator_image'], '-r', validator, input=payload, check=False)
        if result.returncode:
            raise WorkflowError('Worker shared storage or runtime configuration is invalid; remove overlapping media paths and reconcile adopted storage before enabling workers.')
        # Docker's containerd image store can discard an untagged image index
        # when a later build replaces its revision tag. Keep this boot's controller.
        docker.run('image', 'tag', release['operator_image'],
            'baander-development-controller:' + instance['project'] + '-' + boot)
        atomic_json(directory / 'manifest.json', manifest)
        atomic_json(directory / 'credentials.json', credentials)
        instance['workers'] = {'directory': str(directory), 'operator_image': release['operator_image'],
                               'socket': endpoint.removeprefix('unix://'), 'phase': 'prepared',
                               'boot_id': boot, 'ipc_volume': ipc}
        self.store.save(instance['id'], instance)

    @staticmethod
    def source_mount_arguments(manifest: dict) -> list[str]:
        arguments = []
        for media in manifest['mediaMounts']:
            if media['role'] != 'source' or 'source' not in media:
                continue
            configured = Path(media['source'])
            if not configured.is_dir() or configured.resolve() != configured:
                raise WorkflowError('The recorded source directory changed; reconcile the saved worker deployment.')
            source = str(configured)
            if ',' in source:
                raise WorkflowError('Docker mount paths cannot contain commas.')
            arguments += ['--mount', f'type=bind,src={source},dst={source},readonly,bind-propagation=rprivate,bind-recursive=readonly']
        return arguments

    def invoke(self, instance: dict, action: str) -> dict:
        record = instance['workers']
        directory = Path(record['directory'])
        docker = Docker(self.runner, instance['docker'])
        docker.validate()
        # The trusted controller needs the daemon socket's existing SELinux label.
        # Never relabel that socket or apply this exception to the worker units.
        arguments = ['run', '--rm', '-i', '--network', Runtime.network_name(instance), '--user', '0',
                     '--cap-drop', 'ALL', '--security-opt', 'no-new-privileges',
                     '--security-opt', 'label=disable', '--entrypoint', '/bin/sh',
                     '--mount', 'type=bind,src=' + record['socket'] + ',dst=/var/run/docker.sock',
                     '--tmpfs', '/operator:rw,noexec,nosuid,size=1m,mode=0700']
        documents = {}
        for name in ('manifest', 'credentials'):
            with (directory / (name + '.json')).open('rb') as source:
                contents = source.read(8193)
            if len(contents) > 8192:
                raise WorkflowError('Worker operator input exceeds its bounded file size.')
            documents[name] = contents.decode('utf-8')
        manifest = json.loads(documents['manifest'])
        arguments += self.source_mount_arguments(manifest)
        # Stdin preserves private host ownership without granting DAC capabilities
        # to the root controller. Only fixed paths and a fixed decoder run there.
        decoder = r'''try {
$input = stream_get_contents(STDIN, 32769);
if ($input === false || strlen($input) > 32768) { exit(1); }
$documents = json_decode($input, true, 4, JSON_THROW_ON_ERROR);
if (!is_array($documents) || array_keys($documents) !== ['manifest', 'credentials']) { exit(1); }
umask(0077);
foreach ($documents as $name => $contents) {
    if (!is_string($contents) || strlen($contents) > 8192) { exit(1); }
    $file = fopen('/operator/' . $name . '.json', 'xb');
    if ($file === false || fwrite($file, $contents) !== strlen($contents) || !fclose($file)) { exit(1); }
}
} catch (Throwable) { exit(1); }'''
        arguments += [record['operator_image'], '-ec',
                      'php -r ' + shlex.quote(decoder) + '; '
                      'exec php /var/www/html/bin/worker-deployment.php "$1" /operator/manifest.json /operator/credentials.json',
                      'worker-controller', action]
        payload = json.dumps(documents, ensure_ascii=False, separators=(',', ':'))
        if len(payload.encode('utf-8')) > 32768:
            raise WorkflowError('Worker operator input exceeds its bounded transfer size.')
        result = docker.run(*arguments, input=payload, timeout=240, check=False)
        try:
            response = json.loads(result.stdout)
            if result.returncode or response.get('success') is not True or response.get('action') != action:
                raise ValueError('unconfirmed')
            return response['result']
        except (ValueError, KeyError, TypeError) as error:
            raise WorkflowError('Worker ' + action + ' was not confirmed; inspect status and use explicit recovery.') from error

    def running(self, instance: dict) -> bool:
        """Observe the current boot pair without probing transient functional health."""
        record = instance.get('workers')
        if not record:
            return False
        manifest = json.loads((Path(record['directory']) / 'manifest.json').read_text())
        if manifest['namespace'] != instance['project'] or manifest['bootId'] != record['boot_id']:
            raise WorkflowError('Worker observation identity differs from the saved manifest.')
        docker = Docker(self.runner, instance['docker'])
        docker.validate()
        identifiers = docker.run('ps', '--all', '--quiet', '--no-trunc',
            '--filter', 'label=app.baander.worker.namespace=' + manifest['namespace'],
            '--filter', 'label=app.baander.worker.boot-id=' + manifest['bootId']).stdout.split()
        if not identifiers:
            return False
        containers = json.loads(docker.run('inspect', *identifiers).stdout)
        observed = set()
        running = True
        for container in containers:
            labels = container['Config']['Labels']
            role = labels.get('app.baander.worker.role')
            if (labels.get('app.baander.worker.namespace') != manifest['namespace']
                    or labels.get('app.baander.worker.boot-id') != manifest['bootId']
                    or role not in ('control', 'execution') or role in observed
                    or container['Image'] != manifest['imageId']):
                raise WorkflowError('Worker observation does not identify the recorded boot pair.')
            observed.add(role)
            state = container['State']['Running']
            if not isinstance(state, bool):
                raise WorkflowError('Worker container state was not confirmed.')
            running = running and state
        return observed == {'control', 'execution'} and running

    def phase(self, instance: dict, phase: str) -> None:
        instance['workers']['phase'] = phase
        self.store.save(instance['id'], instance)

    def start(self, instance: dict) -> None:
        if instance['workers'].get('phase', 'prepared') != 'prepared':
            raise WorkflowError('Worker lifecycle was already attempted; reconcile or recover the saved boot.')
        self.phase(instance, 'create_unconfirmed')
        self.invoke(instance, 'create')
        self.phase(instance, 'start_unconfirmed')
        self.invoke(instance, 'start')
        self.phase(instance, 'started')

    def ready(self, instance: dict, timeout: float = 90) -> None:
        deadline = time.monotonic() + timeout
        while True:
            try:
                result = self.invoke(instance, 'readiness')
                if result.get('readiness') != 'ready':
                    raise WorkflowError('Worker readiness was not confirmed.')
                self.phase(instance, 'ready')
                return
            except WorkflowError:
                if time.monotonic() >= deadline:
                    raise
                time.sleep(2)

    def retire(self, instance: dict) -> None:
        if not instance.get('workers') or instance['workers'].get('phase') == 'retired':
            return
        self.phase(instance, 'retirement_unconfirmed')
        status = self.invoke(instance, 'status')
        # Create cannot reach Docker creation before committing its boot reservation.
        # A failure before that reservation leaves no durable deployment to recover.
        if (status.get('namespace') == instance.get('project')
                and status.get('bootId') == instance['workers'].get('boot_id')
                and status.get('retirement') == 'unregistered'
                and status.get('reservation') == 'none' and status.get('units') == []):
            manifest = json.loads((Path(instance['workers']['directory']) / 'manifest.json').read_text())
            if (manifest.get('namespace') != instance['project']
                    or manifest.get('bootId') != instance['workers']['boot_id']):
                raise WorkflowError('Worker cleanup identity differs from the saved manifest.')
            # Also reject orphaned containers if the deployment journal was lost.
            docker = Docker(self.runner, instance['docker'])
            docker.validate()
            containers = docker.run('ps', '-aq',
                '--filter', 'label=app.baander.worker.namespace=' + manifest['namespace'],
                '--filter', 'label=app.baander.worker.boot-id=' + manifest['bootId'])
            if containers.stdout.strip():
                raise WorkflowError('Unregistered worker containers require explicit investigation before cleanup.')
            self.phase(instance, 'retired')
            return
        result = self.invoke(instance, 'recover')
        if result.get('state') != 'retired':
            raise WorkflowError('Worker retirement was not confirmed; the instance remains blocked.')
        self.phase(instance, 'retired')
