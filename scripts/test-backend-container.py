#!/usr/bin/env python3
"""Run backend tests in disposable, independently isolated Docker stacks."""
from __future__ import annotations

import concurrent.futures
import json
import os
from pathlib import Path
import re
import shutil
import signal
import subprocess
import sys
import tempfile
import threading
import time
import uuid

ROOT = Path(__file__).resolve().parent.parent
CONTAINER_ROOT = '/var/www/html'
RUN_DIRECTORY = '/tmp/baander-run'
ARCHIVE_PATHS = [
    'vendor', 'src', 'tests', 'config', 'packages', 'migrations', 'bin', 'scripts',
    'docker', 'templates', 'public', 'phpunit.xml.dist', '.env', '.env.test',
    '.forgejo', 'composer.json', 'composer.lock', 'symfony.lock', 'deptrac.yaml',
    'deptrac.baseline.yaml', 'phpstan.dist.neon', 'translations', 'docs-book',
    'docker-compose.yml', 'compose.override.yaml', 'compose.test.yaml', 'openapi.json',
]


def positive_integer(value: str, name: str, maximum: int | None = None) -> int:
    if not re.fullmatch(r'[1-9][0-9]*', value):
        raise ValueError(f'{name} must be a positive integer')
    result = int(value)
    if maximum is not None and result > maximum:
        raise ValueError(f'{name} must be between 1 and {maximum}')
    return result


def partition(files: list[str], native: dict[str, str], workers: int) -> dict[str, dict]:
    if len(files) != len(set(files)) or not files:
        raise ValueError('Discovery must contain unique, nonempty test files')
    ordinary, serial, services = [], [], []
    for file in sorted(files):
        relative = file.removeprefix(CONTAINER_ROOT + '/')
        if relative in native:
            serial.append(file)
        elif relative.startswith(('tests/Functional/', 'tests/Integration/')):
            services.append(file)
        else:
            ordinary.append(file)
    lanes = {}
    if ordinary:
        lanes['ordinary'] = dict(files=ordinary, processes=min(workers, len(ordinary)), batch=25,
                                 services=False, privileged=False)
    if serial:
        lanes['native'] = dict(files=serial, processes=1, batch=1, services=False, privileged=True)
    for index in range(min(workers, len(services))):
        lanes[f'stack-{index + 1}'] = dict(files=services[index::min(workers, len(services))],
                                         processes=1, batch=1, services=True, privileged=True)
    assigned = [file for lane in lanes.values() for file in lane['files']]
    if sorted(assigned) != sorted(files):
        raise ValueError('Lane manifests do not partition discovery')
    return lanes


class Runtime:
    def __init__(self, artifacts: Path, timeout: int):
        self.artifacts = artifacts
        self.deadline = time.monotonic() + timeout
        self.stopped = threading.Event()
        self.containers: list[str] = []
        self.networks: list[str] = []
        self.volumes: list[str] = []
        self.projects: list[str] = []
        self.project_environments: dict[str, dict[str, str]] = {}
        self.project_containers: set[str] = set()
        self.project_networks: set[str] = set()
        self.environment = dict(os.environ)
        self.prefix = 'baander-tests-' + uuid.uuid4().hex[:12]
        self.image = os.environ.get('BAANDER_TEST_IMAGE', 'martinjuul/baander-app:latest')
        self.archive: Path | None = None

    def command(self, args: list[str], log: Path | None = None, check: bool = True,
                env: dict[str, str] | None = None) -> subprocess.CompletedProcess:
        if self.stopped.is_set():
            raise RuntimeError('Backend run interrupted')
        if time.monotonic() >= self.deadline:
            raise TimeoutError('Backend overall timeout expired')
        destination = log.open('ab') if log else tempfile.TemporaryFile()
        try:
            with subprocess.Popen(args, stdout=destination, stderr=subprocess.STDOUT,
                                  start_new_session=True, env=env) as process:
                while process.poll() is None:
                    if self.stopped.is_set() or time.monotonic() >= self.deadline:
                        os.killpg(process.pid, signal.SIGKILL)
                        process.wait()
                        self.stopped.set()
                        raise TimeoutError('Backend run interrupted or overall timeout expired')
                    self.stopped.wait(0.1)
                destination.flush()
                if log:
                    output = ''
                else:
                    destination.seek(0)
                    output = destination.read().decode(errors='replace')
                result = subprocess.CompletedProcess(args, process.returncode, output)
                if check and result.returncode:
                    detail = str(log) if log else output[-4000:]
                    raise RuntimeError(f'Command failed ({result.returncode}): {args[0:3]}\n{detail}')
                return result
        finally:
            destination.close()

    def docker(self, *args: str, **kwargs):
        return self.command(['docker', *args], **kwargs)

    def compose_args(self, project: str, *args: str) -> list[str]:
        return ['docker', 'compose', '--project-name', project, '--project-directory', str(ROOT),
                '--file', str(ROOT / 'compose.test.yaml'), *args]

    def compose(self, project: str, *args: str, **kwargs):
        if project not in self.projects:
            raise ValueError('Unregistered Compose test project: ' + project)
        return self.command(self.compose_args(project, *args), env=self.project_environments[project], **kwargs)

    def service_container(self, project: str, service: str) -> str:
        identifiers = self.compose(project, 'ps', '--quiet', service).stdout.split()
        if len(identifiers) != 1 or not re.fullmatch(r'[a-f0-9]{12,64}', identifiers[0]):
            raise RuntimeError(f'Expected one running {service} container for Compose project {project}')
        identifier = identifiers[0]
        if identifier not in self.project_containers:
            self.containers.append(identifier)
            self.project_containers.add(identifier)
        return identifier

    def prepare_archive(self):
        if os.environ.get('BAANDER_TEST_CHECKOUT_IN_IMAGE') == '1':
            return
        if not (ROOT / 'vendor/autoload.php').is_file():
            raise RuntimeError('Install Composer dependencies first')
        self.archive = self.artifacts / 'checkout.tar'
        self.command(['tar', '--exclude=config/secrets/oauth', '--exclude=config/secrets/*.decrypt.private.php',
                      '--exclude=node_modules', '--exclude=.phpunit.cache', '--exclude=__pycache__',
                      '-cf', str(self.archive), '-C', str(ROOT), *ARCHIVE_PATHS])

    def create_app(self, lane: str, network: str = 'none', privileged: bool = False,
                   services: bool = False, coverage: bool = False) -> str:
        if services:
            if network not in self.projects:
                raise ValueError('Service app requires a registered Compose project')
            self.project_environments[network]['BAANDER_TEST_XDEBUG_MODE'] = 'coverage' if coverage else 'off'
            self.project_environments[network]['BAANDER_TEST_APP_PRIVILEGED'] = 'true' if privileged else 'false'
            self.compose(network, 'up', '--detach', '--wait', '--wait-timeout', '60', 'app')
            name = self.service_container(network, 'app')
        else:
            name = self.prefix + '-' + lane
            self.containers.append(name)
            args = ['create', '--user', '0:0', '--name', name, '--network', network, '--workdir', CONTAINER_ROOT,
                    '--entrypoint', 'sh', '-e', 'APP_ENV=test', '-e', 'APP_DEBUG=0',
                    '-e', 'XDEBUG_MODE=' + ('coverage' if coverage else 'off')]
            if privileged:
                args.append('--privileged')
            self.docker(*args, self.image, '-c', 'exec sleep infinity')
            self.docker('start', name)
        if self.archive:
            self.docker('cp', str(self.archive), name + ':/tmp/checkout.tar')
            self.docker('exec', name, 'sh', '-ec',
                        'rm -rf /var/www/html; mkdir -p /var/www/html; tar -xf /tmp/checkout.tar -C /var/www/html; rm /tmp/checkout.tar; chown -R www-data:www-data /var/www/html')
        self.docker('exec', name, 'sh', '-ec', 'mkdir -p ' + RUN_DIRECTORY + '; chown www-data:www-data ' + RUN_DIRECTORY)
        return name

    def start_services(self, lane: str) -> str:
        project = self.prefix + '-' + lane
        if project in self.projects:
            raise ValueError('Compose test project already registered: ' + project)
        self.projects.append(project)
        self.project_environments[project] = {
            **self.environment,
            'BAANDER_TEST_IMAGE': self.image,
            'BAANDER_TEST_POSTGRES_IMAGE': self.environment.get('BAANDER_TEST_POSTGRES_IMAGE', 'baander-database:latest'),
            'BAANDER_TEST_REDIS_IMAGE': self.environment.get('BAANDER_TEST_REDIS_IMAGE', 'redis/redis-stack-server:edge'),
            'BAANDER_TEST_XDEBUG_MODE': 'off',
            'BAANDER_TEST_APP_PRIVILEGED': 'true',
        }
        network = project + '_private'
        self.networks.append(network)
        self.project_networks.add(network)
        self.volumes.extend([project + '_postgres_data', project + '_redis_data'])
        self.compose(project, 'up', '--detach', '--wait', '--wait-timeout', '60', 'postgres', 'redis')
        self.service_container(project, 'postgres')
        self.service_container(project, 'redis')
        return project

    def helper(self, app: str, *args: str, log: Path | None = None, check: bool = True):
        self.docker('exec', app, 'chown', '-R', 'www-data:www-data', RUN_DIRECTORY)
        return self.docker('exec', '--user', 'www-data', app, 'php', '-d', 'memory_limit=2G',
                           'scripts/backend-test-runner.php', *args, log=log, check=check)

    def run_lane(self, lane: str, spec: dict, run: Path, coverage: bool):
        started = time.monotonic()
        network = self.start_services(lane) if spec['services'] else 'none'
        app = self.create_app(lane, network, spec['privileged'], spec['services'], coverage)
        log = self.artifacts / (lane + '.log')
        self.docker('cp', str(run) + '/.', app + ':' + RUN_DIRECTORY)
        try:
            if spec['services']:
                self.docker('exec', '--user', 'www-data', app, 'sh', '-ec', '''
                    mkdir -p /tmp/baander-oauth
                    umask 077
                    openssl genrsa -out "$OAUTH_PRIVATE_KEY_PATH" 2048
                    openssl rsa -in "$OAUTH_PRIVATE_KEY_PATH" -pubout -out "$OAUTH_PUBLIC_KEY_PATH"
                    php -d memory_limit=512M bin/console cache:clear --env=test --no-debug
                    php -d memory_limit=512M bin/console doctrine:migrations:migrate --no-interaction --env=test
                    php -d memory_limit=512M bin/console doctrine:migrations:migrate --no-interaction --env=test
                ''', log=log)
            if spec.get('engine') == 'reference':
                self.docker('exec', app, 'chown', '-R', 'www-data:www-data', RUN_DIRECTORY)
                result = self.docker('exec', '--user', 'www-data', app, 'php', '-d', 'memory_limit=2G',
                                     'scripts/backend-test-reference.php', RUN_DIRECTORY, lane, log=log, check=False)
            else:
                result = self.helper(app, 'run', RUN_DIRECTORY, lane, log=log, check=False)
            print(f'{lane}: exit {result.returncode}, {time.monotonic() - started:.1f}s; {log}', flush=True)
            return result.returncode
        finally:
            # Preserve incomplete/crashed runs as well as successful reports.
            destination = self.artifacts / lane
            destination.mkdir(exist_ok=True)
            try:
                subprocess.run(['docker', 'cp', app + ':' + RUN_DIRECTORY + '/.', str(destination)],
                               stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=30)
            except (OSError, subprocess.TimeoutExpired) as error:
                print(f'Artifact collection failed for {lane}: {error}', file=sys.stderr)
            for path in destination.glob(lane + '.*'):
                if path.is_file():
                    shutil.copy2(path, run / path.name)

    def cleanup(self) -> bool:
        success = True
        # Cleanup has an independent bound: it must run even after the run deadline.
        for project in reversed(self.projects):
            try:
                with (self.artifacts / (project + '.compose.log')).open('wb') as log:
                    subprocess.run(self.compose_args(project, 'logs', '--no-color'),
                                   env=self.project_environments[project], stdout=log,
                                   stderr=subprocess.STDOUT, timeout=10)
            except (OSError, subprocess.TimeoutExpired) as error:
                print(f'Log collection failed for {project}: {error}', file=sys.stderr)
            try:
                result = subprocess.run(self.compose_args(project, 'down', '--volumes', '--remove-orphans', '--timeout', '5'),
                                        env=self.project_environments[project], stdout=subprocess.DEVNULL,
                                        stderr=subprocess.DEVNULL, timeout=30)
                if result.returncode != 0:
                    success = False
                    print(f'Cleanup failed for Compose project {project}: exit {result.returncode}', file=sys.stderr)
            except (OSError, subprocess.TimeoutExpired) as error:
                success = False
                print(f'Cleanup failed for Compose project {project}: {error}', file=sys.stderr)
        for name in reversed(self.containers):
            if name in self.project_containers:
                continue
            try:
                with (self.artifacts / (name + '.container.log')).open('wb') as log:
                    subprocess.run(['docker', 'logs', name], stdout=log, stderr=subprocess.STDOUT, timeout=10)
            except (OSError, subprocess.TimeoutExpired) as error:
                print(f'Log collection failed for {name}: {error}', file=sys.stderr)
            try:
                result = subprocess.run(['docker', 'rm', '-f', name], stdout=subprocess.DEVNULL,
                               stderr=subprocess.DEVNULL, timeout=20)
                if result.returncode != 0:
                    success = False
                    print(f'Cleanup failed for {name}: exit {result.returncode}', file=sys.stderr)
            except (OSError, subprocess.TimeoutExpired) as error:
                success = False
                print(f'Cleanup failed for {name}: {error}', file=sys.stderr)
        for name in reversed(self.networks):
            if name in self.project_networks:
                continue
            try:
                result = subprocess.run(['docker', 'network', 'rm', name], stdout=subprocess.DEVNULL,
                               stderr=subprocess.DEVNULL, timeout=20)
                if result.returncode != 0:
                    success = False
                    print(f'Cleanup failed for {name}: exit {result.returncode}', file=sys.stderr)
            except (OSError, subprocess.TimeoutExpired) as error:
                success = False
                print(f'Cleanup failed for {name}: {error}', file=sys.stderr)
        if self.archive:
            self.archive.unlink(missing_ok=True)
        return success


def main(arguments: list[str]) -> int:
    started = time.monotonic()
    workers = positive_integer(os.environ.get('BAANDER_TEST_PROCESSES', '2'), 'BAANDER_TEST_PROCESSES', 8)
    timeout = positive_integer(os.environ.get('BAANDER_TEST_TIMEOUT', '3600'), 'BAANDER_TEST_TIMEOUT')
    artifacts = Path(os.environ.get('BAANDER_TEST_ARTIFACTS', str(ROOT / 'var/test-runs'))).resolve()
    artifacts = artifacts / (time.strftime('%Y%m%d-%H%M%S') + '-' + uuid.uuid4().hex[:8])
    artifacts.mkdir(parents=True)
    print(f'Backend artifacts: {artifacts}', flush=True)
    runtime = Runtime(artifacts, timeout)
    previous = {}
    for sig in (signal.SIGINT, signal.SIGTERM):
        previous[sig] = signal.signal(sig, lambda *_: runtime.stopped.set())
    status = 1
    try:
        runtime.prepare_archive()
        discovery = runtime.create_app('discovery')
        runtime.helper(discovery, 'discover', RUN_DIRECTORY, '--no-progress', '--colors=never',
                       '--display-all-issues', '--fail-on-phpunit-notice', '--fail-on-skipped', *arguments, log=artifacts / 'discovery.log')
        run = artifacts / 'run'
        run.mkdir()
        runtime.docker('cp', discovery + ':' + RUN_DIRECTORY + '/.', str(run))
        selected = json.loads((run / 'discovery.json').read_text())
        native = json.loads((ROOT / 'scripts/backend-native-tests.json').read_text())
        lanes = partition(list(selected['files']), native, workers)
        if os.environ.get('BAANDER_TEST_REFERENCE') == '1':
            lanes = {'reference': dict(files=list(selected['files']), processes=1, batch=25,
                                       services=True, privileged=True, engine='reference')}
        for lane, spec in lanes.items():
            (run / (lane + '.json')).write_text(json.dumps(spec, indent=2) + '\n')
        print(f"Selected {sum(map(len, selected['files'].values()))} tests in {len(selected['files'])} files; "
              f'{len(lanes)} lanes, {workers} workers', flush=True)
        statuses = []
        for lane in ('ordinary', 'native'):
            if lane in lanes:
                statuses.append(runtime.run_lane(lane, lanes[lane], run, selected['coverage']))
        with concurrent.futures.ThreadPoolExecutor(max_workers=workers) as executor:
            futures = [executor.submit(runtime.run_lane, lane, spec, run, selected['coverage'])
                       for lane, spec in lanes.items() if spec['services']]
            for future in concurrent.futures.as_completed(futures):
                statuses.append(future.result())
        runtime.docker('cp', str(run) + '/.', discovery + ':' + RUN_DIRECTORY)
        result = runtime.helper(discovery, 'finalize', RUN_DIRECTORY, log=artifacts / 'finalize.log', check=False)
        runtime.docker('cp', discovery + ':' + RUN_DIRECTORY + '/.', str(run))
        if result.returncode or any(statuses):
            raise RuntimeError('Backend validation failed; inspect lane and finalize logs')
        for option, target in (selected.get('reports') or {}).items():
            destination = Path(target)
            if not destination.is_absolute():
                destination = ROOT / destination
            destination.parent.mkdir(parents=True, exist_ok=True)
            staging = destination.with_name(destination.name + '.' + runtime.prefix)
            runtime.docker('cp', discovery + ':' + (target if target.startswith('/') else CONTAINER_ROOT + '/' + target),
                           str(staging))
            report_directory = artifacts / 'reports'
            report_directory.mkdir(exist_ok=True)
            shutil.copy2(staging, report_directory / ('report.txt' if option == '--coverage-text' else 'clover.xml'))
            os.replace(staging, destination)
        status = 0
    except (RuntimeError, TimeoutError, OSError, ValueError) as error:
        print(str(error), file=sys.stderr)
    finally:
        if not runtime.cleanup():
            status = 1
        for sig, handler in previous.items():
            signal.signal(sig, handler)
        elapsed = time.monotonic() - started
        (artifacts / 'timing.json').write_text(json.dumps({'status': status, 'elapsed': elapsed, 'workers': workers}) + '\n')
        print(f'Backend exit {status}; elapsed {elapsed:.1f}s; artifacts {artifacts}', flush=True)
    return status


if __name__ == '__main__':
    try:
        sys.exit(main(sys.argv[1:]))
    except ValueError as error:
        print(str(error), file=sys.stderr)
        sys.exit(2)
