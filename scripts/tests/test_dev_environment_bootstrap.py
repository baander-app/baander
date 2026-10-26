"""Catalog refusal and resumable bootstrap contracts."""
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
