#!/usr/bin/env python3
import argparse
import json
from pathlib import Path
import tempfile
import uuid

from dev_environment.build import Builder, snapshot_worktree
from dev_environment.cli import docker_target
from dev_environment.model import WorkflowError
from dev_environment.process import Docker, Runner


ROOT = Path(__file__).resolve().parent.parent
CONTAINER_ROOT = '/var/www/html'


def validate_dependencies(root):
    try:
        lock = json.loads((root / 'composer.lock').read_text())
        installed = json.loads((root / 'vendor/composer/installed.json').read_text())
        def inventory(packages):
            return {package['name']: (package['version'],
                    package.get('source', {}).get('reference'),
                    package.get('dist', {}).get('reference')) for package in packages}
        if inventory(lock['packages'] + lock['packages-dev']) == inventory(installed['packages']):
            return
    except (OSError, ValueError, KeyError, TypeError):
        pass
    raise WorkflowError('Composer dependencies do not match composer.lock; run composer install with development dependencies first.')


def prepare_checkout(runner, root, directory):
    if not (root / 'vendor/autoload.php').is_file():
        raise WorkflowError('Install Composer dependencies first.')
    validate_dependencies(root)
    source = directory / 'source'
    revision = snapshot_worktree(runner, root, source)
    archive = directory / 'checkout.tar'
    runner.run(['tar', '--exclude=__pycache__', '--exclude=.phpunit.cache',
                '-cf', str(archive), '-C', str(source), '.', '-C', str(root), 'vendor'], timeout=None)
    return source, revision, archive


def run_check(docker, image, archive, command):
    name = 'baander-check-' + uuid.uuid4().hex[:12]
    docker.run('create', '--name', name, '--user', '0:0', '--network', 'none',
               '--workdir', CONTAINER_ROOT, '--entrypoint', 'sh',
               '--env', 'APP_ENV=test', '--env', 'APP_DEBUG=0', '--env', 'XDEBUG_MODE=off',
               image, '-ec', 'exec sleep infinity')
    try:
        docker.run('start', name)
        docker.run('cp', str(archive), name + ':/tmp/checkout.tar', timeout=None)
        docker.run('exec', name, 'sh', '-ec', '''
            umask 077
            rm -rf /var/www/html
            mkdir -p /var/www/html
            tar -xf /tmp/checkout.tar -C /var/www/html
            rm /tmp/checkout.tar
            mkdir -p /var/www/html/config/secrets/oauth /var/www/html/var
            openssl genrsa -out /var/www/html/config/secrets/oauth/private.key 2048 2>/dev/null
            openssl rsa -in /var/www/html/config/secrets/oauth/private.key -pubout -out /var/www/html/config/secrets/oauth/public.key 2>/dev/null
            chown -R www-data:www-data /var/www/html
        ''', timeout=None)
        return docker.run('exec', '--user', 'www-data', name, *command,
                          timeout=None, check=False, interactive=True).returncode
    finally:
        docker.run('rm', '--force', name, check=False)


def main():
    parser = argparse.ArgumentParser(description='Run static checks against the current checkout in a disposable CI container.')
    parser.add_argument('--image', help='Reuse this runtime image with the current checkout and installed Composer dependencies.')
    parser.add_argument('--docker-context', default='default')
    parser.add_argument('command', nargs=argparse.REMAINDER)
    args = parser.parse_args()
    command = args.command[1:] if args.command[:1] == ['--'] else args.command
    if not command:
        parser.error('Supply a check command after --.')
    runner = Runner()
    docker = Docker(runner, docker_target(runner, args.docker_context))
    with tempfile.TemporaryDirectory(prefix='baander-check-') as directory:
        source, revision, archive = prepare_checkout(runner, ROOT, Path(directory))
        image = args.image or Builder(None, runner).build(docker, source, 'ci', revision)
        return run_check(docker, image, archive, command)


if __name__ == '__main__':
    try:
        raise SystemExit(main())
    except WorkflowError as error:
        raise SystemExit(str(error))
