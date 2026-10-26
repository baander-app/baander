"""Explicit inventory, stable private configuration and fresh-only initialization."""
from __future__ import annotations
import base64
import json
import tempfile
from urllib.parse import quote
from pathlib import Path
import secrets
from .database import Database
from .model import StateStore, WorkflowError, atomic_json
from .process import Docker, Runner
from .runtime import Runtime

class Bootstrap:
    def __init__(self, store: StateStore, runner: Runner):
        self.store,self.runner=store,runner

    def configure(self, instance, *, environment=None, adoption=None):
        directory=self.store.directory(instance['id'])
        path=directory/'env.json'
        if path.exists():
            saved=Runtime(self.store,self.runner).environment(instance)
            if environment is not None and saved!=environment:
                raise WorkflowError('Saved instance identity differs; reconcile configuration explicitly.')
            return instance
        if adoption is not None:
            if not environment:
                raise WorkflowError('Adoption requires explicit existing environment and container inventory.')
            instance=self.inventory(instance,adoption,environment)
            values=dict(environment)
        else:
            values={'APP_ENV':'prod' if instance.get('kind')=='persistent' else 'dev','APP_DEBUG':'0','APP_SECRET':secrets.token_hex(32),
                    'DB_HOST':'database','DB_PORT':'5432','DB_USERNAME':'baander','DB_DATABASE':'baander',
                    'DB_PASSWORD':secrets.token_hex(32),'REDIS_PASSWORD':secrets.token_hex(32),
                    'STREAM_URL_HMAC_SECRET':secrets.token_hex(32),'AUTH_SPA_CLIENT_ID':'baander_dev_spa_00001'}
            if environment:
                values.update(environment)
            values.update(self.vapid())
            values['DATABASE_URL']='postgresql://'+quote(values['DB_USERNAME'],safe='')+':'+quote(values['DB_PASSWORD'],safe='')+'@database:5432/'+quote(values['DB_DATABASE'],safe='')+'?serverVersion=18&charset=utf8'
            values['MEDIA_STORAGE_PATH']='/storage/media'
            values['CONVERT_STORAGE_PATH']='/storage/conversions'
            values['REDIS_URL']='redis://default:'+values['REDIS_PASSWORD']+'@redis:6379'
            values['MESSENGER_TRANSPORT_DSN']=values['REDIS_URL']+'/messages'
        atomic_json(self.store._prepare(instance['id'])/'env.json',values)
        return instance

    def vapid(self):
        with tempfile.TemporaryDirectory(prefix='baander-vapid-') as temporary:
            key=Path(temporary)/'private.pem'
            self.runner.run(['openssl','genpkey','-algorithm','EC','-pkeyopt','ec_paramgen_curve:P-256','-out',str(key)])
            key.chmod(0o600)
            output=self.runner.run(['openssl','ec','-in',str(key),'-text','-noout']).stdout
        try:
            private=bytes.fromhex(''.join(output.split('priv:')[1].split('pub:')[0].split()).replace(':',''))
            public=bytes.fromhex(''.join(output.split('pub:')[1].split('ASN1 OID:')[0].split()).replace(':',''))
            if len(private)!=32 or len(public)!=65:
                raise ValueError('unexpected P-256 key size')
        except (ValueError,IndexError) as error:
            raise WorkflowError('Cannot generate the instance VAPID identity.') from error
        encode=lambda value:base64.urlsafe_b64encode(value).decode().rstrip('=')
        return {'VAPID_PRIVATE_KEY':encode(private),'VAPID_PUBLIC_KEY':encode(public)}

    def inventory(self, instance, adoption, environment):
        docker=Docker(self.runner,instance['docker'])
        docker.validate()
        containers=adoption.get('containers',{})
        if not {'postgres','redis','app'}.issubset(containers) or set(containers)-{'postgres','redis','app','nginx'} or any(not value for value in containers.values()):
            raise WorkflowError('Select exact postgres, redis and app container IDs for adoption.')
        inspections={}
        for role,identifier in containers.items():
            inspected=json.loads(docker.run('inspect',identifier).stdout)[0]
            if inspected['Id']!=identifier:
                raise WorkflowError('Adoption requires immutable full container IDs.')
            inspections[role]=inspected
        db=inspections['postgres']
        db_env=dict(value.split('=',1) for value in db['Config']['Env'] if '=' in value)
        pgdata=db_env.get('PGDATA','/var/lib/postgresql/data')
        database_mounts=[m for m in db['Mounts'] if pgdata==m['Destination'] or pgdata.startswith(m['Destination'].rstrip('/')+'/')]
        if len(database_mounts)!=1 or database_mounts[0]['Type']!='volume':
            raise WorkflowError('The selected database must have one recorded existing PGDATA volume.')
        db_mount=database_mounts[0]
        docker.run('volume','inspect',db_mount['Name'])
        for key in ('APP_SECRET','STREAM_URL_HMAC_SECRET','VAPID_PRIVATE_KEY','VAPID_PUBLIC_KEY','AUTH_SPA_CLIENT_ID','DB_PASSWORD'):
            if not environment.get(key):
                raise WorkflowError('Adoption requires all existing identity secrets and OAuth configuration.')
        for variable,key in (('POSTGRES_PASSWORD','DB_PASSWORD'),('POSTGRES_USER','DB_USERNAME'),('POSTGRES_DB','DB_DATABASE')):
            if db_env.get(variable)!=environment.get(key):
                raise WorkflowError('Selected PostgreSQL identity differs from the adoption configuration.')
        targets={'var':'/var/www/html/var','secrets':'/var/www/html/config/secrets',
                 'media':environment.get('MEDIA_STORAGE_PATH','/var/www/html/storage/media'),
                 'conversions':environment.get('CONVERT_STORAGE_PATH','/var/www/html/storage/conversions'),'redis':'/data'}
        state_mounts={}
        volumes=dict(instance.get('volumes',{}),database=db_mount['Name'])
        for key,target in targets.items():
            role='redis' if key=='redis' else 'app'
            matches=sorted([m for m in inspections[role]['Mounts'] if target==m['Destination'] or target.startswith(m['Destination'].rstrip('/')+'/')],key=lambda m:len(m['Destination']),reverse=True)
            if not matches or matches[0]['Type'] not in ('volume','bind'):
                raise WorkflowError('Adoption inventory lacks an exact writable mount for '+target)
            mount=matches[0]
            suffix=target[len(mount['Destination']):].lstrip('/')
            if suffix and mount['Type']=='volume':
                raise WorkflowError('Nested writable volume adoption requires an explicit copy after quiescence.')
            if mount['Type']=='volume':
                docker.run('volume','inspect',mount['Name'])
                volumes[key]=mount['Name']
            state_mounts[key]={'type':mount['Type'],'source':mount.get('Name') if mount['Type']=='volume' else str(Path(mount['Source'])/suffix),'target':target}
        media=[{'source':m['Source'],'target':m['Destination']} for m in inspections['app']['Mounts']
               if m['Type']=='bind' and m['Destination'] not in targets.values() and not m['Destination'].startswith('/var/www/html')]
        if 'worker_manifests' not in adoption or not isinstance(adoption['worker_manifests'],list):
            raise WorkflowError('Record the current worker manifests and owner before adopting writable resources.')
        key_check = r"""$base='/var/www/html';
$a=str_replace('%kernel.project_dir%',$base,$argv[1]);
$b=str_replace('%kernel.project_dir%',$base,$argv[2]);
if (!is_file($a)||!is_file($b)||is_link($a)||is_link($b)) { exit(2); }
$p=openssl_pkey_get_private(file_get_contents($a));
$q=openssl_pkey_get_public(file_get_contents($b));
if ($p===false||$q===false||!openssl_sign('adoption',$s,$p,OPENSSL_ALGO_SHA256)||openssl_verify('adoption',$s,$q,OPENSSL_ALGO_SHA256)!==1) { exit(3); }
"""
        docker.run('exec',containers['app'],'php','-r',key_check,
                   environment.get('OAUTH_PRIVATE_KEY_PATH','/var/www/html/config/secrets/oauth/private.key'),
                   environment.get('OAUTH_PUBLIC_KEY_PATH','/var/www/html/config/secrets/oauth/public.key'))
        return dict(instance,adoption=adoption,adoption_inventory=inspections,volumes=volumes,
                    pgdata=pgdata,postgres_mount_target=db_mount['Destination'],postgres_image=db['Image'],
                    state_mounts=state_mounts,media=media,initialization='adopted',
                    warnings=['An origin change requires login again; passkeys remain bound to their enrolled relying party.'])

    def transfer(self, instance, release, checkpoint):
        Database.quiescent(instance)
        if not checkpoint or checkpoint.get('verified') is not True:
            raise WorkflowError('Adoption transfer requires a verified checkpoint and isolated migration rehearsal.')
        if instance.get('ownership_transferred'):
            return instance
        docker=Docker(self.runner,instance['docker'])
        docker.validate()
        inventory=instance['adoption_inventory']
        for role,recorded in inventory.items():
            actual=json.loads(docker.run('inspect',recorded['Id']).stdout)[0]
            if actual['Id']!=recorded['Id'] or actual['Image']!=recorded['Image'] or actual['Mounts']!=recorded['Mounts']:
                raise WorkflowError('Adoption container identity or mounts changed; preserve the checkpoint and reconcile inventory.')
            if role in ('app','nginx') and actual['State']['Running']:
                raise WorkflowError('The original app writer and recorded frontend must be stopped before adoption handoff.')
        # The original cluster is stopped before a second server can mount PGDATA.
        for role in ('postgres','redis'):
            docker.run('stop','--time','60',inventory[role]['Id'],timeout=90)
            actual=json.loads(docker.run('inspect',inventory[role]['Id']).stdout)[0]
            if actual['State']['Running']:
                raise WorkflowError('Original data service did not stop; no replacement may start.')
        mounts=dict(instance['state_mounts'])
        volumes=dict(instance['volumes'])
        for key,mount in list(mounts.items()):
            if mount['type']!='bind':
                continue
            destination=instance['project']+'-adopted-'+key+'-'+Path(checkpoint['directory']).name[:12]
            docker.run('volume','create',destination)
            archive=Path(checkpoint['directory'])/(key+'.tar')
            docker.run('run','--rm','--network','none','--user','0:0','--entrypoint','/bin/sh',
                       '--mount',f'type=volume,src={destination},dst=/restore',
                       '--mount',f'type=bind,src={archive},dst=/checkpoint.tar,readonly',
                       release['app_image'],'-ec',
                       'test -z "$(ls -A /restore)"; tar -xpf /checkpoint.tar -C /restore')
            mounts[key]=dict(mount,type='volume',source=destination)
            volumes[key]=destination
        transferred=dict(instance,state_mounts=mounts,volumes=volumes,ownership_transferred=True)
        self.store.save(instance['id'],transferred)
        return transferred

    def initialize(self, instance, release):
        database=Database(self.store,self.runner)
        catalog=database.inspect(instance,release)
        if instance.get('initialization')=='adopted':
            if catalog['fresh']:
                raise WorkflowError('Adoption requires a compatible populated database.')
            client_id=Runtime(self.store,self.runner).environment(instance)['AUTH_SPA_CLIENT_ID'].replace("'","''")
            client=database.sql(instance,"BEGIN READ ONLY; SELECT json_build_object('valid',EXISTS(SELECT 1 FROM oauth_clients WHERE public_id='"+client_id+"' AND first_party AND password_client AND NOT revoked)); COMMIT;")
            if not client.get('valid'):
                raise WorkflowError('The adopted first-party OAuth client is missing or unsuitable; preserve identities and reconcile configuration.')
            return instance
        if instance.get('initialization')=='complete':
            return instance
        if not catalog['fresh'] and instance.get('initialization')!='initializing':
            raise WorkflowError('A populated database requires explicit adoption; development users will not be seeded.')
        instance=dict(instance,initialization='initializing',phase='database_mutating')
        self.store.save(instance['id'],instance)
        runtime=Runtime(self.store,self.runner)
        runtime.prepare(instance,release)
        docker=Docker(self.runner,instance['docker'])
        docker.validate()
        try:
            docker.compose(instance,'run','--rm','--no-deps','app','php','bin/console','app:dev:setup',
                           '--origin=https://'+instance['hostname'],'--no-interaction',timeout=None)
        except BaseException:
            self.store.save(instance['id'],dict(instance,phase='blocked',failure='Fresh initialization interrupted; resume this identity without regenerating secrets.'))
            raise
        catalog=database.inspect(instance,release)
        if catalog['pending']:
            raise WorkflowError('Fresh initialization did not finish all target migrations.')
        instance=dict(instance,initialization='complete',migration_hashes=catalog['migration_hashes'])
        self.store.save(instance['id'],instance)
        return instance
