#!/usr/bin/env python3
"""Reap a deliberately orphaned real helper and inspect its alarm exit."""
import ctypes
import json
import os
import signal
import subprocess
import sys
import time

# Adopt the orphan in this disposable supervisor, so the drill leaves no zombie.
if ctypes.CDLL(None, use_errno=True).prctl(36, 1, 0, 0, 0) != 0:
    raise OSError(ctypes.get_errno(), 'PR_SET_CHILD_SUBREAPER')
parent = subprocess.Popen([sys.argv[1], sys.argv[2], 'orphan-parent'], stdout=subprocess.PIPE, text=True)
helper_pid = int(parent.stdout.readline())
parent_code = parent.wait(timeout=4)
if parent_code != -signal.SIGKILL:
    raise RuntimeError(f'Parent did not exit abruptly: {parent_code}')
deadline = time.monotonic() + 4
while time.monotonic() < deadline:
    pid, status = os.waitpid(helper_pid, os.WNOHANG)
    if pid:
        if not os.WIFSIGNALED(status) or os.WTERMSIG(status) != signal.SIGALRM:
            raise RuntimeError(f'Helper did not exit from its own alarm: {status}')
        print(json.dumps({'parent_signal': signal.SIGKILL, 'helper_signal': signal.SIGALRM, 'reaped': True}))
        sys.exit(0)
    time.sleep(0.01)
os.kill(helper_pid, signal.SIGKILL)
os.waitpid(helper_pid, 0)
raise RuntimeError('Orphan helper outlived its finite alarm')
