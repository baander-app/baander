"""Build immutable releases without modifying a developer's checkout."""
from __future__ import annotations

import hashlib
from pathlib import Path
import tarfile
import tempfile
import uuid

from .model import WorkflowError
from .process import Docker, Runner


class SourceUnavailable(WorkflowError):
    """The remote source could not be acquired; a later attempt may succeed."""


EXCLUDED = {'.git', '.agents', '.codex', '.claude', '.idea', '.reli', 'vendor',
            'node_modules', 'var', '__pycache__', '.phpunit.cache'}


def snapshot_worktree(runner: Runner, source: Path, destination: Path | None) -> str:
    source = source.resolve()
    if destination is not None:
        destination.mkdir(parents=True, exist_ok=False)
    names = runner.run(['git', 'ls-files', '--cached', '--others', '--exclude-standard', '-z'], cwd=source).stdout.split('\0')
    digest = hashlib.sha256()
    for name in sorted(set(names) - {''}):
        path = Path(name)
        if (path.is_absolute() or '..' in path.parts or EXCLUDED.intersection(path.parts)
                or name.startswith('config/secrets/') or name == 'auth.json'
                or (path.name.startswith('.env') and ('.local' in path.name))
                or (name.startswith('docker/dev/') and path.suffix == '.key')):
            continue
        original = source / path
        if not original.exists() and not original.is_symlink():
            continue
        target = destination / path if destination is not None else None
        if target is not None:
            target.parent.mkdir(parents=True, exist_ok=True)
        if original.is_symlink():
            resolved = original.resolve()
            if not resolved.is_relative_to(source):
                raise WorkflowError('Source contains a symlink outside the worktree: ' + name)
            link = original.readlink()
            if link.is_absolute():
                raise WorkflowError('Source contains an absolute symlink: ' + name)
            if target is not None:
                target.symlink_to(link)
            content = str(link).encode()
        elif original.is_file():
            if path.name == '.env' or (path.name.startswith('.env.') and not path.name.endswith('.example')):
                template = runner.run(['git', 'show', 'HEAD:' + name], cwd=source, check=False)
                if template.returncode:
                    continue
                content = template.stdout.encode()
            else:
                content = original.read_bytes()
            if target is not None:
                target.write_bytes(content)
                target.chmod(original.stat().st_mode & 0o777)
        else:
            raise WorkflowError('Source snapshot contains an unsupported entry: ' + name)
        for part in (name.encode(), b'\0', str(original.lstat().st_mode & 0o777).encode(), b'\0', content, b'\0'):
            digest.update(part)
    return digest.hexdigest()


def checkout_revision(runner: Runner, remote: str, managed: Path, destination: Path) -> tuple[Path, str]:
    if not managed.exists():
        managed.parent.mkdir(parents=True, exist_ok=True)
        with tempfile.TemporaryDirectory(prefix='.clone-', dir=managed.parent) as temporary:
            checkout = Path(temporary) / 'checkout'
            try:
                runner.run(['git', 'clone', '--no-checkout', '--no-hardlinks', '--', remote, str(checkout)], timeout=300)
            except WorkflowError as error:
                raise SourceUnavailable('Source clone is unavailable; retry source acquisition later.') from error
            checkout.rename(managed)

    actual = runner.run(['git', 'remote', 'get-url', 'origin'], cwd=managed).stdout.strip()
    if actual != remote:
        raise WorkflowError('Managed checkout belongs to a different remote.')
    try:
        runner.run(['git', 'fetch', '--no-tags', 'origin', '+refs/heads/master:refs/remotes/origin/master'], cwd=managed, timeout=300)
    except WorkflowError as error:
        raise SourceUnavailable('Source fetch is unavailable; retry source acquisition later.') from error
    revision = runner.run(['git', 'rev-parse', '--verify', 'refs/remotes/origin/master^{commit}'], cwd=managed).stdout.strip()
    destination.mkdir(parents=True, exist_ok=False)
    with tempfile.TemporaryDirectory(prefix='baander-source-') as temporary:
        archive = Path(temporary) / 'source.tar'
        runner.run(['git', 'archive', '--format=tar', '--output=' + str(archive), revision], cwd=managed)
        with tarfile.open(archive) as contents:
            contents.extractall(destination, filter='data')
    return destination, revision


class Builder:
    def __init__(self, store, runner: Runner):
        self.store, self.runner = store, runner

    def prepare(self, instance: dict) -> dict:
        docker = Docker(self.runner, instance['docker'])
        docker.validate()
        builds = self.store.directory(instance['id']) / 'builds'
        builds.mkdir(mode=0o700, exist_ok=True)
        source = builds / uuid.uuid4().hex
        if instance['kind'] == 'persistent':
            _, revision = checkout_revision(self.runner, instance['repository'],
                                            self.store.directory(instance['id']) / 'managed', source)
        else:
            revision = snapshot_worktree(self.runner, Path(instance['worktree']), source)
        instance['target_revision'] = revision
        self.store.save(instance['id'], instance)
        release = {'revision': revision, 'source': str(source)}
        release['tool_image'] = self.build(docker, source, 'dev-tools', revision)
        self.frontend(docker, source, release['tool_image'])
        release['app_image'] = self.build(docker, source, 'production' if instance['kind'] == 'persistent' else 'dev-runtime', revision)
        release['worker_image'] = self.build(docker, source, 'worker', revision)
        release['operator_image'] = self.build(docker, source, 'dev-operator', revision)
        tag = 'baander-development-postgres:' + revision + '-' + source.name
        docker.run('build', '--file', str(source / 'docker/postgres/Dockerfile'), '--tag', tag, str(source / 'docker'), timeout=None, log_path=source.parent / (source.name + '-postgres.log'))
        release['postgres_image'] = self.image_id(docker, tag)
        for key, tag in (('nginx_image', 'nginx:stable-alpine'), ('redis_image', 'redis:7-alpine')):
            docker.run('pull', tag, timeout=300, log_path=source.parent / (source.name + '-' + key + '.log'))
            release[key] = self.image_id(docker, tag)
        previous = instance.get('release') or {}
        for key in ('postgres_image', 'redis_image'):
            release[key] = instance.get(key) or previous.get(key) or release[key]
        # Pin the final runtime selection before another build replaces revision tags.
        release['image_pins'] = {}
        for key in tuple(release):
            if key.endswith('_image'):
                pin = 'baander-development-release-' + key.removesuffix('_image') + ':' + instance['project'] + '-' + source.name
                docker.run('image', 'tag', release[key], pin)
                release['image_pins'][key] = pin
        return release

    def image_id(self, docker: Docker, name: str) -> str:
        return docker.run('image', 'inspect', '--format', '{{.Id}}', name).stdout.strip()

    def build(self, docker: Docker, source: Path, target: str, revision: str) -> str:
        tag = 'baander-development-' + target + ':' + revision + '-' + source.name
        docker.run('build', '--target', target, '--build-arg', 'BAANDER_SOURCE_REVISION=' + revision,
                   '--tag', tag, str(source), timeout=None, log_path=source.parent / (source.name + '-' + target + '.log'))
        return self.image_id(docker, tag)

    def frontend(self, docker: Docker, source: Path, image: str) -> None:
        name = 'baander-assets-' + uuid.uuid4().hex[:12]
        docker.run('create', '--name', name, '--workdir', '/work/ui/web', image,
                   'sh', '-ec', 'yarn install --immutable && yarn build')
        try:
            docker.run('cp', str(source) + '/.', name + ':/work', timeout=300)
            docker.run('start', '--attach', name, timeout=None, log_path=source.parent / (source.name + '-frontend.log'))
            code = docker.run('inspect', '--format', '{{.State.ExitCode}}', name).stdout.strip()
            if code != '0':
                raise WorkflowError('Frontend build failed before activation.')
            docker.run('cp', name + ':/work/public/.', str(source / 'public'), timeout=300)
        finally:
            docker.run('rm', '--force', name, check=False)
