"""The shared command boundary for human and agent operations."""
from __future__ import annotations

import argparse
import json
import os
from pathlib import Path
import shutil
import shlex
import sys

from .bootstrap import Bootstrap
from .build import snapshot_worktree
from .host import Host
from .model import StateStore, WorkflowError, preview_id, resolve_instance
from .process import Docker, Runner
from .supervisor import Supervisor
from .updater import Updater


def repository_context(runner: Runner, directory: Path) -> tuple[Path, str]:
    root = Path(runner.run(['git', 'rev-parse', '--show-toplevel'], cwd=directory).stdout.strip())
    remote = runner.run(['git', 'remote', 'get-url', 'origin'], cwd=root, check=False)
    if remote.returncode == 0:
        identity = remote.stdout.strip()
    else:
        common = runner.run(['git', 'rev-parse', '--git-common-dir'], cwd=root).stdout.strip()
        identity = str((root / common).resolve())
    return root, identity


def status(store: StateStore, instance: dict) -> dict:
    return {key: instance.get(key) for key in ('id', 'hostname', 'phase', 'running_revision',
            'detected_revision', 'target_revision', 'last_ready_revision', 'worker_snapshot', 'readiness', 'failure')} | {
                'updater_enabled': store.enabled(instance['id'])}


def parser() -> argparse.ArgumentParser:
    result = argparse.ArgumentParser(description='Manage isolated Baander development instances.')
    result.add_argument('--state-root', type=Path, help='Explicit development state directory')
    commands = result.add_subparsers(dest='command', required=True)
    host = commands.add_parser('host-setup')
    host.add_argument('--install-trust', action='store_true')
    host.add_argument('--inspect', action='store_true')
    up = commands.add_parser('up')
    up.add_argument('--instance', choices=['persistent'])
    up.add_argument('--media', action='append', default=[], metavar='SOURCE[=TARGET]')
    up.add_argument('--docker-context', default='default')
    up.add_argument('--environment-file', type=Path)
    up.add_argument('--adopt', type=Path)
    for name in ('status', 'down', 'update', 'recover', 'logs', 'restart', 'exec', 'url'):
        command = commands.add_parser(name)
        command.add_argument('--instance')
        if name == 'status':
            command.add_argument('--json', action='store_true')
        if name == 'update':
            command.add_argument('--automatic', action='store_true')
            command.add_argument('--retry', action='store_true')
        if name in ('logs', 'restart', 'exec'):
            command.add_argument('--service', choices=['app', 'nginx', 'postgres', 'redis', 'vite'], default='app')
        if name == 'logs':
            command.add_argument('--follow', action='store_true')
        if name == 'exec':
            command.add_argument('--user')
            command_source = command.add_mutually_exclusive_group()
            command_source.add_argument('--command-env', metavar='NAME')
            command_source.add_argument('--shell-command-env', metavar='NAME')
            command.add_argument('arguments', nargs=argparse.REMAINDER)
    return result


def docker_target(runner: Runner, context: str) -> dict:
    executable = shutil.which('docker')
    if not executable:
        raise WorkflowError('Install Docker with Compose before starting a development instance.')
    information = json.loads(runner.run([executable, '--context', context, 'info', '--format', '{{json .}}']).stdout)
    runner.run([executable, '--context', context, 'compose', 'version'])
    return {'executable': executable, 'context': context, 'daemon_id': information['ID']}


def exec_command(args) -> list[str]:
    command = args.arguments[1:] if args.arguments[:1] == ['--'] else args.arguments
    variable = args.command_env or args.shell_command_env
    if variable:
        if command:
            raise WorkflowError('Choose command arguments or a command environment variable.')
        value = os.environ.get(variable, '')
        if not value.strip():
            raise WorkflowError('The command environment variable is empty or missing.')
        command = ['bash', '-c', value] if args.shell_command_env else shlex.split(value)
    if not command:
        raise WorkflowError('Supply a command after exec --.')
    return command


def validate_media_state(media: list[dict], state_root: Path) -> None:
    state_root = state_root.resolve()
    for mount in media:
        source = Path(mount['source']).resolve()
        if source.is_relative_to(state_root) or state_root.is_relative_to(source):
            raise WorkflowError('Media directories must not contain development state or be inside it.')


def validate_state_root(store: StateStore, root: Path) -> None:
    if store.root.resolve().is_relative_to(root.resolve()):
        raise WorkflowError('Development state must be outside the worktree.')


def configure(store: StateStore, runner: Runner, root: Path, repository: str, args) -> dict:
    validate_state_root(store, root)
    identity = args.instance or preview_id(root, repository)
    with store.lock(identity):
        instance = store.load(identity)
        if instance and instance['repository'] != repository:
            raise WorkflowError('The saved instance belongs to another repository.')
        media = []
        for value in args.media:
            source, separator, target = value.partition('=')
            path = Path(source).expanduser().resolve()
            if not path.is_dir():
                raise WorkflowError('A media source must be an existing directory.')
            media.append({'source': str(path), 'target': target if separator else str(path)})
        if instance is None:
            if not media and identity != 'persistent':
                persistent = store.load('persistent')
                media = persistent.get('media', []) if persistent and persistent['repository'] == repository else []
            validate_media_state(media, store.root)
            instance = {'id': identity, 'kind': 'persistent' if identity == 'persistent' else 'preview',
                        'hostname': 'baander.test' if identity == 'persistent' else identity + '.baander.test',
                        'project': 'baander-' + identity + '-' + store.scope, 'repository': repository, 'worktree': str(root),
                        'phase': 'configured', 'media': media, 'docker': docker_target(runner, args.docker_context)}
            store.save(identity, instance)
        elif media and media != instance.get('media', []):
            if instance['phase'] != 'stopped':
                raise WorkflowError('Stop this instance before changing its media mounts.')
            instance['media'] = media
        validate_media_state(instance.get('media', []), store.root)
        environment = json.loads(args.environment_file.read_text()) if args.environment_file else None
        adoption = json.loads(args.adopt.read_text()) if args.adopt else None
        instance = Bootstrap(store, runner).configure(instance, environment=environment, adoption=adoption)
        store.save(identity, instance)
        return instance


def main(arguments: list[str] | None = None) -> int:
    args = parser().parse_args(arguments)
    try:
        store = StateStore(args.state_root) if args.state_root else StateStore.default()
        runner = Runner()
        if args.command == 'host-setup':
            worktree = runner.run(['git', 'rev-parse', '--show-toplevel'], cwd=Path.cwd(), check=False)
            if worktree.returncode == 0:
                validate_state_root(store, Path(worktree.stdout.strip()))
            host = Host(store, runner)
            directory = host.instructions()
            print('Host setup instructions: ' + str(directory / 'README.md'))
            if args.inspect:
                print(json.dumps(host.inspect(), indent=2))
            if args.install_trust:
                host.certificates(install_trust=True)
            return 0
        if args.command == 'update' and args.automatic:
            if args.instance != 'persistent':
                raise WorkflowError('Automatic updates require the persistent instance.')
            Updater(store, runner).update('persistent', automatic=True)
            return 0
        root, repository = repository_context(runner, Path.cwd())
        if args.command == 'up':
            if not (store.root / 'gateway/tls/cert.pem').is_file():
                raise WorkflowError('Complete make dev-host-setup and certificate trust setup before starting an instance.')
            instance = configure(store, runner, root, repository, args)
            if instance['kind'] == 'persistent':
                Supervisor(store, runner).enable()
            Updater(store, runner).update(instance['id'], retry=True)
            current = store.load(instance['id'])
            if not current or current.get('phase') != 'ready' or not current.get('readiness'):
                raise WorkflowError('Activation stopped before readiness; inspect instance status before opening its hostname.')
            print('Ready: https://' + current['hostname'])
            return 0
        instance = resolve_instance(store, root, repository, args.instance)
        if args.command == 'status':
            data = status(store, instance)
            if instance['kind'] == 'preview':
                try:
                    current = snapshot_worktree(runner, Path(instance['worktree']), None)
                    data['worker_snapshot_current'] = current == instance.get('worker_snapshot')
                except (OSError, WorkflowError):
                    data['worker_snapshot_current'] = 'unavailable'
            if args.json:
                print(json.dumps(data, sort_keys=True))
            else:
                for key, value in data.items():
                    print(f'{key}: {value if value is not None else "unknown"}')
            return 0
        if args.command == 'url':
            if instance['kind'] != 'preview' or instance.get('phase') != 'ready':
                raise WorkflowError('Browser verification requires this worktree\'s ready isolated preview.')
            print('https://' + instance['hostname'])
            return 0
        if args.command == 'down':
            if instance['kind'] == 'persistent':
                Supervisor(store, runner).disable()
            Updater(store, runner).down(instance['id'])
            return 0
        if args.command == 'update':
            Updater(store, runner).update(instance['id'], retry=args.retry)
            return 0
        if args.command == 'recover':
            Updater(store, runner).recover(instance['id'])
            return 0
        if args.command == 'restart':
            Updater(store, runner).down(instance['id'])
            if instance['kind'] == 'persistent':
                Supervisor(store, runner).enable()
            Updater(store, runner).update(instance['id'], retry=True)
            return 0
        if args.command == 'logs':
            docker = Docker(runner, instance['docker'])
            docker.validate()
            values = ['logs', '--tail', '100'] + (['--follow'] if args.follow else []) + [args.service]
            return docker.compose(instance, *values, check=False, timeout=None, interactive=True).returncode
        with store.lock(instance['id']):
            instance = store.load(instance['id'])
            docker = Docker(runner, instance['docker'])
            docker.validate()
            if instance['phase'] != 'ready':
                raise WorkflowError('The instance is not ready for commands; inspect its status.')
            command = exec_command(args)
            values = ['exec'] + ([] if sys.stdin.isatty() else ['-T'])
            if args.user:
                values += ['--user', args.user]
            values += [args.service, *command]
            result = docker.compose(instance, *values, check=False, timeout=None, interactive=True)
            return result.returncode
    except (WorkflowError, OSError, ValueError, KeyError) as error:
        print(str(error), file=sys.stderr)
        return 1
