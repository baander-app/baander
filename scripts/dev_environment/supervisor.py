from pathlib import Path
import os
import shutil
import sys
from string import Template

from .model import StateStore, WorkflowError
from .process import Runner


def systemd_argument(value: str) -> str:
    if '\n' in value or '\r' in value or '\0' in value:
        raise WorkflowError('Controller paths must not contain control characters.')
    return '"' + value.replace('\\', '\\\\').replace('"', '\\"').replace('%', '%%').replace('$', '$$') + '"'


def systemd_search_path(value: str) -> str:
    if any(ord(character) < 32 or ord(character) == 127 for character in value):
        raise WorkflowError('Controller search paths must not contain control characters.')
    entries = dict.fromkeys(entry for entry in value.split(os.pathsep) if Path(entry).is_absolute())
    if not entries:
        raise WorkflowError('Controller search paths require at least one absolute directory.')
    # Environment= expands systemd specifiers, but does not expand dollar variables.
    assignment = 'PATH=' + os.pathsep.join(entries)
    return '"' + assignment.replace('\\', '\\\\').replace('"', '\\"').replace('%', '%%') + '"'


class Supervisor:
    def __init__(self, store: StateStore, runner: Runner):
        self.store, self.runner = store, runner
        self.unit = 'baander-development-' + store.scope

    def install(self) -> None:
        search_path = systemd_search_path(os.environ.get('PATH', os.defpath))
        self.runner.run(['systemctl', '--user', 'show-environment'])
        root = Path(__file__).resolve().parents[2]
        controller = self.store.root / 'controller'
        controller.mkdir(mode=0o700, parents=True, exist_ok=True)
        for relative in ('scripts/dev_environment', 'docker/dev'):
            shutil.copytree(root / relative, controller / relative, dirs_exist_ok=True,
                            ignore=shutil.ignore_patterns('__pycache__', '*.key', '*.pem'))
        shutil.copyfile(root / 'scripts/dev-environment.py', controller / 'scripts/dev-environment.py')
        unit_home = Path(os.environ.get('XDG_CONFIG_HOME', str(Path.home() / '.config'))) / 'systemd/user'
        unit_home.mkdir(parents=True, exist_ok=True)
        arguments = [sys.executable, str(controller / 'scripts/dev-environment.py'), '--state-root', str(self.store.root),
                     'update', '--instance', 'persistent', '--automatic']
        command = ' '.join(systemd_argument(value) for value in arguments)
        template = (root / 'docker/dev/systemd/baander-development.service.template').read_text()
        (unit_home / (self.unit + '.service')).write_text(Template(template).substitute(COMMAND=command, SEARCH_PATH=search_path))
        timer = (root / 'docker/dev/systemd/baander-development.timer').read_text()
        (unit_home / (self.unit + '.timer')).write_text(timer.replace('Unit=baander-development.service', 'Unit=' + self.unit + '.service'))
        self.runner.run(['systemctl', '--user', 'daemon-reload'])

    def enable(self) -> None:
        self.install()
        self.store.set_enabled('persistent', True)
        try:
            self.runner.run(['systemctl', '--user', 'enable', '--now', self.unit + '.timer'])
        except WorkflowError:
            self.store.set_enabled('persistent', False)
            raise

    def disable(self) -> None:
        self.store.set_enabled('persistent', False)
        # Stop the timer, not the service: an active migration must finish under its lock.
        self.runner.run(['systemctl', '--user', 'disable', '--now', self.unit + '.timer'], check=False)
