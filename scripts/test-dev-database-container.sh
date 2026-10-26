#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
# Every name and database belongs to this disposable drill, never a saved instance.
run_id="baander-dev-database-$(date +%s)-$$"
export BAANDER_DATABASE_DRILL_ID="$run_id"
export BAANDER_DATABASE_DRILL_IMAGE="${BAANDER_DATABASE_TEST_IMAGE:-baander-database:latest}"
python3 - <<'PY'
import json, os, subprocess, sys, tempfile
from pathlib import Path
sys.path.insert(0,str(Path.cwd()/'scripts'))
from dev_environment.database import Database
from dev_environment.model import StateStore, WorkflowError
from dev_environment.process import Runner
name=os.environ['BAANDER_DATABASE_DRILL_ID']
image=os.environ['BAANDER_DATABASE_DRILL_IMAGE']
def docker(*args, **kwargs):
    result=subprocess.run(['docker',*args],text=True,capture_output=True,**kwargs)
    if result.returncode:
        raise RuntimeError(result.stderr or result.stdout)
    return result.stdout.strip()
def sql(container, statement):
    return docker('exec','-i',container,'psql','-X','-qAt','-v','ON_ERROR_STOP=1','-U','baander','-d','baander',input=statement)
with tempfile.TemporaryDirectory(prefix=name) as temporary:
    root=Path(temporary)
    (root/'migrations').mkdir()
    (root/'migrations/Version20261017000000.php').write_text('<?php')
    context=docker('context','show')
    daemon=json.loads(docker('info','--format','{{json .}}'))['ID']
    instance={'id':'persistent','project':name,'docker':{'executable':'docker','context':context,'daemon_id':daemon},
              'adoption':{'containers':{'postgres':name}}}
    release={'source':str(root)}
    database=Database(StateStore(root/'state'),Runner())
    try:
        for container in (name,name+'-restore'):
            docker('run','-d','--name',container,'--network','none','--tmpfs','/var/lib/postgresql','-e','PGDATA=/var/lib/postgresql/data/baanderdev',
                   '-e','POSTGRES_USER=baander','-e','POSTGRES_DB=baander','-e','POSTGRES_PASSWORD=disposable',image)
            docker('exec',container,'sh','-ec','for n in $(seq 1 60); do pg_isready -h 127.0.0.1 -U baander -d baander && exit 0; sleep 1; done; exit 1')
        assert database.inspect(instance,release)['fresh']
        sql(name,'CREATE EXTENSION pgroonga; CREATE TABLE preserved(id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY, value text); INSERT INTO preserved(value) VALUES (\'retained library identity\'); CREATE INDEX preserved_search ON preserved USING pgroonga(value);')
        try:
            database.inspect(instance,release)
            raise AssertionError('populated unversioned database was accepted')
        except WorkflowError as error:
            assert 'unversioned' in str(error)
        assert sql(name,"SELECT to_regclass('doctrine_migration_versions') IS NULL")=='t'
        sql(name,"CREATE TABLE doctrine_migration_versions(version text PRIMARY KEY); INSERT INTO doctrine_migration_versions VALUES ('DoctrineMigrations\\Version20261017000000');")
        assert not database.inspect(instance,release)['pending']
        docker('exec',name,'pg_dump','-U','baander','-d','baander','-Fc','-f','/tmp/checkpoint.dump')
        docker('cp',name+':/tmp/checkpoint.dump',str(root/'checkpoint.dump'))
        docker('cp',str(root/'checkpoint.dump'),name+'-restore:/tmp/checkpoint.dump')
        docker('exec',name+'-restore','pg_restore','-U','baander','-d','baander','--exit-on-error','--single-transaction','/tmp/checkpoint.dump')
        restored=dict(instance,adoption={'containers':{'postgres':name+'-restore'}})
        original=database.inspect(instance,release)
        result=database.inspect(restored,release)
        for key in ('versions','objects','extensions','owner'):
            assert original[key]==result[key],key
        assert sql(name+'-restore',"SELECT id FROM preserved WHERE value &@~ 'library'")=='1'
        assert sql(name+'-restore',"INSERT INTO preserved(value) VALUES ('next') RETURNING id")=='2'
        sql(name,"INSERT INTO doctrine_migration_versions VALUES ('DoctrineMigrations\\Version19990101000000');")
        try:
            database.inspect(instance,release)
            raise AssertionError('former migration accepted')
        except WorkflowError:
            pass
        assert sql(name,'SELECT count(*) FROM preserved')=='1'
        (root/'corrupt.dump').write_text('corrupt')
        docker('cp',str(root/'corrupt.dump'),name+'-restore:/tmp/corrupt.dump')
        result=subprocess.run(['docker','exec',name+'-restore','pg_restore','-U','baander','-d','baander','--exit-on-error','/tmp/corrupt.dump'],capture_output=True)
        assert result.returncode!=0
        print('PASS: direct read-only refusal, unchanged metadata/data, complete dump/restore, PGroonga search and identity sequence, corrupt archive refusal')
    finally:
        for container in (name,name+'-restore'):
            subprocess.run(['docker','rm','-fv',container],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
PY
