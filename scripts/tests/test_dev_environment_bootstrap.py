"""Catalog refusal and resumable bootstrap contracts."""
import hashlib
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from dev_environment.model import StateStore, WorkflowError
from dev_environment.database import Database
from dev_environment.bootstrap import Bootstrap
from dev_environment.process import Runner

class CatalogRunner:
    def __init__(self, catalog):
        self.catalog, self.calls = catalog, []
    def run(self, args, **kwargs):
        self.calls.append((args, kwargs))
        if 'info' in args:
            output = '{"ID":"daemon"}'
        elif 'psql' in ' '.join(args):
            output = json.dumps(self.catalog)
        else:
            output = 'container'
        return subprocess.CompletedProcess(args, 0, output, '')

class BootstrapTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        (self.root / 'migrations').mkdir()
        for version in ('20261017000000','20261017130000'):
            (self.root / 'migrations' / ('Version' + version + '.php')).write_text('<?php')
        self.store = StateStore(self.root / 'state')
        self.instance = {'id':'persistent','project':'test','compose_file':'compose.json',
                         'hostname':'dev.baander.app','docker':{'executable':'docker','context':'test','daemon_id':'daemon'}}
        self.release = {'source':str(self.root),'postgres_image':'postgres'}
    def inspect(self, catalog):
        runner = CatalogRunner(catalog)
        return Database(self.store, runner).inspect(self.instance,self.release)
    def test_empty_catalog_can_initialize(self):
        self.assertTrue(self.inspect({'versions':[],'objects':[],'server_version':180004})['fresh'])
    def test_refuses_populated_unversioned_without_kernel(self):
        with self.assertRaisesRegex(WorkflowError,'unversioned'):
            self.inspect({'versions':[],'objects':['public.users'],'server_version':180004})
    def test_refuses_former_ahead_and_holey_histories(self):
        for versions in (['DoctrineMigrations\\Version20240101000000'],['DoctrineMigrations\\Version20261017130000']):
            with self.subTest(versions=versions), self.assertRaises(WorkflowError):
                self.inspect({'versions':versions,'objects':['public.users'],'server_version':180004})
    def test_valid_prefix_reports_pending(self):
        result=self.inspect({'versions':['DoctrineMigrations\\Version20261017000000'],'objects':['public.users'],'server_version':180004})
        self.assertFalse(result['fresh'])
        self.assertEqual(['DoctrineMigrations\\Version20261017130000'],result['pending'])
    def test_configure_preserves_generated_identity_on_resume(self):
        bootstrap=Bootstrap(self.store,Runner())
        first=bootstrap.configure(self.instance)
        before=(self.store.directory('persistent')/'env.json').read_bytes()
        bootstrap.configure(first)
        self.assertEqual(before,(self.store.directory('persistent')/'env.json').read_bytes())
    def test_checkpoint_requires_quiescence(self):
        with self.assertRaisesRegex(WorkflowError,'writers'):
            Database(self.store,CatalogRunner({})).checkpoint(self.instance,self.release)

    def test_preflight_uses_read_only_transaction_without_application_kernel(self):
        runner=CatalogRunner({'versions':[],'objects':[],'server_version':180004})
        Database(self.store,runner).inspect(self.instance,self.release)
        commands=' '.join(' '.join(args) for args,_ in runner.calls)
        self.assertNotIn('bin/console',commands)
        queries=[kwargs['input'] for _,kwargs in runner.calls if 'input' in kwargs]
        self.assertIn('BEGIN READ ONLY;',queries[0])
        self.assertIn("statement_timeout = '15s'",queries[0])

    def test_migration_failure_keeps_recovery_blocked(self):
        class FailingRunner(CatalogRunner):
            def run(self,args,**kwargs):
                if 'doctrine:migrations:migrate' in args:
                    raise WorkflowError('later migration failed after an earlier commit')
                return super().run(args,**kwargs)
        runner=FailingRunner({'versions':['DoctrineMigrations\\Version20261017000000','DoctrineMigrations\\Version20261017130000'],'objects':['public.users']})
        instance=dict(self.instance,writers_stopped=True,release={'revision':'old'})
        with self.assertRaises(WorkflowError):
            Database(self.store,runner).migrate(instance,self.release)
        saved=self.store.load('persistent')
        self.assertEqual('blocked',saved['phase'])
        self.assertEqual('old',saved['release']['revision'])
        self.assertNotIn('ready',saved['failure'])

    def test_adoption_transfer_refuses_unverified_checkpoint(self):
        with self.assertRaisesRegex(WorkflowError,'verified checkpoint'):
            Bootstrap(self.store,CatalogRunner({})).transfer(dict(self.instance,writers_stopped=True),self.release,{'verified':False})

    def test_environment_explicitly_targets_the_managed_storage_and_database(self):
        Bootstrap(self.store,Runner()).configure(dict(self.instance,kind='persistent'))
        env=json.loads((self.store.directory('persistent')/'env.json').read_text())
        self.assertEqual('prod',env['APP_ENV'])
        self.assertIn('@database:5432/baander?',env['DATABASE_URL'])
        self.assertEqual('/storage/media',env['MEDIA_STORAGE_PATH'])
        self.assertEqual(87,len(env['VAPID_PUBLIC_KEY']))
        self.assertEqual(43,len(env['VAPID_PRIVATE_KEY']))

    def test_adoption_rejects_unrecorded_service_names_before_inspection(self):
        with self.assertRaisesRegex(WorkflowError,'exact postgres'):
            Bootstrap(self.store,CatalogRunner({})).inventory(self.instance,{'containers':{'postgres':'p','redis':'r','app':'a','unexpected':'x'}},{})

    def test_adoption_accepts_and_inspects_explicit_frontend_identity(self):
        class InventoryRunner(CatalogRunner):
            def run(self,args,**kwargs):
                if 'inspect' in args:
                    return subprocess.CompletedProcess(args,0,json.dumps([{'Id':args[-1],'Config':{'Env':[]},'Mounts':[]}]),'')
                return super().run(args,**kwargs)
        with self.assertRaisesRegex(WorkflowError,'PGDATA volume'):
            Bootstrap(self.store,InventoryRunner({})).inventory(self.instance,{'containers':{'postgres':'p','redis':'r','app':'a','nginx':'n'}},{})

class AdoptionIdentityTest(unittest.TestCase):
    def setUp(self):
        temporary=tempfile.TemporaryDirectory()
        self.addCleanup(temporary.cleanup)
        self.store=StateStore(Path(temporary.name)/'state')
        self.instance={'id':'persistent','project':'test','hostname':'dev.baander.app',
                       'docker':{'executable':'docker','context':'test','daemon_id':'daemon'}}
        self.environment={'APP_SECRET':'original-app-secret','STREAM_URL_HMAC_SECRET':'original-stream-secret',
                          'VAPID_PRIVATE_KEY':'original-private-vapid','VAPID_PUBLIC_KEY':'original-public-vapid',
                          'AUTH_SPA_CLIENT_ID':'original-client','DB_PASSWORD':'original-database-secret',
                          'DB_USERNAME':'baander','DB_DATABASE':'baander',
                          'MEDIA_STORAGE_PATH':'/storage/media','CONVERT_STORAGE_PATH':'/storage/conversions'}
        self.adoption={'containers':{'app':'exact-app','postgres':'exact-postgres','redis':'exact-redis'},'worker_manifests':[]}

    def runner(self, *, app_env=None, observation=None, extra_mounts=()):
        environment=self.environment
        class InventoryRunner(CatalogRunner):
            def run(self,args,**kwargs):
                self.calls.append((args,kwargs))
                output=''
                if 'info' in args:
                    output='{"ID":"daemon"}'
                elif 'inspect' in args and '--format' in args:
                    output='0'
                elif 'inspect' in args and 'volume' not in args:
                    identifier=args[-1]
                    mounts=[]
                    entries=[]
                    if identifier=='exact-postgres':
                        mounts=[{'Type':'volume','Name':'existing-database','Destination':'/var/lib/postgresql/data'}]
                        entries=['PGDATA=/var/lib/postgresql/data/baanderdev','POSTGRES_PASSWORD='+environment['DB_PASSWORD'],'POSTGRES_USER=baander','POSTGRES_DB=baander']
                    elif identifier=='exact-redis':
                        mounts=[{'Type':'volume','Name':'existing-redis','Destination':'/data'}]
                    else:
                        mounts=[{'Type':'bind','Source':'/original/checkout','Destination':'/var/www/html'},
                                {'Type':'bind','Source':'/original/library','Destination':'/storage/media'},
                                {'Type':'bind','Source':'/original/conversions','Destination':'/storage/conversions'},*extra_mounts]
                        entries=[key+'='+value for key,value in (environment if app_env is None else app_env).items()]
                    output=json.dumps([{'Id':identifier,'Image':'existing-image','Config':{'Env':entries},'Mounts':mounts,'State':{'Running':False}}])
                elif 'exec' in args and any('bootEnv' in arg for arg in args):
                    output=json.dumps(observation)
                return subprocess.CompletedProcess(args,0,output,'')
        return InventoryRunner({})

    def test_wrong_app_secret_refuses_before_persisting_or_stopping_services(self):
        runner=self.runner()
        supplied=dict(self.environment,APP_SECRET='wrong-private-secret')
        with self.assertRaises(WorkflowError) as failure:
            Bootstrap(self.store,runner).configure(self.instance,environment=supplied,adoption=self.adoption)
        self.assertNotIn(supplied['APP_SECRET'],str(failure.exception))
        self.assertNotIn(self.environment['APP_SECRET'],str(failure.exception))
        self.assertFalse((self.store.directory('persistent')/'env.json').exists())
        self.assertFalse(any('stop' in args for args,_ in runner.calls))

    def test_matching_config_env_preserves_identity_without_dotenv_probe(self):
        runner=self.runner()
        configured=Bootstrap(self.store,runner).configure(self.instance,environment=self.environment,adoption=self.adoption)
        self.assertEqual('adopted',configured['initialization'])
        self.assertEqual(self.environment,json.loads((self.store.directory('persistent')/'env.json').read_text()))
        self.assertFalse(any('bootEnv' in arg for args,_ in runner.calls for arg in args))

    def test_dotenv_identity_digests_complete_missing_process_environment(self):
        hashes={key:hashlib.sha256(value.encode()).hexdigest() for key,value in self.environment.items() if key in ('APP_SECRET','STREAM_URL_HMAC_SECRET','VAPID_PRIVATE_KEY','VAPID_PUBLIC_KEY')}
        runner=self.runner(app_env={},observation=hashes)
        Bootstrap(self.store,runner).configure(self.instance,environment=self.environment,adoption=self.adoption)
        probes=[args for args,_ in runner.calls if any('bootEnv' in arg for arg in args)]
        self.assertEqual(1,len(probes))
        self.assertIn('exact-app',probes[0])
        self.assertNotIn('bin/console',' '.join(probes[0]))
        for key in hashes:
            self.assertNotIn(self.environment[key],' '.join(probes[0]))

    def test_unverifiable_dotenv_identity_refuses_without_mutation(self):
        runner=self.runner(app_env={},observation={'APP_SECRET':None})
        with self.assertRaises(WorkflowError):
            Bootstrap(self.store,runner).configure(self.instance,environment=self.environment,adoption=self.adoption)
        self.assertFalse((self.store.directory('persistent')/'env.json').exists())

    def test_config_env_mismatch_does_not_fall_back_to_dotenv(self):
        runner=self.runner(app_env=dict(self.environment,STREAM_URL_HMAC_SECRET='different-existing-stream'))
        with self.assertRaises(WorkflowError):
            Bootstrap(self.store,runner).configure(self.instance,environment=self.environment,adoption=self.adoption)
        self.assertFalse(any('bootEnv' in arg for args,_ in runner.calls for arg in args))

    def test_adoption_preserves_storage_root_and_read_only_media_source(self):
        configured=Bootstrap(self.store,self.runner()).configure(self.instance,environment=self.environment,adoption=self.adoption)
        self.assertEqual('/original/checkout/storage',configured['state_mounts']['storage']['source'])
        self.assertTrue(configured['state_mounts']['media']['read_only'])
        self.assertEqual('/original/library',configured['state_mounts']['media']['source'])

    def test_nested_adoption_source_and_conversions_refuse_before_persistence(self):
        for key in ('MEDIA_STORAGE_PATH','CONVERT_STORAGE_PATH'):
            with self.subTest(key=key):
                environment=dict(self.environment,**{key:'/var/www/html/storage/nested'})
                runner=self.runner()
                with self.assertRaisesRegex(WorkflowError,'overlaps.*storage'):
                    Bootstrap(self.store,runner).configure(self.instance,environment=environment,adoption=self.adoption)
                self.assertFalse((self.store.directory('persistent')/'env.json').exists())
                self.assertFalse(any('stop' in args for args,_ in runner.calls))

    def test_transfer_copies_writable_archives_without_host_binds_and_retains_sources(self):
        runner=self.runner()
        bootstrap=Bootstrap(self.store,runner)
        configured=bootstrap.configure(self.instance,environment=self.environment,adoption=self.adoption)
        configured['writers_stopped']=True
        transferred=bootstrap.transfer(configured,{'app_image':'fixture-image'},{'verified':True,'directory':str(self.store.root/'checkpoint')})
        self.assertEqual(configured['state_mounts']['media'],transferred['state_mounts']['media'])
        self.assertEqual('existing-redis',transferred['volumes']['redis'])
        self.assertEqual('existing-database',transferred['volumes']['database'])
        self.assertEqual('volume',transferred['state_mounts']['storage']['type'])
        copies=[args for args,_ in runner.calls if 'cp' in args]
        self.assertTrue(any(args[-2].endswith('/storage.tar') for args in copies))
        self.assertTrue(any(args[-2].endswith('/transcode_state.tar') for args in copies))
        self.assertFalse(any(args[-2].endswith('/media.tar') for args in copies))
        self.assertFalse(any('type=bind' in arg and 'checkpoint' in arg for args,_ in runner.calls for arg in args))

    def test_separate_transcode_mount_is_inventoried_and_preserved_at_transfer(self):
        for kind in ('volume','bind'):
            with self.subTest(kind=kind):
                mount={'Type':kind,'Destination':'/var/www/html/var/transcode_state','Name':'original-transcode','Source':'/original/transcode'}
                runner=self.runner(extra_mounts=[mount])
                bootstrap=Bootstrap(self.store,runner)
                configured=bootstrap.inventory(self.instance,self.adoption,self.environment)
                recorded=configured['state_mounts']['transcode_state']
                self.assertEqual(kind,recorded['type'])
                self.assertEqual('original-transcode' if kind=='volume' else '/original/transcode',recorded['source'])
                configured['writers_stopped']=True
                transferred=bootstrap.transfer(configured,{'app_image':'fixture-image'},{'verified':True,'directory':str(self.store.root/'checkpoint')})
                copies=[args for args,_ in runner.calls if 'cp' in args and args[-2].endswith('/transcode_state.tar')]
                if kind=='volume':
                    self.assertEqual('original-transcode',transferred['volumes']['transcode_state'])
                    self.assertEqual([],copies)
                else:
                    self.assertEqual(1,len(copies))
                    self.assertEqual('volume',transferred['state_mounts']['transcode_state']['type'])

class BootstrapMutationTest(unittest.TestCase):
    setUp=BootstrapTest.setUp
    def test_fresh_setup_records_mutation_before_command_and_preserves_failure(self):
        from unittest.mock import patch
        store=self.store
        class SetupRunner(CatalogRunner):
            def run(self,args,**kwargs):
                if 'app:dev:setup' in args:
                    if store.load('persistent').get('database_mutation_started') is not True:
                        raise AssertionError('mutation boundary not durable before setup')
                    raise WorkflowError('setup interrupted')
                return super().run(args,**kwargs)
        with patch('dev_environment.bootstrap.Database.inspect',return_value={'fresh':True}), patch('dev_environment.bootstrap.Runtime.prepare'):
            with self.assertRaises(WorkflowError):
                Bootstrap(self.store,SetupRunner({})).initialize(self.instance,self.release)
        self.assertTrue(self.store.load('persistent')['database_mutation_started'])
        self.assertEqual('blocked',self.store.load('persistent')['phase'])
