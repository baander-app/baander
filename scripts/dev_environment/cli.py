"""The shared command boundary for human and agent operations."""
from __future__ import annotations

import argparse
import json
from pathlib import Path
import sys

from .model import StateStore, WorkflowError, resolve_instance
from .process import Docker, Runner


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
            'detected_revision', 'target_revision', 'worker_snapshot', 'readiness', 'failure')} | {
                'updater_enabled': store.enabled(instance['id'])}


def parser() -> argparse.ArgumentParser:
    result = argparse.ArgumentParser(description='Manage isolated Baander development instances.')
    result.add_argument('--state-root', type=Path, help='Explicit development state directory')
    commands = result.add_subparsers(dest='command', required=True)
    read = commands.add_parser('status')
    read.add_argument('--instance')
    read.add_argument('--json', action='store_true')
    execute = commands.add_parser('exec')
    execute.add_argument('--instance')
    execute.add_argument('arguments', nargs=argparse.REMAINDER)
    return result


def main(arguments: list[str] | None = None) -> int:
    args = parser().parse_args(arguments)
    try:
        store = StateStore(args.state_root) if args.state_root else StateStore.default()
        runner = Runner()
        root, repository = repository_context(runner, Path.cwd())
        instance = resolve_instance(store, root, repository, args.instance)
        if args.command == 'status':
            data = status(store, instance)
            if args.json:
                print(json.dumps(data, sort_keys=True))
            else:
                for key, value in data.items():
                    print(f'{key}: {value if value is not None else "unknown"}')
            return 0
        command = args.arguments
        if command[:1] == ['--']:
            command = command[1:]
        if not command:
            raise WorkflowError('Supply a command after exec --.')
        with store.lock(instance['id']):
            instance = store.load(instance['id'])
            if instance['phase'] != 'ready':
                raise WorkflowError('The instance is not ready for commands; inspect its status.')
            docker = Docker(runner, instance['docker'])
            docker.validate()
            result = docker.compose(instance, 'exec', '-T', 'app', *command, check=False, timeout=None)
            print(result.stdout, end='')
            print(result.stderr, end='', file=sys.stderr)
            return result.returncode
    except (WorkflowError, OSError, ValueError) as error:
        print(str(error), file=sys.stderr)
        return 1
