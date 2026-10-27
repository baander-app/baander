"""Generate and operate one explicitly targeted, isolated development stack."""
from __future__ import annotations

import hashlib
import json
from pathlib import Path
import re

from .model import StateStore, WorkflowError, atomic_json
from .process import Docker, Runner

GATEWAY_NETWORK = 'baander-development-gateway'


def bind(source: str | Path, target: str, read_only: bool = True) -> dict:
    return {'type': 'bind', 'source': str(source).replace('$', '$$'), 'target': target,
            'read_only': read_only, 'bind': {'create_host_path': False}}


def volume(name: str, target: str) -> dict:
    return {'type': 'volume', 'source': name, 'target': target}


class Runtime:
    def __init__(self, store: StateStore, runner: Runner):
        self.store, self.runner = store, runner

    def environment(self, instance: dict) -> dict:
        path = self.store.directory(instance['id']) / 'env.json'
        if path.is_symlink():
            raise WorkflowError('Refusing symlink environment configuration.')
        try:
            values = json.loads(path.read_text())
            if not isinstance(values, dict) or not all(isinstance(k, str) and isinstance(v, str) for k, v in values.items()):
                raise ValueError('expected string environment mapping')
            return values
        except (OSError, ValueError) as error:
            raise WorkflowError('Configure private env.json before starting this instance.') from error

    def config(self, instance: dict, release: dict) -> dict:
        project = instance['project']
        if not re.fullmatch(r'[a-z0-9][a-z0-9_-]*', project):
            raise WorkflowError('Invalid Compose project name.')
        environment = self.environment(instance)
        origin = 'https://' + instance['hostname']
        environment.update(APP_URL=origin, DEFAULT_URI=origin, APP_DOMAIN=instance['hostname'],
                           TRUSTED_PROXIES=instance.get('proxy_ip', ''), DISCOVERY_ENABLED='false')
        mounts = [volume('var', '/var/www/html/var'), volume('secrets', '/var/www/html/config/secrets'),
                  dict(volume('media', environment.get('MEDIA_STORAGE_PATH', '/storage/media')), read_only=True),
                  volume('storage', '/var/www/html/storage'), volume('conversions', environment.get('CONVERT_STORAGE_PATH', '/storage/conversions')),
                  volume('transcode_state', '/var/www/html/var/transcode_state'),
                  volume('public', '/var/www/html/public')]
        for media in instance.get('media', []):
            source = Path(media['source'])
            target = Path(media['target'])
            if not source.is_absolute() or not source.is_dir() or not target.is_absolute():
                raise WorkflowError('Configure an existing absolute media directory and absolute container path.')
            if str(target) == '/var/www/html' or str(target).startswith('/var/www/html/') or str(target) in ('/', '/var/run', '/run', '/etc'):
                raise WorkflowError('Media mount overlaps application or system state.')
            if str(target) == environment.get('MEDIA_STORAGE_PATH', '/storage/media'):
                mounts = [mount for mount in mounts if mount['target'] != str(target)]
            mounts.append(bind(source, str(target)))
        services = {
            'app': {'image': release['app_image'], 'environment': environment, 'volumes': mounts,
                    'stop_grace_period': '35s', 'networks': {'private': {}}, 'init': True},
            'postgres': {'image': release['postgres_image'], 'environment': {
                'POSTGRES_USER': environment.get('DB_USERNAME', 'baander'),
                'POSTGRES_PASSWORD': environment.get('DB_PASSWORD', 'baander'),
                'POSTGRES_DB': environment.get('DB_DATABASE', 'baander'),
                'PGDATA': instance.get('pgdata', '/var/lib/postgresql/data/baanderdev')},
                'volumes': [volume('database', instance.get('postgres_mount_target', '/var/lib/postgresql/data'))], 'networks': {'private': {'aliases': ['database']}},
                'healthcheck': {'test': ['CMD-SHELL', 'pg_isready -U "$$POSTGRES_USER" -d "$$POSTGRES_DB"'],
                                'interval': '5s', 'timeout': '5s', 'retries': 12}},
            'redis': {'image': release['redis_image'], 'environment': {'REDIS_PASSWORD': environment.get('REDIS_PASSWORD', '')},
                      'command': ['sh', '-ec', 'exec redis-server --dir /data --appendonly yes --maxmemory-policy noeviction --requirepass "$$REDIS_PASSWORD"'],
                      'volumes': [volume('redis', '/data')], 'networks': {'private': {}}},
            'nginx': {'image': release['nginx_image'], 'environment': {'SERVER_NAME': instance['hostname'],
                      'GATEWAY_ADDRESS': instance.get('gateway', {}).get('ipv4_address', '')}, 'volumes': [
                          bind(self.store.directory(instance['id']) / 'frontend.conf.template', '/etc/nginx/templates/default.conf.template'),
                          dict(volume('public', '/var/www/html/public'), read_only=True)],
                      'networks': {'private': {}, 'gateway': {'aliases': [project + '-frontend']}}},
        }
        if instance['kind'] == 'preview':
            environment.update(BAANDER_DEV_ORIGIN=origin, APP_ENV='dev', APP_DEBUG='1')
            source = Path(instance['worktree']).resolve()
            mounts.insert(0, bind(source, '/var/www/html'))
            mounts.append(volume('vendor', '/var/www/html/vendor'))
            services['vite'] = {'image': release['tool_image'], 'working_dir': '/var/www/html/ui/web',
                                'entrypoint': ['yarn'], 'command': ['dev', '--host', '0.0.0.0'],
                                'environment': {'BAANDER_DEV_ORIGIN': origin,
                                                'YARN_INSTALL_STATE_PATH': '/var/www/html/ui/web/node_modules/.yarn-install-state.gz'},
                                'volumes': [bind(source, '/var/www/html'), volume('node_modules', '/var/www/html/ui/web/node_modules'),
                                            volume('public', '/var/www/html/public')], 'networks': {'private': {}}}
        for key, saved in instance.get('state_mounts', {}).items():
            service = services['redis'] if key == 'redis' else services['app']
            defaults = {'var': '/var/www/html/var', 'secrets': '/var/www/html/config/secrets',
                        'media': environment.get('MEDIA_STORAGE_PATH', '/storage/media'), 'storage': '/var/www/html/storage', 'conversions': environment.get('CONVERT_STORAGE_PATH', '/storage/conversions'), 'redis': '/data',
                        'transcode_state': '/var/www/html/var/transcode_state'}
            service['volumes'] = [mount for mount in service['volumes'] if mount['target'] != defaults.get(key)]
            if saved['type'] == 'bind':
                service['volumes'].append(bind(saved['source'], saved['target'], key == 'media'))
            else:
                service['volumes'].append(dict(volume(key, saved['target']), read_only=True) if key == 'media' else volume(key, saved['target']))
        for service in services.values():
            if 'environment' in service:
                service['environment'] = {key: str(value).replace('$', '$$')
                                          for key, value in service['environment'].items()}
        names = ('database', 'redis', 'var', 'secrets', 'media', 'storage', 'conversions', 'transcode_state', 'public', 'vendor', 'node_modules')
        return {'name': project, 'services': services,
                'networks': {'private': {'name': self.network_name(instance), 'external': True},
                             'gateway': {'external': True, 'name': instance.get('gateway', {}).get('name', GATEWAY_NETWORK)}},
                'volumes': {key: {'name': instance.get('volumes', {}).get(key, project + '-' + key),
                                  **({'external': True} if key in instance.get('volumes', {}) else {})} for key in names}}

    @staticmethod
    def network_name(instance: dict) -> str:
        network = instance.get('network', instance['project'] + '-private')
        return network['name'] if isinstance(network, dict) else network

    def ensure_networks(self, instance: dict, release: dict) -> dict:
        from .host import Gateway, owned_network
        docker = Docker(self.runner, instance['docker'])
        docker.validate()
        network = owned_network(docker, self.network_name(instance), instance['id'])
        gateway = Gateway(self.store, self.runner).configure(instance, release)
        if instance['kind'] == 'preview':
            source = Path(instance['worktree']).resolve()
            sentinels = ('composer.lock', 'ui/web/package.json')
            try:
                expected = [hashlib.sha256((source / name).read_bytes()).hexdigest() for name in sentinels]
            except OSError as error:
                raise WorkflowError('Preview source must be a complete Baander worktree.') from error
            try:
                result = docker.run('run', '--rm', '--network', 'none', '--entrypoint', '/bin/sh',
                                    '--mount', f'type=bind,src={source},dst=/probe,readonly',
                                    release['app_image'], '-ec',
                                    'sha256sum /probe/composer.lock /probe/ui/web/package.json')
            except WorkflowError as error:
                raise WorkflowError('Preview checkout is unavailable to the selected Docker daemon; check daemon path sharing, Unix permissions and SELinux read labels.') from error
            actual = [line.split()[0] for line in result.stdout.splitlines() if line.strip()]
            if actual != expected:
                raise WorkflowError('Docker sees different preview source at this path; check daemon path sharing, Unix permissions and SELinux read labels.')
        for media in instance.get('media', []):
            source = Path(media['source'])
            if not source.is_absolute() or not source.is_dir():
                raise WorkflowError('Configure an existing absolute media directory.')
            docker.run('run', '--rm', '--network', 'none', '--entrypoint', '/bin/sh',
                       '--mount', f'type=bind,src={source},dst=/probe,readonly',
                       release['app_image'], '-ec', 'test -d /probe && test -r /probe && test -x /probe')
        return dict(instance, network=network, gateway=gateway)

    def write(self, instance: dict, release: dict) -> dict:
        instance = self.ensure_networks(instance, release)
        directory = self.store._prepare(instance['id'])
        template = Path(__file__).resolve().parents[2] / 'docker/dev/frontend.conf.template'
        content = template.read_text()
        if instance['kind'] != 'preview':
            content = content.replace('include /etc/nginx/preview.conf;', '')
        else:
            content = content.replace('include /etc/nginx/preview.conf;', '''location /@ { proxy_pass $vite_backend; }
    location /src/ { proxy_pass $vite_backend; }
    location /node_modules/ { proxy_pass $vite_backend; }
    location /__vite_hmr { proxy_pass $vite_backend; }''')
        (directory / 'frontend.conf.template').write_text(content)
        path = directory / 'compose.json'
        atomic_json(path, self.config(instance, release))
        return dict(instance, compose_file=str(path))

    def running(self, instance: dict) -> bool:
        """Observe owned container presence without treating health as restart authority."""
        docker = Docker(self.runner, instance['docker'])
        docker.validate()
        services = {'app', 'nginx', 'postgres', 'redis'}
        if instance['kind'] == 'preview':
            services.add('vite')
        identifiers = docker.compose(instance, 'ps', '--all', '--quiet', *sorted(services)).stdout.split()
        if not identifiers:
            return False
        containers = json.loads(docker.run('inspect', *identifiers).stdout)
        observed = set()
        running = True
        for container in containers:
            labels = container['Config']['Labels']
            service = labels.get('com.docker.compose.service')
            if (labels.get('com.docker.compose.project') != instance['project']
                    or service not in services or service in observed):
                raise WorkflowError('Runtime observation does not identify the owned service set.')
            observed.add(service)
            state = container['State']['Running']
            if not isinstance(state, bool):
                raise WorkflowError('Runtime container state was not confirmed.')
            running = running and state
        return observed == services and running

    def dependencies(self, instance: dict) -> None:
        docker = Docker(self.runner, instance['docker'])
        docker.validate()
        docker.compose(instance, 'up', '-d', '--wait', 'postgres', 'redis')

    def prepare(self, instance: dict, release: dict) -> None:
        """Populate only this instance's generated assets and preview dependencies."""
        docker = Docker(self.runner, instance['docker'])
        docker.validate()
        model = self.config(instance, release)
        if instance['kind'] == 'preview':
            root = Path(instance['worktree']).resolve(strict=True)
            container_root = Path('/var/www/html')
            targets = {Path(mount['target']).relative_to(container_root)
                       for service in model['services'].values() for mount in service.get('volumes', [])
                       if mount['type'] == 'volume' and Path(mount['target']).is_relative_to(container_root)}
            # Docker cannot create nested volume mountpoints through a read-only bind.
            # Only empty directories belong in the checkout; contents stay in volumes.
            for target in targets:
                if '..' in target.parts:
                    raise WorkflowError('Preview volume mountpoints must not contain parent segments.')
                for part in (target, *target.parents):
                    if (root / part).is_symlink():
                        raise WorkflowError('Preview volume mountpoint contains a symlink; use checkout directories.')
                if (root / target).exists() and not (root / target).is_dir():
                    raise WorkflowError('Preview volume mountpoint is not a directory.')
            for target in targets:
                (root / target).mkdir(parents=True, exist_ok=True)
        destinations = [('public', '/var/www/html/public'),
                        ('var', '/var/www/html/var'),
                        ('storage', '/var/www/html/storage'), ('conversions', '/storage/conversions'),
                        ('transcode_state', '/var/www/html/var/transcode_state'),
                        ('secrets', '/var/www/html/config/secrets')]
        if instance['kind'] == 'preview':
            destinations.append(('vendor', '/var/www/html/vendor'))
        for key, source in destinations:
            name = model['volumes'][key]['name']
            if key in ('public', 'vendor'):
                script = ('find /seed -mindepth 1 -maxdepth 1 -exec rm -rf -- {} +; '
                          f'cp -a {source}/. /seed/; ')
            elif key == 'secrets':
                script = (f'if [ -z "$(ls -A /seed)" ] && [ -d {source} ]; then '
                          f'cp -a {source}/. /seed/; fi; ')
            elif key == 'var':
                script = 'rm -rf /seed/cache; mkdir -p /seed/cache /seed/log; '
            else:
                script = ''
            script += 'chown ' + ('-R ' if key in ('public', 'vendor', 'var', 'secrets') else '') + 'www-data:www-data /seed'
            docker.run('run', '--rm', '--network', 'none', '--user', '0:0',
                       '--entrypoint', '/bin/sh', '--mount', f'type=volume,src={name},dst=/seed',
                       release['app_image'], '-ec', script)
        if instance['kind'] == 'preview':
            source = Path(instance['worktree']).resolve()
            modules = model['volumes']['node_modules']['name']
            docker.run('run', '--rm', '--entrypoint', 'yarn', '--workdir', '/var/www/html/ui/web',
                       '--mount', f'type=bind,src={source},dst=/var/www/html,readonly',
                       '--mount', f'type=volume,src={modules},dst=/var/www/html/ui/web/node_modules',
                       '--env', 'YARN_ENABLE_IMMUTABLE_INSTALLS=true',
                       '--env', 'YARN_INSTALL_STATE_PATH=/var/www/html/ui/web/node_modules/.yarn-install-state.gz',
                       '--env', 'YARN_CACHE_FOLDER=/var/www/html/ui/web/node_modules/.yarn-cache',
                       release['tool_image'], 'install', '--immutable', timeout=900)

    def up(self, instance: dict, release: dict) -> None:
        docker = Docker(self.runner, instance['docker'])
        docker.validate()
        self.prepare(instance, release)
        docker.compose(instance, 'up', '-d', '--no-deps', 'nginx')
        identifier = docker.compose(instance, 'ps', '-q', 'nginx').stdout.strip()
        if not identifier:
            raise WorkflowError('Frontend did not start; inspect this instance logs.')
        networks = json.loads(docker.run('inspect', '--format', '{{json .NetworkSettings.Networks}}', identifier).stdout)
        private = self.network_name(instance)
        proxy_ip = networks[private]['IPAddress']
        configured = self.write(dict(instance, proxy_ip=proxy_ip), release)
        instance.update(proxy_ip=proxy_ip, compose_file=configured['compose_file'])
        services = ['app'] + (['vite'] if instance['kind'] == 'preview' else [])
        docker.compose(instance, 'up', '-d', '--no-deps', *services)

    def down(self, instance: dict) -> None:
        docker = Docker(self.runner, instance['docker'])
        docker.validate()
        docker.compose(instance, 'down', '--remove-orphans')

    def ready(self, instance: dict) -> dict:
        origin = 'https://' + instance['hostname']
        result = self.runner.run(['curl', '--fail', '--silent', '--show-error', '--max-time', '15', origin + '/ready'], check=False)
        if result.returncode:
            raise WorkflowError(f'{origin} is not ready; inspect instance logs, DNS and certificate trust.')
        return {'url': origin, 'web_ready': True}
