"""Owned gateway routes and inspectable host setup, without implicit privilege."""
from __future__ import annotations

from contextlib import contextmanager
import fcntl
import ipaddress
import json
import os
from pathlib import Path
import re
import shutil
import socket

from .model import StateStore, WorkflowError, atomic_json
from .process import Docker, Runner
from .runtime import GATEWAY_NETWORK, bind


@contextmanager
def gateway_lock(store: StateStore):
    directory = store.root / 'gateway'
    directory.mkdir(mode=0o700, parents=True, exist_ok=True)
    fd = os.open(directory / 'operation.lock', os.O_CREAT | os.O_RDWR | os.O_NOFOLLOW, 0o600)
    try:
        fcntl.flock(fd, fcntl.LOCK_EX)
        yield directory
    finally:
        os.close(fd)


def owned_network(docker: Docker, name: str, owner: str, *, internal: bool = False) -> dict:
    result = docker.run('network', 'inspect', name, check=False)
    if result.returncode:
        arguments = ['network', 'create', '--label', 'app.baander.development.owner=' + owner]
        if internal:
            arguments.append('--internal')
        docker.run(*arguments, name)
        result = docker.run('network', 'inspect', name)
    try:
        network = json.loads(result.stdout)[0]
        if network.get('Labels', {}).get('app.baander.development.owner') != owner:
            raise WorkflowError('Network is not owned by this workflow; select a different network name.')
        subnet = network['IPAM']['Config'][0]['Subnet']
        return {'name': name, 'subnet': subnet}
    except (KeyError, IndexError, TypeError, ValueError) as error:
        raise WorkflowError('Cannot verify owned Docker network inventory.') from error


class Gateway:
    def __init__(self, store: StateStore, runner: Runner):
        self.store, self.runner = store, runner
        self.directory = store.root / 'gateway'
        self.configuration = self.directory / 'conf.d' / 'routes.conf'

    def configure(self, instance: dict, release: dict) -> dict:
        with gateway_lock(self.store):
            docker = Docker(self.runner, instance['docker'])
            docker.validate()
            saved = self.directory / 'state.json'
            if saved.exists():
                record = json.loads(saved.read_text())
                if record['docker'] != instance['docker']:
                    raise WorkflowError('The shared gateway belongs to another Docker target.')
            network = owned_network(docker, GATEWAY_NETWORK, 'gateway')
            address = str(ipaddress.ip_network(network['subnet'])[2])
            gateway = dict(network, ipv4_address=address)
            self.configuration.parent.mkdir(mode=0o700, exist_ok=True)
            if not self.configuration.exists():
                self.configuration.write_text(self.render({}))
            compose = {'name': 'baander-development-gateway', 'services': {'gateway': {
                'image': release['nginx_image'], 'ports': ['127.0.0.1:443:443'],
                'volumes': [bind(self.configuration.parent, '/etc/nginx/conf.d'),
                            bind(self.directory / 'tls', '/etc/nginx/certificates')],
                'networks': {'gateway': {'ipv4_address': address}}}},
                'networks': {'gateway': {'external': True, 'name': GATEWAY_NETWORK}}}
            atomic_json(self.directory / 'compose.json', compose)
            record = {'docker': instance['docker'], 'project': 'baander-development-gateway',
                      'compose_file': str(self.directory / 'compose.json'), 'gateway': gateway,
                      'nginx_image': release['nginx_image']}
            atomic_json(saved, record)
            return gateway

    def start(self) -> None:
        with gateway_lock(self.store):
            record = self.record()
            for name in ('cert.pem', 'key.pem'):
                if not (self.directory / 'tls' / name).is_file():
                    raise WorkflowError('Run explicit host certificate setup before starting the gateway.')
            Docker(self.runner, record['docker']).compose(record, 'up', '-d', '--wait')

    def record(self) -> dict:
        try:
            return json.loads((self.directory / 'state.json').read_text())
        except (OSError, ValueError) as error:
            raise WorkflowError('Configure the shared development gateway first.') from error

    def render(self, routes: dict) -> str:
        template = Path(__file__).resolve().parents[2] / 'docker/dev/gateway.conf.template'
        servers = []
        for hostname, route in sorted(routes.items()):
            if not re.fullmatch(r'(?:[a-z0-9-]+\.)?baander\.test', hostname):
                raise WorkflowError('Gateway hostname must be baander.test or one preview label below it.')
            upstream = route['upstream']
            if not re.fullmatch(r'[a-z0-9][a-z0-9_-]*-frontend', upstream):
                raise WorkflowError('Invalid gateway upstream alias.')
            location = 'return 503;' if route['maintenance'] else f'set $backend http://{upstream}:80; proxy_pass $backend;'
            servers.append(template.read_text().replace('${SERVER_NAME}', hostname).replace('${LOCATION}', location))
        return ('map $http_upgrade $connection_upgrade { default upgrade; \'\' close; }\n'
                'resolver 127.0.0.11 valid=5s;\n'
                'server { listen 443 ssl default_server; server_name _; '
                'ssl_certificate /etc/nginx/certificates/cert.pem; '
                'ssl_certificate_key /etc/nginx/certificates/key.pem; return 404; }\n' + '\n'.join(servers))

    def route(self, instance: dict, maintenance: bool = False) -> None:
        self._change(instance, maintenance)

    def remove(self, instance: dict) -> None:
        self._change(instance, None)

    def _change(self, instance: dict, maintenance: bool | None) -> None:
        with gateway_lock(self.store):
            record = self.record()
            if instance['docker'] != record['docker']:
                raise WorkflowError('Route target differs from the shared gateway Docker target.')
            path = self.directory / 'routes.json'
            routes = json.loads(path.read_text()) if path.exists() else {}
            hostname = instance['hostname']
            if hostname in routes and routes[hostname]['owner'] != instance['id']:
                raise WorkflowError('Hostname is already owned by another instance.')
            if maintenance is None:
                routes.pop(hostname, None)
            else:
                routes[hostname] = {'owner': instance['id'], 'upstream': instance['project'] + '-frontend', 'maintenance': maintenance}
            candidate = self.directory / 'candidate.conf'
            candidate.write_text(self.render(routes))
            docker = Docker(self.runner, record['docker'])
            docker.validate()
            docker.run('run', '--rm', '--network', 'none', '--mount',
                       f'type=bind,src={candidate},dst=/etc/nginx/conf.d/default.conf,readonly',
                       '--mount', f'type=bind,src={self.directory / "tls"},dst=/etc/nginx/certificates,readonly',
                       record['nginx_image'], 'nginx', '-t')
            old = self.configuration.read_text()
            staged = self.configuration.parent / '.routes.pending'
            staged.write_text(candidate.read_text())
            os.replace(staged, self.configuration)
            try:
                docker.compose(record, 'exec', '-T', 'gateway', 'nginx', '-t')
                docker.compose(record, 'exec', '-T', 'gateway', 'nginx', '-s', 'reload')
            except WorkflowError:
                staged.write_text(old)
                os.replace(staged, self.configuration)
                raise
            atomic_json(path, routes)
            candidate.unlink(missing_ok=True)


class Host:
    def __init__(self, store: StateStore, runner: Runner):
        self.store, self.runner = store, runner

    def inspect(self) -> dict:
        if not shutil.which('nmcli'):
            raise WorkflowError('Host setup requires NetworkManager managing DNS; install/configure it explicitly.')
        result = self.runner.run(['NetworkManager', '--print-config'])
        configuration = result.stdout
        if re.search(r'^\s*(dns\s*=\s*none|rc-manager\s*=\s*unmanaged)\s*$', configuration, re.M):
            raise WorkflowError('NetworkManager DNS is disabled; resolve host DNS ownership before setup.')
        resolver = Path('/etc/resolv.conf')
        resolved = str(resolver.resolve())
        if '/NetworkManager/' not in resolved and 'NetworkManager' not in resolver.read_text():
            raise WorkflowError('NetworkManager does not own resolv.conf; configure DNS ownership explicitly.')
        if not shutil.which('mkcert'):
            raise WorkflowError('Install mkcert, then invoke host certificate setup explicitly.')
        with socket.socket() as listener:
            try:
                listener.bind(('127.0.0.1', 443))
            except OSError as error:
                raise WorkflowError('Loopback port 443 is occupied; stop its owner before first gateway startup.') from error
        return {'resolver': 'NetworkManager', 'port': 443, 'hostname': 'baander.test'}

    def instructions(self) -> Path:
        directory = self.store.root / 'host-setup'
        directory.mkdir(mode=0o700, parents=True, exist_ok=True)
        source = Path(__file__).resolve().parents[2] / 'docker/dev/host-setup'
        for name in ('10-baander-dns.conf', 'baander.conf', 'README.md'):
            (directory / name).write_text((source / name).read_text())
        return directory

    def certificates(self, install_trust: bool = False) -> Path:
        directory = self.store.root / 'gateway' / 'tls'
        directory.mkdir(mode=0o700, parents=True, exist_ok=True)
        ca = self.store.root / 'host-setup' / 'ca'
        ca.mkdir(mode=0o700, parents=True, exist_ok=True)
        environment = {'CAROOT': str(ca)}
        if install_trust:
            self.runner.run(['mkcert', '-install'], env=environment)
        self.runner.run(['mkcert', '-cert-file', str(directory / 'cert.pem'), '-key-file', str(directory / 'key.pem'),
                         'baander.test', '*.baander.test'], env=environment)
        (directory / 'key.pem').chmod(0o600)
        return directory
