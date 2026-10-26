"""Read catalogs without a kernel and rehearse changes against isolated restores."""
from __future__ import annotations
import hashlib
import json
from pathlib import Path
import re
import shutil
from urllib.parse import quote
import uuid
from .model import StateStore, WorkflowError, atomic_json
from .process import Docker, Runner
from .runtime import Runtime

CATALOG_SQL = r"""
BEGIN READ ONLY;
SET LOCAL statement_timeout = '15s';
SET LOCAL lock_timeout = '3s';
SET LOCAL timezone = 'UTC';
SELECT json_build_object(
 'server_version', current_setting('server_version_num')::int,
 'database', current_database(),
 'owner', pg_get_userbyid((SELECT datdba FROM pg_database WHERE datname=current_database())),
 'extensions', (SELECT coalesce(json_object_agg(extname,extversion),'{}'::json) FROM pg_extension),
 'objects', (SELECT coalesce(json_agg(n.nspname || '.' || identified.identity ORDER BY n.nspname,identified.identity),'[]'::json)
 FROM pg_depend d JOIN pg_namespace n ON n.oid=d.refobjid
 CROSS JOIN LATERAL pg_identify_object(d.classid,d.objid,d.objsubid) identified
 WHERE d.refclassid='pg_namespace'::regclass AND n.nspname <> 'information_schema'
 AND n.nspname !~ '^pg_' AND d.classid <> 'pg_extension'::regclass
 AND NOT EXISTS (SELECT 1 FROM pg_depend e WHERE e.classid=d.classid AND e.objid=d.objid AND e.deptype='e')
 AND NOT (identified.type='table' AND identified.name='doctrine_migration_versions' AND n.nspname=current_schema())),
 'metadata', to_regclass('doctrine_migration_versions') IS NOT NULL
);
SELECT CASE WHEN to_regclass('doctrine_migration_versions') IS NOT NULL THEN
 'SELECT json_build_object(''versions'',coalesce(json_agg(version ORDER BY version),''[]''::json)) FROM doctrine_migration_versions;'
 ELSE 'SELECT json_build_object(''versions'',''[]''::json);' END \gexec
COMMIT;
"""

class Database:
    def __init__(self, store: StateStore, runner: Runner):
        self.store,self.runner=store,runner

    def docker(self, instance):
        docker=Docker(self.runner,instance['docker'])
        docker.validate()
        return docker

    def container(self, instance, docker):
        if instance.get('adoption') and not instance.get('ownership_transferred'):
            return instance['adoption']['containers']['postgres']
        identifier=docker.compose(instance,'ps','-q','postgres').stdout.strip()
        if not identifier:
            raise WorkflowError('The recorded PostgreSQL container is unavailable.')
        return identifier

    def sql(self, instance, sql, *, container=None):
        docker=self.docker(instance)
        identifier=container or self.container(instance,docker)
        result=docker.run('exec','-i',identifier,'/bin/sh','-ec',
            'export PGPASSWORD="$POSTGRES_PASSWORD" PGCONNECT_TIMEOUT=5; '
            'exec psql -X -qAt --set=ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d "$POSTGRES_DB"',input=sql,timeout=30)
        rows=[json.loads(line) for line in result.stdout.splitlines() if line.startswith('{')]
        if not rows:
            raise WorkflowError('PostgreSQL returned no catalog evidence.')
        return dict(pair for row in rows for pair in row.items())

    def inspect(self, instance, release):
        files=sorted((Path(release['source'])/'migrations').glob('Version*.php'))
        if not files or any(not re.fullmatch(r'Version\d{14}\.php',file.name) for file in files):
            raise WorkflowError('The target migration inventory is missing or malformed.')
        expected=['DoctrineMigrations\\'+file.stem for file in files]
        catalog=self.sql(instance,CATALOG_SQL)
        versions=catalog.get('versions')
        if not isinstance(versions,list) or any(not isinstance(v,str) for v in versions):
            raise WorkflowError('Malformed PostgreSQL migration history.')
        if versions != expected[:len(versions)]:
            raise WorkflowError('Unknown, former, ahead-of-target or incomplete migration history; preserve this database and reconcile it explicitly.')
        if not versions and catalog.get('objects'):
            raise WorkflowError('Populated unversioned database refused before application metadata initialization.')
        hashes={file.name:hashlib.sha256(file.read_bytes()).hexdigest() for file in files}
        for name,digest in instance.get('migration_hashes',{}).items():
            if name in hashes and hashes[name]!=digest:
                raise WorkflowError('An applied migration changed; reconcile the saved release before continuing.')
        return dict(catalog,fresh=not versions,pending=expected[len(versions):],migration_hashes={files[i].name:hashes[files[i].name] for i in range(len(versions))})

    @staticmethod
    def quiescent(instance):
        if instance.get('writers_stopped') is not True:
            raise WorkflowError('Stop and account for all database, queue and filesystem writers before checkpointing or migration.')

    def checkpoint(self, instance, release):
        self.quiescent(instance)
        catalog=self.inspect(instance,release)
        docker=self.docker(instance)
        container=self.container(instance,docker)
        # Refuse even idle application connections: they can start a write after the snapshot.
        activity=self.sql(instance,"BEGIN READ ONLY; SELECT json_build_object('writers',count(*)) FROM pg_stat_activity WHERE datname=current_database() AND pid<>pg_backend_pid() AND backend_type='client backend'; COMMIT;")
        if activity.get('writers',0):
            raise WorkflowError('Unaccounted database writers prevent a quiescent checkpoint.')
        directory=self.store.directory(instance['id'])/'checkpoints'/uuid.uuid4().hex
        directory.mkdir(mode=0o700,parents=True)
        archive='/tmp/baander-checkpoint-'+directory.name+'.dump'
        try:
            docker.run('exec',container,'/bin/sh','-ec',
                       'umask 077; export PGPASSWORD="$POSTGRES_PASSWORD" PGCONNECT_TIMEOUT=5; '
                       'exec pg_dump --format=custom --file="$1" -U "$POSTGRES_USER" -d "$POSTGRES_DB"',
                       'checkpoint',archive,timeout=None)
            docker.run('cp',container+':'+archive,str(directory/'database.dump'),timeout=None)
        finally:
            docker.run('exec',container,'rm','-f',archive,check=False)
        if not (directory/'database.dump').is_file() or not (directory/'database.dump').stat().st_size:
            raise WorkflowError('Database checkpoint is missing or empty; live migration remains blocked.')
        (directory/'database.dump').chmod(0o600)
        shutil.copyfile(self.store.directory(instance['id'])/'env.json',directory/'env.json')
        (directory/'env.json').chmod(0o600)
        model=Runtime(self.store,self.runner).config(instance,release)
        redis_id=(instance.get('adoption',{}).get('containers',{}).get('redis') if not instance.get('ownership_transferred') else None)
        if redis_id:
            docker.run('exec',redis_id,'/bin/sh','-ec','export REDISCLI_AUTH="$REDIS_PASSWORD"; exec redis-cli SAVE')
        else:
            docker.compose(instance,'exec','-T','redis','/bin/sh','-ec','export REDISCLI_AUTH="$REDIS_PASSWORD"; exec redis-cli SAVE')
        snapshots=[]
        for key in ('redis','var','secrets','media','conversions'):
            mount=instance.get('state_mounts',{}).get(key)
            if mount is None:
                mount={'type':'volume','source':model['volumes'][key]['name']}
            source=mount['source']
            mount_argument=f"type={mount['type']},src={source},dst=/snapshot,readonly"
            name='baander-checkpoint-'+uuid.uuid4().hex
            docker.run('create','--name',name,'--network','none','--user','0:0','--entrypoint','/bin/sh',
                       '--mount',mount_argument,release['app_image'],'-ec','umask 077; tar -cpf /tmp/state.tar -C /snapshot .')
            try:
                docker.run('start','--attach',name,timeout=None)
                code=docker.run('inspect','--format','{{.State.ExitCode}}',name).stdout.strip()
                if code!='0':
                    raise WorkflowError('Writable state checkpoint failed; preserve the database archive.')
                target=directory/(key+'.tar')
                docker.run('cp',name+':/tmp/state.tar',str(target),timeout=None)
                target.chmod(0o600)
                snapshots.append(key)
            finally:
                docker.run('rm','--force',name,check=False)
        checkpoint={'directory':str(directory),'catalog':catalog,'state':snapshots,'verified':False,
                    'postgres_image':instance.get('postgres_image',release['postgres_image'])}
        atomic_json(directory/'checkpoint.json',checkpoint)
        return checkpoint

    def rehearse(self, instance, release, checkpoint):
        docker=self.docker(instance)
        directory=Path(checkpoint['directory'])
        name='baander-rehearsal-'+uuid.uuid4().hex[:16]
        network=name+'-private'
        env=Runtime(self.store,self.runner).environment(instance)
        rehearsal_url='postgresql://'+quote(env['DB_USERNAME'],safe='')+':'+quote(env['DB_PASSWORD'],safe='')+'@database:5432/'+quote(env['DB_DATABASE'],safe='')+'?serverVersion=18&charset=utf8'
        docker.run('network','create','--internal',network)
        try:
            docker.run('run','-d','--name',name,'--network',network,'--network-alias','database',
                       '--env','POSTGRES_PASSWORD','--env','POSTGRES_USER','--env','POSTGRES_DB',
                       checkpoint['postgres_image'],env={'POSTGRES_PASSWORD':env['DB_PASSWORD'],
                       'POSTGRES_USER':env['DB_USERNAME'],'POSTGRES_DB':env['DB_DATABASE']})
            docker.run('exec',name,'/bin/sh','-ec',
                       'for n in $(seq 1 60); do pg_isready -h 127.0.0.1 -U "$POSTGRES_USER" -d "$POSTGRES_DB" && exit 0; sleep 1; done; exit 1',timeout=70)
            docker.run('cp',str(directory/'database.dump'),name+':/tmp/database.dump',timeout=None)
            docker.run('exec',name,'/bin/sh','-ec',
                       'export PGPASSWORD="$POSTGRES_PASSWORD"; exec pg_restore --exit-on-error --single-transaction '
                       '-U "$POSTGRES_USER" -d "$POSTGRES_DB" /tmp/database.dump',timeout=None)
            restored=self.sql(instance,CATALOG_SQL,container=name)
            for key in ('versions','objects','extensions','owner'):
                if restored.get(key)!=checkpoint['catalog'].get(key):
                    raise WorkflowError('Restored catalog differs from the checkpoint; live migration remains blocked.')
            # No source deployment mounts, queues or Redis are available to this target kernel.
            for _ in range(2):
                docker.run('run','--rm','--network',network,'--env','DB_HOST=database','--env','DATABASE_URL',
                           *[part for key in ('DB_USERNAME','DB_PASSWORD','DB_DATABASE','APP_SECRET') for part in ('--env',key)],
                           '--mount',f'type=bind,src={directory / "secrets.tar"},dst=/checkpoint/secrets.tar,readonly',
                           '--entrypoint','/bin/sh',release['app_image'],'-ec',
                           'mkdir -p config/secrets; tar -xpf /checkpoint/secrets.tar -C config/secrets; exec php bin/console "$@"','rehearsal','doctrine:migrations:migrate','--no-interaction',
                           env={key:env[key] for key in ('DB_USERNAME','DB_PASSWORD','DB_DATABASE','APP_SECRET')} | {'DATABASE_URL':rehearsal_url},timeout=None)
            checkpoint=dict(checkpoint,verified=True)
            atomic_json(directory/'checkpoint.json',checkpoint)
            return checkpoint
        finally:
            docker.run('rm','--force','--volumes',name,check=False)
            docker.run('network','rm',network,check=False)

    def migrate(self, instance, release, checkpoint=None):
        self.quiescent(instance)
        activity=self.sql(instance,"BEGIN READ ONLY; SELECT json_build_object('writers',count(*)) FROM pg_stat_activity WHERE datname=current_database() AND pid<>pg_backend_pid() AND backend_type='client backend'; COMMIT;")
        if activity.get('writers',0):
            raise WorkflowError('Unaccounted database writers prevent migration.')
        catalog=self.inspect(instance,release)
        if not catalog['fresh'] and (catalog['pending'] or instance.get('adoption')):
            if not checkpoint or checkpoint.get('verified') is not True:
                raise WorkflowError('A verified isolated restore and migration rehearsal is required.')
        instance=dict(instance,phase='database_mutating',target_revision=release.get('revision'))
        self.store.save(instance['id'],instance)
        docker=self.docker(instance)
        try:
            docker.compose(instance,'run','--rm','--no-deps','app','php','bin/console','doctrine:migrations:migrate','--no-interaction',timeout=None)
        except BaseException:
            self.store.save(instance['id'],dict(instance,phase='blocked',failure='Database mutation confirmation lost; reconcile this attempt before starting any release.'))
            raise
        catalog=self.inspect(instance,release)
        if catalog['pending']:
            raise WorkflowError('Target migrations remain pending; preserve maintenance state.')
        return dict(instance,migration_hashes=catalog['migration_hashes'])
