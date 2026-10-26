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
                  volume('media', '/storage/media'), volume('conversions', '/storage/conversions'),
                  volume('public', '/var/www/html/public')]
        for media in instance.get('media', []):
            source = Path(media['source'])
            target = Path(media['target'])
            if not source.is_absolute() or not source.is_dir() or not target.is_absolute():
                raise WorkflowError('Configure an existing absolute media directory and absolute container path.')
            if str(target) == '/var/www/html' or str(target).startswith('/var/www/html/') or str(target) in ('/', '/var/run', '/run', '/etc'):
                raise WorkflowError('Media mount overlaps application or system state.')
            mounts.append(bind(source, str(target)))
        services = {
            'app': {'image': release['app_image'], 'environment': environment, 'volumes': mounts,
                    'stop_grace_period': '35s', 'networks': {'private': {}}, 'init': True},
            'postgres': {'image': release['postgres_image'], 'environment': {
                'POSTGRES_USER': environment.get('DB_USERNAME', 'baander'),
                'POSTGRES_PASSWORD': environment.get('DB_PASSWORD', 'baander'),
                'POSTGRES_DB': environment.get('DB_DATABASE', 'baander'),
                'PGDATA': instance.get('pgdata', '/var/lib/postgresql/data/baanderdev')},
                'volumes': [volume('database', '/var/lib/postgresql/data')], 'networks': {'private': {'aliases': ['database']}},
                'healthcheck': {'test': ['CMD-SHELL', 'pg_isready -U "$$POSTGRES_USER" -d "$$POSTGRES_DB"'],
                                'interval': '5s', 'timeout': '5s', 'retries': 12}},
            'redis': {'image': release['redis_image'], 'environment': {'REDIS_PASSWORD': environment.get('REDIS_PASSWORD', '')},
                      'command': ['sh', '-ec', 'exec redis-server --appendonly yes --maxmemory-policy noeviction --requirepass "$$REDIS_PASSWORD"'],
                      'volumes': [volume('redis', '/data')], 'networks': {'private': {}}},
            'nginx': {'image': release['nginx_image'], 'environment': {'SERVER_NAME': instance['hostname'],
                      'GATEWAY_ADDRESS': instance.get('gateway', {}).get('ipv4_address', '')}, 'volumes': [
                          bind(self.store.directory(instance['id']) / 'frontend.conf.template', '/etc/nginx/templates/default.conf.template'),
                          dict(volume('public', '/var/www/html/public'), read_only=True)],
                      'networks': {'private': {}, 'gateway': {'aliases': [project + '-frontend']}}},
        }
        if instance['kind'] == 'preview':
            source = Path(instance['worktree']).resolve()
            mounts.insert(0, bind(source, '/var/www/html'))
            mounts.append(volume('vendor', '/var/www/html/vendor'))
            services['vite'] = {'image': release['tool_image'], 'working_dir': '/var/www/html/ui/web',
                                'entrypoint': ['yarn'], 'command': ['dev', '--host', '0.0.0.0'],
                                'environment': {'BAANDER_DEV_ORIGIN': origin},
                                'volumes': [bind(source, '/var/www/html'), volume('node_modules', '/var/www/html/ui/web/node_modules'),
                                            volume('public', '/var/www/html/public')], 'networks': {'private': {}}}
        for service in services.values():
            if 'environment' in service:
                service['environment'] = {key: str(value).replace('$', '$$')
                                          for key, value in service['environment'].items()}
        names = ('database', 'redis', 'var', 'secrets', 'media', 'conversions', 'public', 'vendor', 'node_modules')
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
                raise WorkflowError('Preview checkout is unavailable to the selected Docker daemon; use a daemon-visible worktree path or local Docker context.') from error
            actual = [line.split()[0] for line in result.stdout.splitlines() if line.strip()]
            if actual != expected:
                raise WorkflowError('Docker sees different preview source at this path; use a daemon-visible worktree path or local Docker context.')
        for media in instance.get('media', []):
            source = Path(media['source'])
            if not source.is_absolute() or not source.is_dir():
                raise WorkflowError('Configure an existing absolute media directory.')
            docker.run('run', '--rm', '--network', 'none', '--entrypoint', '/bin/sh',
                       '--mount', f'type=bind,src={source},dst=/probe,readonly',
                       release['app_image'], '-ec', 'test -d /probe')
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

    def dependencies(self, instance: dict) -> None:
        docker = Docker(self.runner, instance['docker'])
        docker.validate()
        docker.compose(instance, 'up', '-d', '--wait', 'postgres', 'redis')

    def prepare(self, instance: dict, release: dict) -> None:
        """Populate only this instance's generated assets and preview dependencies."""
        docker = Docker(self.runner, instance['docker'])
        docker.validate()
        model = self.config(instance, release)
        destinations = [('public', '/var/www/html/public'),
                        ('var', '/var/www/html/var'),
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
            else:
                script = 'mkdir -p /seed/cache /seed/log; '
            script += 'chown -R www-data:www-data /seed'
            docker.run('run', '--rm', '--network', 'none', '--user', '0:0',
                       '--entrypoint', '/bin/sh', '--mount', f'type=volume,src={name},dst=/seed',
                       release['app_image'], '-ec', script)
        if instance['kind'] == 'preview':
            source = Path(instance['worktree']).resolve()
            modules = model['volumes']['node_modules']['name']
            docker.run('run', '--rm', '--entrypoint', 'yarn', '--workdir', '/work/ui/web',
                       '--mount', f'type=bind,src={source},dst=/work,readonly',
                       '--mount', f'type=volume,src={modules},dst=/work/ui/web/node_modules',
                       '--env', 'YARN_ENABLE_IMMUTABLE_INSTALLS=true',
                       '--env', 'YARN_INSTALL_STATE_PATH=/work/ui/web/node_modules/.yarn-install-state.gz',
                       '--env', 'YARN_CACHE_FOLDER=/work/ui/web/node_modules/.yarn-cache',
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
