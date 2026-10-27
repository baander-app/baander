"""Serialize release activation and preserve interrupted database boundaries."""
from __future__ import annotations

import json
import time

from .bootstrap import Bootstrap
from .build import Builder, SourceUnavailable
from .database import Database
from .host import Gateway
from .model import StateStore, WorkflowError
from .process import Docker, Runner
from .runtime import Runtime
from .workers import Workers


INTERRUPTED_PHASES = {'database_mutating', 'quiescing', 'checkpointing', 'activating', 'blocked'}


class Updater:
    def __init__(self, store: StateStore, runner: Runner):
        self.store, self.runner = store, runner
        self.builder = Builder(store, runner)
        self.runtime = Runtime(store, runner)
        self.gateway = Gateway(store, runner)
        self.database = Database(store, runner)
        self.bootstrap = Bootstrap(store, runner)
        self.workers = Workers(store, runner)

    def head(self, instance: dict) -> str:
        result = self.runner.run(['git', 'ls-remote', '--exit-code', '--', instance['repository'], 'refs/heads/master'], timeout=60)
        fields = result.stdout.split()
        if len(fields) != 2 or fields[1] != 'refs/heads/master':
            raise WorkflowError('Remote master could not be resolved.')
        return fields[0]

    def save(self, instance: dict, **values) -> None:
        instance.update(values)
        self.store.save(instance['id'], instance)

    def update(self, identity: str, *, automatic: bool = False, retry: bool = False) -> None:
        with self.store.lock(identity):
            instance = self.store.load(identity)
            if instance is None:
                raise WorkflowError('Configure this development instance first.')
            if instance['kind'] == 'persistent' and not self.store.enabled(identity):
                if automatic:
                    return
                raise WorkflowError('The persistent instance is disabled; resume it with make dev-up.')
            if instance.get('phase') in INTERRUPTED_PHASES:
                self.save(instance, phase='blocked', failure='An interrupted activation requires explicit recovery; no release was started.')
                raise WorkflowError(instance['failure'])
            if automatic and time.time() < instance.get('next_check_at', 0):
                return
            try:
                detected = self.head(instance) if instance['kind'] == 'persistent' else None
            except WorkflowError:
                delay = min(300, max(60, instance.get('retry_delay', 30) * 2))
                self.save(instance, retry_delay=delay, next_check_at=time.time() + delay,
                          failure='Remote unavailable; the running release is unchanged.', failure_stage='fetch')
                raise
            observation = {'detected_revision': detected}
            if instance.get('failure_stage') == 'fetch':
                observation.update(failure=None, failure_stage=None)
            if any(key not in instance or instance[key] != value for key, value in observation.items()):
                self.save(instance, **observation)
            if detected and not retry and instance.get('failed_revision') == detected:
                return
            retained_release = None
            if instance.get('phase') == 'ready' and instance.get('running_revision'):
                if self.runtime.running(instance) and self.workers.running(instance):
                    if detected == instance.get('running_revision') and not retry:
                        return
                else:
                    self.save(instance, readiness=None)
                    if detected == instance.get('running_revision') and not retry:
                        retained_release = instance.get('release')
            previous_phase = instance.get('phase')
            was_ready = previous_phase == 'ready' and bool(instance.get('running_revision')) and bool(instance.get('readiness'))
            self.save(instance, phase='preparing', target_revision=detected, failure=None,
                      database_mutation_started=False, database_mutation_confirmed=False)
            try:
                release = retained_release if retained_release is not None else self.builder.prepare(instance)
            except SourceUnavailable:
                instance = self.store.load(identity)
                delay = min(300, max(60, instance.get('retry_delay', 30) * 2))
                self.save(instance, phase=previous_phase, retry_delay=delay, next_check_at=time.time() + delay,
                          failure='Source download unavailable; the previous runtime is unchanged.', failure_stage='fetch')
                raise
            except BaseException:
                instance = self.store.load(identity)
                self.save(instance, phase='preparation_failed', failed_revision=instance.get('target_revision', detected),
                          failure='Release preparation failed; the previous runtime is unchanged. Inspect private build logs.')
                raise
            self.save(instance, target_revision=release['revision'], prepared_release=release, retry_delay=0, next_check_at=0, failure_stage=None)
            if instance['kind'] == 'persistent' and not self.store.enabled(identity):
                self.save(instance, phase='ready' if was_ready else 'stopped')
                return
            try:
                self.activate(instance, release)
            except BaseException:
                current = self.store.load(identity)
                if current.get('phase') == 'preflight':
                    self.save(current, phase='ready' if was_ready else ('stopped' if previous_phase == 'stopped' else 'preparation_failed'),
                              failed_revision=None if retained_release is not None else release['revision'],
                              failure='Release preflight failed; existing data and runtime were not changed.')
                elif current.get('phase') == 'checkpointing' and current.get('release'):
                    try:
                        current.update(self.runtime.write(current, current['release']))
                        self.start_release(current, current['release'])
                        self.save(current, failed_revision=release['revision'],
                                  failure='Checkpoint or rehearsal failed; the previous release was restarted without database changes.')
                    except BaseException:
                        self.save(current, phase='blocked', failure='Restoring service before migration was not confirmed; inspect the saved attempt.')
                elif current.get('phase') not in ('blocked', 'ready'):

                    self.save(current, phase='blocked', failure='Activation was not confirmed. Inspect the saved attempt before recovery.')
                raise
            if instance['kind'] == 'persistent':
                current = self.store.load(identity)
                try:
                    self.save(current, detected_revision=self.head(instance))
                except WorkflowError:
                    self.save(current, failure='Release is ready; checking for a newer master will retry on the next tick.')

    def freeze(self, instance: dict) -> None:
        self.save(instance, phase='quiescing', writers_stopped=False, readiness=None,
                  last_ready_revision=instance.get('running_revision') or instance.get('last_ready_revision'), running_revision=None)
        self.gateway.route(instance, maintenance=True)
        docker = Docker(self.runner, instance['docker'])
        docker.validate()
        services = ['app'] + (['vite'] if instance['kind'] == 'preview' else [])
        docker.compose(instance, 'stop', '--timeout', '35', *services, timeout=90)
        self.workers.retire(instance)
        self.save(instance, writers_stopped=True)

    def freeze_adoption(self, instance: dict) -> None:
        self.save(instance, phase='quiescing', writers_stopped=False, readiness=None,
                  last_ready_revision=instance.get('running_revision') or instance.get('last_ready_revision'), running_revision=None)
        docker = Docker(self.runner, instance['docker'])
        docker.validate()
        containers = instance['adoption']['containers']
        for role in ('nginx', 'app'):
            if role in containers:
                docker.run('stop', '--time', '35', containers[role], timeout=90)
        for record in instance['adoption']['worker_manifests']:
            if not all(key in record for key in ('directory', 'operator_image', 'socket', 'network')):
                raise WorkflowError('Adoption requires each original worker controller record and its network.')
            original = dict(instance, workers=record, network=record['network'])
            self.workers.retire(original)
        observation = self.database.sql(instance, "BEGIN READ ONLY; SELECT json_build_object('remaining', "
            "(SELECT count(*) FROM worker_deployment_boots WHERE retired_at IS NULL) + "
            "(SELECT count(*) FROM worker_deployment_leases WHERE state='active')); COMMIT;")
        if observation.get('remaining') != 0:
            raise WorkflowError('Unaccounted worker ownership prevents adoption; retain the original controller records.')
        self.save(instance, writers_stopped=True)

    def activate(self, instance: dict, release: dict) -> None:
        previous = instance.get('release')
        adopting = instance.get('adoption') and not instance.get('ownership_transferred')
        if instance.get('postgres_image'):
            release['postgres_image'] = instance['postgres_image']
        elif previous:
            release['postgres_image'] = previous['postgres_image']
        if instance.get('redis_image'):
            release['redis_image'] = instance['redis_image']
        elif previous and previous.get('redis_image'):
            release['redis_image'] = previous['redis_image']
        self.save(instance, prepared_release=release, phase='preflight')
        if adopting:
            instance.update(self.bootstrap.initialize(instance, release))
            catalog = self.database.inspect(instance, release)
            self.freeze_adoption(instance)
        elif previous:
            instance.update(self.runtime.write(instance, previous))
            self.runtime.dependencies(instance)
            self.gateway.start()
            catalog = self.database.inspect(instance, release)
            self.freeze(instance)
        else:
            instance.update(self.runtime.write(instance, release))
            self.gateway.start()
            self.gateway.route(instance, maintenance=True)
            self.runtime.dependencies(instance)
            self.save(instance, writers_stopped=True)
            catalog = self.database.inspect(instance, release)
        checkpoint = None
        if not catalog['fresh'] and (catalog['pending'] or adopting):
            self.save(instance, phase='checkpointing')
            checkpoint = self.database.checkpoint(instance, release)
            self.save(instance, checkpoint=checkpoint)
            checkpoint = self.database.rehearse(instance, release, checkpoint)
            self.save(instance, checkpoint=checkpoint)
        if adopting:
            self.save(instance, phase='quiescing')
            instance.update(self.bootstrap.transfer(instance, release, checkpoint))
        instance.update(self.runtime.write(instance, release))
        self.runtime.dependencies(instance)
        if adopting:
            self.gateway.start()
            self.gateway.route(instance, maintenance=True)
        if instance.get('initialization') not in ('complete', 'adopted'):
            instance.update(self.bootstrap.initialize(instance, release))
        elif catalog['pending']:
            self.runtime.prepare(instance, release)
            instance.update(self.database.migrate(instance, release, checkpoint))
        self.start_release(instance, release)

    def start_release(self, instance: dict, release: dict) -> None:
        self.save(instance, phase='activating', writers_stopped=False)
        self.runtime.dependencies(instance)
        self.runtime.up(instance, release)
        self.workers.prepare(instance, release)
        self.workers.start(instance)
        self.workers.ready(instance)
        self.gateway.route(instance, maintenance=False)
        deadline = time.monotonic() + 90
        while True:
            try:
                readiness = self.runtime.ready(instance)
                break
            except WorkflowError:
                if time.monotonic() >= deadline:
                    self.gateway.route(instance, maintenance=True)
                    raise
                time.sleep(2)
        self.save(instance, phase='ready', release=release, running_revision=release['revision'],
                  worker_snapshot=release['revision'], readiness=dict(readiness, workers_ready=True),
                  postgres_image=release['postgres_image'], redis_image=release.get('redis_image'), failed_revision=None, failure=None,
                  database_mutation_started=False, database_mutation_confirmed=False)

    def down(self, identity: str) -> None:
        self.store.set_enabled(identity, False)
        with self.store.lock(identity):
            instance = self.store.load(identity)
            if not instance or not instance.get('compose_file'):
                return
            blocked = instance.get('phase') in INTERRUPTED_PHASES
            self.freeze(instance)
            self.runtime.down(instance)
            self.gateway.remove(instance)
            self.save(instance, phase='blocked' if blocked else 'stopped', readiness=None)

    def recover(self, identity: str) -> None:
        with self.store.lock(identity):
            instance = self.store.load(identity)
            if not instance or instance.get('phase') != 'blocked' or not instance.get('prepared_release'):
                raise WorkflowError('Recovery requires a blocked attempt with retained release artifacts.')
            release = instance['prepared_release']
            adopting = instance.get('adoption') and not instance.get('ownership_transferred')
            if adopting:
                docker = Docker(self.runner, instance['docker'])
                docker.validate()
                # Original ownership must still match before restarting a dependency.
                for role, saved in instance['adoption_inventory'].items():
                    current = json.loads(docker.run('inspect', saved['Id']).stdout)[0]
                    if any(current.get(key) != saved.get(key) for key in ('Id', 'Image', 'Mounts')):
                        raise WorkflowError('Original adoption ownership changed; reconcile it before recovery.')
                for role in ('postgres', 'redis'):
                    docker.run('start', instance['adoption_inventory'][role]['Id'])
                docker.run('exec', instance['adoption_inventory']['postgres']['Id'], '/bin/sh', '-ec',
                           'for n in $(seq 1 60); do pg_isready -h 127.0.0.1 -U "$POSTGRES_USER" -d "$POSTGRES_DB" && exit 0; sleep 1; done; exit 1', timeout=70)
            else:
                instance.update(self.runtime.write(instance, release))
                self.runtime.dependencies(instance)
                self.gateway.start()
                self.gateway.route(instance, maintenance=True)
            catalog = self.database.inspect(instance, release)
            if catalog['pending'] and instance.get('database_mutation_started'):
                raise WorkflowError('Database reconciliation is still required. Preserve the checkpoint; no migration or reset was attempted.')
            if adopting or not instance.get('database_mutation_started'):
                try:
                    self.activate(instance, release)
                except BaseException:
                    current = self.store.load(identity)
                    self.save(current, phase='blocked', failure='Recovery was not confirmed; retain the saved attempt and checkpoint.')
                    raise
                return
            self.save(instance, migration_hashes=catalog['migration_hashes'])
            self.freeze(instance)
            if instance.get('initialization') == 'initializing':
                instance.update(self.bootstrap.initialize(instance, release))
            self.start_release(instance, release)
