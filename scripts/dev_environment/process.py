"""Explicit process and Docker targeting, independent of a caller's shell."""
from __future__ import annotations

import json
import os
from pathlib import Path
import subprocess

from .model import WorkflowError, clean_environment


class Runner:
    def run(self, arguments: list[str], *, cwd: Path | None = None,
            env: dict[str, str] | None = None, input: str | None = None,
            timeout: float | None = 120, check: bool = True, log_path: Path | None = None, interactive: bool = False) -> subprocess.CompletedProcess:
        environment = clean_environment()
        if env:
            environment.update(env)
        try:
            if interactive:
                result = subprocess.run(arguments, cwd=cwd, env=environment, input=input,
                                        text=True, timeout=timeout)
            elif log_path is None:
                result = subprocess.run(arguments, cwd=cwd, env=environment, input=input,
                                        text=True, capture_output=True, timeout=timeout)
            else:
                descriptor = os.open(log_path, os.O_CREAT | os.O_WRONLY | os.O_TRUNC | os.O_NOFOLLOW, 0o600)
                with os.fdopen(descriptor, 'w') as output:
                    result = subprocess.run(arguments, cwd=cwd, env=environment, input=input,
                                            text=True, stdout=output, stderr=subprocess.STDOUT, timeout=timeout)
        except (OSError, subprocess.TimeoutExpired) as error:
            raise WorkflowError(f'{Path(arguments[0]).name} did not complete; inspect the recorded operation before retrying.') from error
        if check and result.returncode:
            # Command arguments and child output can contain credentials.
            detail = f' See {log_path}.' if log_path else ''
            raise WorkflowError(f'{Path(arguments[0]).name} exited with status {result.returncode}.' + detail)
        return result


class Docker:
    def __init__(self, runner: Runner, configuration: dict):
        self.runner = runner
        self.configuration = configuration

    def run(self, *arguments: str, **kwargs) -> subprocess.CompletedProcess:
        return self.runner.run([self.configuration['executable'], '--context',
                                self.configuration['context'], *arguments], **kwargs)

    def validate(self) -> dict:
        information = json.loads(self.run('info', '--format', '{{json .}}').stdout)
        if information.get('ID') != self.configuration['daemon_id']:
            raise WorkflowError('Docker daemon identity changed; inspect instance configuration before continuing.')
        return information

    def compose(self, instance: dict, *arguments: str, **kwargs) -> subprocess.CompletedProcess:
        return self.run('compose', '--project-name', instance['project'],
                        '--file', instance['compose_file'], *arguments, **kwargs)
