"""Private instance records and process-owned lifecycle locks."""
from __future__ import annotations

from contextlib import contextmanager
import fcntl
import hashlib
import json
import os
from pathlib import Path
import re
import tempfile


class WorkflowError(RuntimeError):
    """An operation cannot safely complete; its message names the next action."""


def preview_id(worktree: Path, repository: str) -> str:
    identity = repository + '\0' + str(worktree.resolve())
    return 'preview-' + hashlib.sha256(identity.encode()).hexdigest()[:16]


def clean_environment(source: dict[str, str] | None = None) -> dict[str, str]:
    source = os.environ if source is None else source
    allowed = {'PATH', 'HOME', 'USER', 'LOGNAME', 'LANG', 'LC_ALL', 'LC_CTYPE',
               'SSH_AUTH_SOCK', 'XDG_RUNTIME_DIR', 'DBUS_SESSION_BUS_ADDRESS',
               'SSL_CERT_FILE', 'SSL_CERT_DIR', 'HTTP_PROXY', 'HTTPS_PROXY', 'NO_PROXY',
               'http_proxy', 'https_proxy', 'no_proxy'}
    return {key: value for key, value in source.items() if key in allowed}


def atomic_json(path: Path, value: dict) -> None:
    if path.is_symlink():
        raise WorkflowError(f'Refusing symlink state file: {path}')
    path.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
    fd, temporary = tempfile.mkstemp(prefix='.' + path.name, dir=path.parent)
    try:
        with os.fdopen(fd, 'w') as stream:
            json.dump(value, stream, indent=2, sort_keys=True)
            stream.write('\n')
            stream.flush()
            os.fsync(stream.fileno())
        os.replace(temporary, path)
        directory = os.open(path.parent, os.O_RDONLY | os.O_DIRECTORY)
        try:
            os.fsync(directory)
        finally:
            os.close(directory)
    finally:
        Path(temporary).unlink(missing_ok=True)


class StateStore:
    def __init__(self, root: Path):
        self.root = root.expanduser().absolute()
        if self.root.is_symlink():
            raise WorkflowError('The development state root must not be a symlink.')

    @classmethod
    def default(cls) -> StateStore:
        state_home = Path(os.environ.get('XDG_STATE_HOME', str(Path.home() / '.local/state')))
        return cls(state_home / 'baander/development')

    def directory(self, instance_id: str) -> Path:
        if not re.fullmatch(r'persistent|preview-[0-9a-f]{16}', instance_id):
            raise WorkflowError('Invalid development instance identifier.')
        directory = self.root / instance_id
        if directory.is_symlink():
            raise WorkflowError(f'Refusing symlink instance directory: {directory}')
        return directory

    def _prepare(self, instance_id: str) -> Path:
        directory = self.directory(instance_id)
        directory.mkdir(mode=0o700, parents=True, exist_ok=True)
        if directory.stat().st_uid != os.getuid():
            raise WorkflowError('The development instance is owned by another user.')
        directory.chmod(0o700)
        return directory

    def load(self, instance_id: str) -> dict | None:
        path = self.directory(instance_id) / 'state.json'
        if path.is_symlink():
            raise WorkflowError('Refusing symlink instance state.')
        if not path.exists():
            return None
        try:
            value = json.loads(path.read_text())
            if not isinstance(value, dict) or value.get('id') != instance_id:
                raise ValueError('instance identity does not match')
            return value
        except (OSError, ValueError) as error:
            raise WorkflowError(f'Cannot read saved instance {instance_id}; preserve it for recovery: {error}') from error

    def save(self, instance_id: str, record: dict) -> None:
        if record.get('id') != instance_id:
            raise WorkflowError('Instance record identity does not match its destination.')
        atomic_json(self._prepare(instance_id) / 'state.json', record)

    def set_enabled(self, instance_id: str, enabled: bool) -> None:
        atomic_json(self._prepare(instance_id) / 'intent.json', {'enabled': bool(enabled)})

    def enabled(self, instance_id: str) -> bool:
        path = self.directory(instance_id) / 'intent.json'
        if not path.exists():
            return False
        try:
            if path.is_symlink():
                raise ValueError('symlink intent file')
            value = json.loads(path.read_text())
            if not isinstance(value.get('enabled'), bool):
                raise ValueError('invalid enabled intent')
            return value['enabled']
        except (ValueError, OSError, AttributeError) as error:
            raise WorkflowError('Invalid updater intent; inspect saved state before continuing.') from error

    @contextmanager
    def lock(self, instance_id: str, blocking: bool = True):
        path = self._prepare(instance_id) / 'operation.lock'
        fd = os.open(path, os.O_CREAT | os.O_RDWR | os.O_NOFOLLOW, 0o600)
        try:
            flags = fcntl.LOCK_EX | (0 if blocking else fcntl.LOCK_NB)
            try:
                fcntl.flock(fd, flags)
            except BlockingIOError as error:
                raise WorkflowError('Wait for another operation on this instance to finish.') from error
            yield
        finally:
            os.close(fd)


def resolve_instance(store: StateStore, worktree: Path, repository: str,
                     selected: str | None = None) -> dict:
    identity = selected if selected is not None else preview_id(worktree, repository)
    record = store.load(identity)
    if record is None:
        command = 'dev-up' if identity == 'persistent' else 'preview-up'
        raise WorkflowError(f'No configured instance. Run make {command} first.')
    if record.get('repository') not in (None, repository):
        raise WorkflowError('The saved instance belongs to another repository.')
    return record
