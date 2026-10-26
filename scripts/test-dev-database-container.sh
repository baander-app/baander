#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
# Every container, volume and network belongs to this disposable drill.
export BAANDER_DATABASE_DRILL_ID="baander-dev-database-$(date +%s)-$$"
export BAANDER_DATABASE_DRILL_IMAGE="${BAANDER_DATABASE_TEST_IMAGE:-baander-database:latest}"
export BAANDER_DATABASE_DRILL_APP="${BAANDER_DATABASE_APP_IMAGE:-${BAANDER_TEST_IMAGE:-baander-development-app:qualification}}"
export BAANDER_DATABASE_DRILL_REDIS="${BAANDER_DATABASE_REDIS_IMAGE:-redis/redis-stack-server:edge}"
python3 - <<'PY'
import hashlib
import json
import os
from pathlib import Path
import sys
import tarfile
import tempfile

sys.path.insert(0, str(Path.cwd() / 'scripts'))
from dev_environment.bootstrap import Bootstrap
from dev_environment.database import CATALOG_SQL, Database
from dev_environment.model import StateStore, WorkflowError, atomic_json
from dev_environment.process import Docker, Runner
from dev_environment.runtime import Runtime

name = os.environ['BAANDER_DATABASE_DRILL_ID']
class DrillRunner(Runner):
    private_values = []
    expected_versions = []
    rehearsal_versions = []

    def run(self, arguments, **options):
        check = options.pop('check', True)
        result = super().run(arguments, check=False, **options)
        if check and result.returncode:
            diagnostic = (result.stdout + '\n' + result.stderr)[-20000:]
            for value in self.private_values:
                diagnostic = diagnostic.replace(value, '[redacted]')
            log = Path(tempfile.gettempdir()) / 'baander-dev-database-failure.log'
            log.write_text(diagnostic)
            log.chmod(0o600)
            raise WorkflowError('Disposable database drill command failed; sanitized diagnostic: ' + str(log))
        if result.returncode == 0 and 'start' in arguments and '--attach' in arguments:
            candidate = arguments[-1]
            if candidate.startswith('baander-rehearsal-') and '-app-' in candidate:
                restored_database = candidate.rsplit('-app-', 1)[0]
                versions = sql(restored_database, 'SELECT version FROM doctrine_migration_versions ORDER BY version').splitlines()
                assert versions == self.expected_versions, 'target migration was not applied in the isolated rehearsal'
                self.rehearsal_versions.append(versions)
        return result


runner = DrillRunner()
context = runner.run(['docker', 'context', 'show']).stdout.strip()
configuration = {'executable': 'docker', 'context': context,
                 'daemon_id': json.loads(runner.run(['docker', '--context', context, 'info', '--format', '{{json .}}']).stdout)['ID']}
docker = Docker(runner, configuration)
containers = []
volumes = []


def sql(container, statement):
    return docker.run('exec', '-i', container, '/bin/sh', '-ec',
        'export PGPASSWORD="$POSTGRES_PASSWORD"; exec psql -X -qAt --set=ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d "$POSTGRES_DB"',
        input=statement).stdout.strip()


def redis(container, *arguments):
    return docker.run('exec', container, '/bin/sh', '-ec',
        'export REDISCLI_AUTH="$REDIS_PASSWORD"; exec redis-cli --raw "$@"', 'redis-check', *arguments).stdout.strip()


class VerifiedDatabase(Database):
    """Observe the real rehearsal database before the helper removes it."""
    restored_probe = False

    def sql(self, instance, statement, *, container=None):
        result = super().sql(instance, statement, container=container)
        if container and container.startswith('baander-rehearsal-') and statement == CATALOG_SQL:
            assert sql(container, "SELECT id FROM development_checkpoint_probe WHERE value &@~ 'library'") == '1'
            assert sql(container, "INSERT INTO development_checkpoint_probe(value) VALUES ('next') RETURNING id") == '2'
            self.restored_probe = True
        return result


with tempfile.TemporaryDirectory(prefix=name + '-') as temporary:
    root = Path(temporary)
    store = StateStore(root / 'state')
    instance = {'id': 'persistent', 'kind': 'persistent', 'hostname': 'checkpoint.baander.app',
                'project': name, 'network': name, 'docker': configuration, 'writers_stopped': True,
                'compose_file': str(root / 'compose.json')}
    release = {'source': str(Path.cwd()), 'revision': 'd' * 40,
               'app_image': os.environ['BAANDER_DATABASE_DRILL_APP'],
               'postgres_image': os.environ['BAANDER_DATABASE_DRILL_IMAGE'],
               'redis_image': os.environ['BAANDER_DATABASE_DRILL_REDIS'],
               'nginx_image': os.environ['BAANDER_DATABASE_DRILL_APP']}
    instance = Bootstrap(store, runner).configure(instance)
    environment = Runtime(store, runner).environment(instance)
    runner.private_values = [value for key, value in environment.items() if any(word in key for word in ('SECRET', 'PASSWORD', 'PRIVATE_KEY'))]
    model = Runtime(store, runner).config(instance, release)
    # Use the actual service recipes while excluding unrelated web services.
    compose = {'name': name, 'services': {key: model['services'][key] for key in ('postgres', 'redis')},
               'networks': {'private': model['networks']['private']},
               'volumes': {key: model['volumes'][key] for key in ('database', 'redis')}}
    atomic_json(Path(instance['compose_file']), compose)
    database = VerifiedDatabase(store, runner)
    migration_files = sorted(Path(release['source']).joinpath('migrations').glob('Version*.php'))
    assert len(migration_files) >= 2, 'the drill needs an existing migration followed by a pending target migration'
    runner.expected_versions = ['DoctrineMigrations\\' + path.stem for path in migration_files]
    source_version = runner.expected_versions[-2]
    pending_version = runner.expected_versions[-1]
    try:
        docker.run('network', 'create', '--internal', name)
        for key in ('database', 'redis', 'var', 'secrets', 'storage', 'conversions', 'transcode_state'):
            volume_name = model['volumes'][key]['name']
            docker.run('volume', 'create', volume_name)
            volumes.append(volume_name)
        docker.compose(instance, 'up', '-d', '--wait', 'postgres', 'redis')
        postgres = docker.compose(instance, 'ps', '-q', 'postgres').stdout.strip()
        source_redis = docker.compose(instance, 'ps', '-q', 'redis').stdout.strip()
        assert postgres and source_redis
        assert database.inspect(instance, release)['fresh']
        sql(postgres, "CREATE EXTENSION IF NOT EXISTS pgroonga; CREATE TABLE development_checkpoint_probe(id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY, value text); INSERT INTO development_checkpoint_probe(value) VALUES ('retained library identity'); CREATE INDEX development_checkpoint_probe_search ON development_checkpoint_probe USING pgroonga(value);")
        try:
            database.inspect(instance, release)
            raise AssertionError('populated unversioned database was accepted')
        except WorkflowError as error:
            assert 'unversioned' in str(error)
        assert sql(postgres, "SELECT to_regclass('doctrine_migration_versions') IS NULL") == 't'
        sql(postgres, 'DROP TABLE development_checkpoint_probe')
        migration_env = {key: environment[key] for key in ('DB_USERNAME', 'DB_PASSWORD', 'DB_DATABASE', 'APP_SECRET', 'DATABASE_URL')}
        migration_env.update(APP_ENV='prod', APP_DEBUG='0')
        docker.run('run', '--rm', '--network', name, '--user', '0:0',
            *[part for key in migration_env for part in ('--env', key)],
            '--entrypoint', 'php', release['app_image'], '-d', 'memory_limit=512M', 'bin/console', 'doctrine:migrations:migrate', source_version, '--no-interaction', '--env=prod',
            env=migration_env, timeout=None)
        sql(postgres, "CREATE TABLE development_checkpoint_probe(id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY, value text); INSERT INTO development_checkpoint_probe(value) VALUES ('retained library identity'); CREATE INDEX development_checkpoint_probe_search ON development_checkpoint_probe USING pgroonga(value);")
        catalog = database.inspect(instance, release)
        assert catalog['versions'] == runner.expected_versions[:-1]
        assert catalog['pending'] == [pending_version]
        assert catalog['objects']  # PostgreSQL collation defines catalog ordering.
        assert catalog['objects'] == database.inspect(instance, release)['objects']
        # Keep each writable class distinguishable after independent extraction.
        for key in ('var', 'secrets', 'storage', 'conversions', 'transcode_state'):
            docker.run('run', '--rm', '--network', 'none', '--user', '0:0', '--entrypoint', '/bin/sh',
                '--mount', f'type=volume,src={model["volumes"][key]["name"]},dst=/seed', release['app_image'], '-ec',
                'umask 077; printf "%s" "$1" > /seed/checkpoint-probe; chown -R www-data:www-data /seed', 'seed', key)
        docker.run('run', '--rm', '--network', 'none', '--user', '0:0', '--entrypoint', '/bin/sh',
            '--mount', f'type=volume,src={model["volumes"]["secrets"]["name"]},dst=/seed', release['app_image'], '-ec',
            'umask 077; mkdir -p /seed/oauth; openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:2048 -out /seed/oauth/private.key 2>/dev/null; '
            'openssl pkey -in /seed/oauth/private.key -pubout -out /seed/oauth/public.key; chown -R www-data:www-data /seed')
        assert redis(source_redis, 'CONFIG', 'GET', 'appendonly').splitlines() == ['appendonly', 'yes']
        assert redis(source_redis, 'CONFIG', 'GET', 'dir').splitlines() == ['dir', '/data'], 'Redis persistence must use its mounted volume'
        assert redis(source_redis, 'SET', 'checkpoint:durable', 'queue-payload') == 'OK'
        assert redis(source_redis, 'SET', 'checkpoint:ttl', 'expiring-payload', 'PX', '3600000') == 'OK'
        expiry = redis(source_redis, 'PEXPIRETIME', 'checkpoint:ttl')
        assert int(expiry) > 0
        assert redis(source_redis, 'SET', 'checkpoint:expired', 'short-lived', 'PX', '1000') == 'OK'
        assert redis(source_redis, 'GET', 'checkpoint:expired') == 'short-lived'
        identity_digest = hashlib.sha256((store.directory('persistent') / 'env.json').read_bytes()).hexdigest()
        checkpoint = database.checkpoint(instance, release)
        directory = Path(checkpoint['directory'])
        assert checkpoint['verified'] is False
        assert checkpoint['state'] == ['redis', 'var', 'secrets', 'storage', 'conversions', 'transcode_state']
        assert checkpoint['catalog']['objects'] == catalog['objects']
        assert not json.loads(docker.run('inspect', source_redis).stdout)[0]['State']['Running']
        assert (directory.stat().st_mode & 0o777) == 0o700
        for artifact in directory.iterdir():
            assert (artifact.stat().st_mode & 0o777) == 0o600, artifact.name
        assert hashlib.sha256((directory / 'env.json').read_bytes()).hexdigest() == identity_digest
        original_size = (directory / 'database.dump').stat().st_size
        assert hashlib.sha256((directory / 'database.dump').read_bytes()).hexdigest() == checkpoint['archive_sha256']
        with (directory / 'database.dump').open('ab') as archive:
            archive.write(b'corrupt')
        try:
            database.rehearse(instance, release, checkpoint)
            raise AssertionError('changed database archive was accepted')
        except WorkflowError as error:
            assert 'archive changed' in str(error)
        with (directory / 'database.dump').open('r+b') as archive:
            archive.truncate(original_size)
        try:
            database.rehearse(instance, dict(release, revision='e' * 40), checkpoint)
            raise AssertionError('wrong target release was accepted')
        except WorkflowError as error:
            assert 'different target release' in str(error)
        checkpoint = database.rehearse(instance, release, checkpoint)
        assert checkpoint['verified'] and database.restored_probe
        assert runner.rehearsal_versions == [runner.expected_versions, runner.expected_versions]
        unchanged = database.inspect(instance, release)
        assert unchanged['versions'] == runner.expected_versions[:-1]
        assert unchanged['pending'] == [pending_version]
        assert json.loads((directory / 'checkpoint.json').read_text())['verified']
        # Restore the produced archives without making any host private file readable.
        for key in checkpoint['state']:
            restored_volume = name + '-restored-' + key
            docker.run('volume', 'create', restored_volume)
            volumes.append(restored_volume)
            extractor = name + '-extract-' + key
            containers.append(extractor)
            docker.run('create', '--name', extractor, '--network', 'none', '--user', '0:0', '--entrypoint', '/bin/sh',
                '--mount', f'type=volume,src={restored_volume},dst=/restore', release['app_image'], '-ec',
                'tar -xpf /tmp/state.tar -C /restore; if [ \"$1\" != redis ]; then test \"$(cat /restore/checkpoint-probe)\" = \"$1\"; fi', 'restore', key)
            docker.run('cp', str(directory / (key + '.tar')), extractor + ':/tmp/state.tar')
            docker.run('start', '--attach', extractor)
            assert docker.run('inspect', '--format', '{{.State.ExitCode}}', extractor).stdout.strip() == '0'
            docker.run('rm', extractor)
            containers.remove(extractor)
            with tarfile.open(directory / (key + '.tar')) as archive:
                if key == 'redis':
                    assert any('appendonly' in member.name for member in archive.getmembers())
                else:
                    marker = archive.extractfile('./checkpoint-probe')
                    assert marker is not None and marker.read().decode() == key
                if key == 'secrets':
                    assert archive.getmember('./oauth/private.key').mode == 0o600
        restored_redis = name + '-restored-redis'
        containers.append(restored_redis)
        docker.run('run', '-d', '--name', restored_redis, '--network', 'none',
            '--mount', f'type=volume,src={restored_redis},dst=/data', '--env', 'REDIS_PASSWORD',
            '--entrypoint', '/bin/sh', release['redis_image'], '-ec',
            'exec redis-server --dir /data --appendonly yes --requirepass "$REDIS_PASSWORD"', env={'REDIS_PASSWORD': environment['REDIS_PASSWORD']})
        docker.run('exec', restored_redis, '/bin/sh', '-ec',
            'export REDISCLI_AUTH="$REDIS_PASSWORD"; for n in $(seq 1 30); do redis-cli PING | grep -qx PONG && exit 0; sleep 1; done; exit 1')
        assert redis(restored_redis, 'GET', 'checkpoint:durable') == 'queue-payload'
        assert redis(restored_redis, 'GET', 'checkpoint:ttl') == 'expiring-payload'
        assert redis(restored_redis, 'PEXPIRETIME', 'checkpoint:ttl') == expiry
        assert redis(restored_redis, 'EXISTS', 'checkpoint:expired') == '0'
        assert not json.loads(docker.run('inspect', source_redis).stdout)[0]['State']['Running']
        assert sql(postgres, 'SELECT count(*) FROM development_checkpoint_probe') == '1'
        assert hashlib.sha256((store.directory('persistent') / 'env.json').read_bytes()).hexdigest() == identity_digest
        sql(postgres, "INSERT INTO doctrine_migration_versions(version) VALUES ('DoctrineMigrations\\Version19990101000000')")
        try:
            database.inspect(instance, release)
            raise AssertionError('former migration was accepted')
        except WorkflowError as error:
            assert 'migration history' in str(error)
        print('PASS: actual checkpoint and isolated pending migration rehearsal plus idempotent second pass; unchanged source migration history; deterministic catalogs, archive integrity, PGroonga and sequence restore; private identity and six state archives; stopped Redis AOF restore with unchanged expiry and expired-key removal.')
    finally:
        for container in containers:
            docker.run('rm', '--force', '--volumes', container, check=False)
        docker.compose(instance, 'down', '--volumes', check=False)
        for volume_name in volumes:
            docker.run('volume', 'rm', volume_name, check=False)
        docker.run('network', 'rm', name, check=False)
PY
