#!/usr/bin/env python3
"""Qualify the real development controller on explicitly disposable resources.

The workspace and external state root are retained, including private logs, after
failure. No existing checkout, instance, media mount, or Docker volume is adopted.
"""
from __future__ import annotations

import argparse
import hashlib
import ipaddress
import json
import os
import shutil
import shlex
from pathlib import Path
import subprocess
import sys
import time

from dev_environment.database import Database
from dev_environment.model import StateStore, preview_id
from dev_environment.process import Docker, Runner
from dev_environment.runtime import Runtime


class Qualification:
    def __init__(self, args):
        self.args = args
        self.source = Path(__file__).resolve().parents[1]
        self.workspace = args.workspace.resolve()
        self.store = StateStore(args.state_root.resolve())
        self.checkout = self.workspace / 'checkout'
        self.remote = self.workspace / 'origin.git'
        self.media = self.workspace / 'media'
        self.logs = self.workspace / 'logs'
        self.results = []
        self.sequence = 0
        self.baseline = None
        self.database = Database(self.store, Runner())

    def command(self, argv, *, cwd=None, wait=True, expected=0):
        self.sequence += 1
        log = self.logs / f'{self.sequence:03d}-{Path(str(argv[0])).name}.log'
        with log.open('w') as output:
            process = subprocess.Popen([str(a) for a in argv], cwd=cwd or self.checkout,
                                       stdout=output, stderr=subprocess.STDOUT)
        if not wait:
            return process
        code = process.wait()
        if code != expected:
            raise AssertionError(f'Command returned {code}, expected {expected}; inspect {log}')
        return log.read_text()

    def cli(self, command, *args, checkout=None, **kwargs):
        return self.command([sys.executable, self.source / 'scripts/dev-environment.py',
                             '--state-root', self.store.root, command, *args],
                            cwd=checkout or self.checkout, **kwargs)

    def state(self, identity='persistent'):
        return self.store.load(identity) or {}

    def poll(self, predicate, description, *, timeout=None):
        deadline = time.monotonic() + (timeout or self.args.timeout)
        while time.monotonic() < deadline:
            if predicate():
                return
            time.sleep(0.5)
        raise AssertionError(f'Timed out waiting for {description}; inspect {self.store.root}')

    def ready(self, revision, identity='persistent'):
        self.poll(lambda: self.state(identity).get('running_revision') == revision
                  and self.state(identity).get('phase') == 'ready'
                  and (self.state(identity).get('readiness') or {}).get('workers_ready'),
                  f'{identity} ready at {revision}')
        self.origin(identity)
        instance = self.state(identity)
        docker = Docker(Runner(), instance['docker'])
        identifier = docker.compose(instance, 'ps', '-q', 'app').stdout.strip()
        actual = docker.run('inspect', '--format', '{{.Image}}', identifier).stdout.strip()
        assert actual == instance['release']['app_image'], 'App did not run the prepared immutable image'
        if identity == 'persistent':
            label = docker.run('image', 'inspect', '--format',
                               '{{index .Config.Labels "org.opencontainers.image.revision"}}', actual).stdout.strip()
            assert label == revision, 'Serving image label does not match selected master SHA'

    def origin(self, identity='persistent'):
        self.command(['curl', '--fail', '--silent', '--show-error', '--max-time', '20',
                      'https://' + self.state(identity)['hostname'] + '/ready'])

    def sql(self, sql, identity='persistent'):
        return self.database.sql(self.state(identity), sql)

    def fingerprint(self, identity='persistent'):
        # Runtime activity may change timestamps and scan counters; stable identity
        # and configured paths are the preservation contract.
        data = self.sql("BEGIN READ ONLY; SELECT json_build_object('users',"
                        "(SELECT md5(coalesce(json_agg(row(id,email,password) ORDER BY id)::text,'')) FROM users),"
                        "'libraries',(SELECT md5(coalesce(json_agg(row(id,slug,path) ORDER BY id)::text,'')) FROM libraries),"
                        "'clients',(SELECT md5(coalesce(json_agg(row(id,public_id) ORDER BY id)::text,'')) FROM oauth_clients)); COMMIT;", identity)
        data['environment'] = hashlib.sha256((self.store.directory(identity) / 'env.json').read_bytes()).hexdigest()
        return data

    def record(self, scenario, **evidence):
        self.results.append(dict(scenario=scenario, **evidence))
        (self.workspace / 'evidence.json').write_text(json.dumps(self.results, indent=2) + '\n')
        print('PASS: ' + scenario, flush=True)

    def media_hashes(self):
        return {str(path.relative_to(self.media)): hashlib.sha256(path.read_bytes()).hexdigest()
                for path in self.media.rglob('*') if path.is_file()}

    def inventory(self):
        inventory = {}
        for kind, arguments in {'containers': ['ps', '-aq', '--no-trunc'],
                                'networks': ['network', 'ls', '-q', '--no-trunc'],
                                'volumes': ['volume', 'ls', '-q']}.items():
            result = subprocess.run(['docker', '--context', 'default', *arguments],
                                    check=True, text=True, capture_output=True)
            inventory[kind] = sorted(result.stdout.split())
        return inventory

    def preserve_failure(self):
        if not (self.workspace / 'disposable-qualification').exists():
            return
        if self.store.load('persistent'):
            self.store.set_enabled('persistent', False)
            subprocess.run(['systemctl', '--user', 'disable', '--now',
                            'baander-development-' + self.store.scope + '.timer'],
                           stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        prefix = [sys.executable, str(self.source / 'scripts/dev-environment.py'),
                  '--state-root', str(self.store.root)]
        context = dict(workspace=str(self.workspace), state_root=str(self.store.root),
                       checkout=str(self.checkout), timer_disabled=True,
                       status=shlex.join(prefix + ['status', '--instance', 'persistent', '--json']),
                       resume=shlex.join(prefix + ['up', '--instance', 'persistent']),
                       recovery=shlex.join(prefix + ['recover', '--instance', 'persistent']))
        (self.workspace / 'failure-context.json').write_text(json.dumps(context, indent=2) + '\n')
        print('Scoped timer disabled; in-flight work and owned resources retained.\n'
              'Inspect from ' + str(self.checkout) + ': ' + context['status'] + '\n'
              'Resume after diagnosis: ' + context['resume'] + '\n'
              'Blocked mutation requires explicit reconciliation before: ' + context['recovery'], file=sys.stderr)

    def cleanup(self):
        instances = [json.loads(path.read_text()) for path in self.store.root.glob('*/state.json')
                     if path.parent.name == 'persistent' or path.parent.name.startswith('preview-')]
        projects = {instance['project'] for instance in instances}
        expected_volumes = set()
        expected_networks = {}
        for instance in instances:
            assert instance['repository'] == str(self.remote)
            docker = Docker(Runner(), instance['docker'])
            docker.validate()
            self.cli('down', '--instance', instance['id'])
            compose = json.loads(Path(instance['compose_file']).read_text())
            expected_volumes.update(volume['name'] for volume in compose['volumes'].values())
            for path in self.store.directory(instance['id']).glob('workers/*/manifest.json'):
                manifest = json.loads(path.read_text())
                assert manifest['namespace'] == instance['project']
                expected_volumes.add(manifest['ipcVolume'])
            docker.compose(instance, 'down', '--remove-orphans')
            network = instance['network']
            expected_networks[network['name'] if isinstance(network, dict) else network] = instance['id']
        gateway = json.loads((self.store.root / 'gateway/state.json').read_text())
        docker = Docker(Runner(), gateway['docker'])
        docker.compose(gateway, 'down', '--remove-orphans')
        projects.add(gateway['project'])
        expected_networks[gateway['gateway']['name']] = 'gateway-' + self.store.scope
        # Retired worker containers remain as evidence until all scenarios pass.
        # Remove only containers with an exact saved namespace from this run.
        for project in projects:
            ids = docker.run('ps', '-aq', '--no-trunc', '--filter',
                             'label=app.baander.worker.namespace=' + project).stdout.split()
            for identifier in ids:
                assert identifier not in self.baseline['containers']
                docker.run('rm', identifier)
        for name in sorted(expected_volumes):
            assert name not in self.baseline['volumes'] and any(name.startswith(project + '-') for project in projects)
            if docker.run('volume', 'inspect', name, check=False).returncode == 0:
                docker.run('volume', 'rm', name)
        for name, owner in expected_networks.items():
            observed = json.loads(docker.run('network', 'inspect', name).stdout)[0]
            assert observed['Id'] not in self.baseline['networks']
            assert observed.get('Labels', {}).get('app.baander.development.owner') == owner
            docker.run('network', 'rm', name)
        unit = 'baander-development-' + self.store.scope
        self.command(['systemctl', '--user', 'disable', '--now', unit + '.timer'])
        config = Path(os.environ.get('XDG_CONFIG_HOME', str(Path.home() / '.config'))) / 'systemd/user'
        for suffix in ('.service', '.timer'):
            (config / (unit + suffix)).unlink(missing_ok=True)
        self.command(['systemctl', '--user', 'daemon-reload'])
        remaining = self.inventory()
        for kind in self.baseline:
            assert set(self.baseline[kind]).issubset(remaining[kind]), f'Unrelated Docker {kind} changed'
        for instance in instances:
            assert not docker.run('ps', '-aq', '--filter',
                                  'label=com.docker.compose.project=' + instance['project']).stdout.strip()
            assert not docker.run('ps', '-aq', '--filter',
                                  'label=app.baander.worker.namespace=' + instance['project']).stdout.strip()
        self.record('owned instances, workers, gateway, volumes, networks and timer cleaned; unrelated inventories preserved')

    def commit(self, label, *, delay=0, broken_build=False):
        dockerfile = self.checkout / 'Dockerfile'
        content = self.original_dockerfile
        if delay:
            content = content.replace('FROM node:22-bookworm-slim AS dev-tools',
                f'FROM node:22-bookworm-slim AS dev-tools\nRUN sleep {delay} && echo {label}')
        if broken_build:
            content = content.replace('FROM node:22-bookworm-slim AS dev-tools',
                'FROM node:22-bookworm-slim AS dev-tools\nRUN exit 73')
        dockerfile.write_text(content)
        (self.checkout / 'public/workflow-qualification.txt').write_text(label + '\n')
        self.command(['git', 'add', 'Dockerfile', 'public/workflow-qualification.txt'])
        self.command(['git', 'commit', '-m', 'Qualify ' + label])
        revision = self.command(['git', 'rev-parse', 'HEAD']).strip()
        self.command(['git', 'push', 'origin', 'HEAD:master'])
        return revision

    def seed(self):
        if self.store.root == self.args.host_state_root.resolve():
            raise AssertionError('Qualification state must be separate from the existing trusted host state.')
        if self.workspace.exists() or self.store.load('persistent'):
            raise AssertionError('Select a new workspace and dedicated external state root without instances.')
        if self.store.root.exists() and any(path.name not in {'gateway', 'host-setup'} for path in self.store.root.iterdir()):
            raise AssertionError('Qualification state may contain only preinstalled host certificates and instructions.')
        if (self.store.root / 'gateway/state.json').exists():
            raise AssertionError('Qualification gateway must not already own runtime resources.')
        if self.store.root.is_relative_to(self.workspace) or self.workspace.is_relative_to(self.source):
            raise AssertionError('Workspace must be outside the checkout; state root must be outside workspace.')
        self.logs.mkdir(parents=True, mode=0o700)
        self.workspace.chmod(0o700)
        (self.workspace / 'disposable-qualification').write_text('Owned workflow qualification resources only.\n')
        self.baseline = self.inventory()
        (self.workspace / 'docker-inventory-before.json').write_text(json.dumps(self.baseline, indent=2) + '\n')
        self.media.mkdir()
        self.command(['ffmpeg', '-hide_banner', '-loglevel', 'error', '-f', 'lavfi',
                      '-i', 'sine=frequency=440:sample_rate=48000:duration=8', '-metadata', 'title=Workflow qualification tone',
                      '-metadata', 'artist=Baander qualification', '-metadata', 'album=Development workflow',
                      '-c:a', 'flac', self.media / 'workflow-qualification-tone.flac'], cwd=self.workspace)
        self.seed_media_hashes = self.media_hashes()
        self.command(['git', 'clone', '--bare', '--no-hardlinks', self.source, self.remote], cwd=self.workspace)
        self.command(['git', 'clone', self.remote, self.checkout], cwd=self.workspace)
        self.command(['git', 'checkout', '-B', 'master'])
        self.command(['git', 'config', 'user.name', 'Baander Workflow Qualification'])
        self.command(['git', 'config', 'user.email', 'workflow@baander.app'])
        self.command(['git', 'push', '--force', 'origin', 'HEAD:master'])
        self.original_dockerfile = (self.checkout / 'Dockerfile').read_text()
        self.initial = self.command(['git', 'rev-parse', 'HEAD']).strip()
        # Reuse the explicitly trusted host identity, copying only public CA and
        # leaf identity files. The host CA private key remains in its original root.
        for relative in ('gateway/tls/cert.pem', 'gateway/tls/key.pem', 'host-setup/ca/rootCA.pem'):
            source = self.args.host_state_root.resolve() / relative
            target = self.store.root / relative
            if not source.is_file():
                raise AssertionError(f'Complete trusted host setup first; missing {source}')
            target.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
            shutil.copyfile(source, target)
            target.chmod(0o600)
        if self.args.prepare_shared_paths:
            self.command([self.args.prepare_shared_paths, self.workspace, self.store.root])
        else:
            self.cli('host-setup', '--share-path', self.workspace)

    def up(self, *, checkout=None, wait=True):
        arguments = ['--media', str(self.media) + '=/qualification-media']
        if checkout is None:
            arguments += ['--instance', 'persistent']
        return self.cli('up', *arguments, checkout=checkout, wait=wait)

    def run(self):
        self.seed()
        self.up()
        self.ready(self.initial)
        self.cli('exec', '--instance', 'persistent', '--', 'php', 'bin/console',
                 'app:library:create', 'Qualification music', '/qualification-media', 'music',
                 '--slug', 'workflow-qualification', '--no-interaction')
        original = self.fingerprint()
        self.up()
        assert self.fingerprint() == original, 'Repeat startup changed account/library/identity state'
        self.record('fresh and repeat startup retain configured account, library and secrets', revision=self.initial)

        # No update invocation: the installed detached systemd timer owns both attempts.
        first = self.commit('concurrent-first', delay=35)
        self.poll(lambda: self.state().get('target_revision') == first
                  and self.state().get('phase') == 'preparing', 'first timer preparation')
        second = self.commit('concurrent-second')
        self.ready(first)
        self.ready(second)
        assert self.fingerprint() == original
        self.record('user timer serializes successive immutable remote master releases', revisions=[first, second])

        bad = self.commit('broken-build', broken_build=True)
        self.poll(lambda: self.state().get('failed_revision') == bad, 'build failure')
        assert self.state()['running_revision'] == second
        self.origin()
        assert self.fingerprint() == original
        self.record('build failure preserves serving release and existing state', failed_revision=bad)
        good = self.commit('after-build-failure')
        self.ready(good)

        migration = sorted((self.checkout / 'migrations').glob('Version*.php'))[0]
        migration.write_text(migration.read_text() + '\n// Disposable incompatible history.\n')
        self.command(['git', 'add', 'migrations'])
        bad_schema = self.commit('incompatible-schema')
        self.poll(lambda: self.state().get('failed_revision') == bad_schema, 'history refusal')
        assert self.state()['running_revision'] == good and self.state()['phase'] == 'ready'
        self.origin()
        assert self.fingerprint() == original
        self.record('incompatible migration history refuses before data mutation', failed_revision=bad_schema)
        self.command(['git', 'checkout', self.initial, '--', 'migrations'])
        self.command(['git', 'add', 'migrations'])
        good = self.commit('after-schema-refusal')
        self.ready(good)

        previews = []
        for number in (1, 2):
            checkout = self.workspace / f'preview-{number}'
            self.command(['git', 'clone', self.remote, checkout])
            self.command(['git', 'checkout', 'master'], cwd=checkout)
            identity = preview_id(checkout, str(self.remote))
            self.up(checkout=checkout)
            assert self.state(identity)['phase'] == 'ready'
            self.origin(identity)
            previews.append((checkout, identity))
        assert len({self.state(i)['project'] for _, i in previews} | {self.state()['project']}) == 3
        before = {i: self.fingerprint(i) for _, i in previews}
        checkout, identity = previews[0]
        self.cli('exec', '--', 'php', 'bin/console', 'app:library:create', 'Isolated qualification',
                 '/qualification-media', 'music', '--slug', 'isolated-qualification', '--no-interaction', checkout=checkout)
        self.cli('exec', '--', 'php', 'bin/console', 'app:library:scan', 'isolated-qualification',
                 '--no-interaction', checkout=checkout)
        assert self.fingerprint(previews[1][1]) == before[previews[1][1]] and self.fingerprint() == original
        retained = self.fingerprint(identity)
        self.cli('down', checkout=checkout)
        self.origin(previews[1][1])
        self.origin()
        self.up(checkout=checkout)
        assert self.fingerprint(identity) == retained
        assert self.media_hashes() == self.seed_media_hashes
        self.record('two simultaneous previews isolate scans, accounts, resources and retained resume state',
                    previews=[i for _, i in previews])

        if self.args.media_check:
            self.command([sys.executable, self.args.media_check, '--state-root', self.store.root,
                          '--workspace', self.workspace, '--instance', 'persistent'])
            self.record('queued ingestion and original plus transcoded playback')
            original = self.fingerprint()
        else:
            raise AssertionError('Full qualification requires --media-check helper; playback cannot be skipped')
        original_media = self.media_hashes()
        if self.args.browser_check:
            self.cli('exec', '--', 'php', 'bin/console', 'app:settings:set',
                     'transcode.enabled', 'true', '--no-interaction', checkout=previews[0][0])
            for identity, name in ((previews[0][1], 'preview'), ('persistent', 'persistent')):
                arguments = ['node', self.args.browser_check, '--url', 'https://' + self.state(identity)['hostname'],
                             '--output', self.workspace / (name + '-browser-evidence.json')]
                if identity != 'persistent':
                    arguments.append('--preview')
                self.command(arguments)
            self.record('trusted HTTPS browser login and media playback on persistent instance and isolated preview')

        target = self.commit('down-during-preparation', delay=35)
        self.poll(lambda: self.state().get('target_revision') == target
                  and self.state().get('phase') == 'preparing', 'preparation before down')
        self.cli('down', '--instance', 'persistent')
        assert self.state()['phase'] == 'stopped' and not self.store.enabled('persistent')
        target = self.commit('master-advances-while-stopped')
        # A service tick already queued when the timer was disabled must obey
        # the persisted stop intent too.
        self.command(['systemctl', '--user', 'start',
                      'baander-development-' + self.store.scope + '.service'])
        assert self.state()['phase'] == 'stopped'
        # Two complete timer intervals, without manually invoking the updater.
        deadline = time.monotonic() + 125
        while time.monotonic() < deadline:
            assert self.state()['phase'] == 'stopped' and not self.store.enabled('persistent')
            time.sleep(1)
        self.up()
        self.ready(target)
        assert self.fingerprint() == original
        assert self.media_hashes() == original_media
        self.record('down during preparation cancels activation; timer stays stopped; up retains state', revision=target)
        self.mutation_failure(original)
        assert self.media_hashes() == original_media
        self.record('original source media hashes preserved', files=original_media)
        self.cleanup()
        self.record('qualification complete')

    def mutation_failure(self, original):
        instance = self.state()
        docker = Docker(Runner(), instance['docker'])
        container = docker.compose(instance, 'ps', '-q', 'postgres').stdout.strip()
        network = json.loads(docker.run('inspect', container).stdout)[0]['NetworkSettings']['Networks']
        address = str(ipaddress.ip_address(network[Runtime.network_name(instance)]['IPAddress']))
        # The migration's isolated rehearsal runs on a fresh private network. A
        # live-address condition produces a real committed statement followed by
        # failure only on the owned live database; controller services stay real.
        migration = self.checkout / 'migrations/Version20990101000000.php'
        migration.write_text('''<?php
declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\\DBAL\\Schema\\Schema;
use Doctrine\\Migrations\\AbstractMigration;
final class Version20990101000000 extends AbstractMigration
{
    public function isTransactional(): bool { return false; }
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE workflow_qualification_committed (id integer PRIMARY KEY)');
        $this->addSql('INSERT INTO workflow_qualification_committed VALUES (1)');
        $this->addSql("SELECT pg_sleep(CASE WHEN host(inet_server_addr()) = 'ADDRESS' THEN 35 ELSE 0 END)");
        $this->addSql("DO \\$\\$ BEGIN IF host(inet_server_addr()) = 'ADDRESS' THEN RAISE EXCEPTION 'Owned qualification failure after commit'; END IF; END \\$\\$");
    }
    public function down(Schema $schema): void { $this->throwIrreversibleMigrationException(); }
}
'''.replace('ADDRESS', address))
        self.command(['git', 'add', 'migrations'])
        target = self.commit('partial-mutation-failure')
        self.poll(lambda: self.state().get('target_revision') == target
                  and self.state().get('phase') == 'database_mutating', 'live migration')
        self.poll(lambda: self.sql("SELECT json_build_object('committed',to_regclass('workflow_qualification_committed') IS NOT NULL);")['committed'],
                  'committed live mutation')
        down = self.cli('down', '--instance', 'persistent', wait=False)
        self.poll(lambda: not self.store.enabled('persistent'), 'down intent during migration')
        assert down.poll() is None, 'Down killed or bypassed the active migration'
        assert down.wait(timeout=300) == 0, 'Down failed after migration finished'
        current = self.state()
        assert current['phase'] == 'blocked' and not current.get('running_revision') and not current.get('readiness')
        assert current.get('checkpoint', {}).get('verified') is True
        # Down removed the runtime; restart only its database to inspect retained
        # evidence. No application or worker is restarted after the failed migration.
        Docker(Runner(), current['docker']).compose(current, 'up', '-d', '--wait', 'postgres')
        assert self.sql("SELECT json_build_object('rows',count(*)) FROM workflow_qualification_committed;")['rows'] == 1
        assert self.fingerprint() == original
        self.cli('recover', '--instance', 'persistent', expected=1)
        assert self.state()['phase'] == 'blocked'
        self.record('down waits for live migration; partial commit blocks readiness and unsafe recovery',
                    failed_revision=target, checkpoint=current['checkpoint']['directory'])


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--workspace', type=Path, required=True)
    parser.add_argument('--state-root', type=Path, required=True)
    parser.add_argument('--host-state-root', type=Path, default=StateStore.default().root)
    parser.add_argument('--prepare-shared-paths', type=Path,
                        help='Explicit host preflight executable, passed only the owned workspace and state paths')
    parser.add_argument('--media-check', type=Path,
                        default=Path(__file__).with_name('dev_environment_media_probe.py'))
    parser.add_argument('--browser-check', type=Path,
                        default=Path(__file__).with_name('dev_environment_browser_probe.mjs'))
    parser.add_argument('--timeout', type=int, default=7200)
    args = parser.parse_args()
    qualification = Qualification(args)
    try:
        qualification.run()
    except (AssertionError, OSError, RuntimeError, subprocess.SubprocessError) as error:
        qualification.preserve_failure()
        print(f'FAIL: {error}\nRetained evidence: {qualification.workspace}', file=sys.stderr)
        return 1
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
