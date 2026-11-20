"""Run from the repository root: python3 tests/Fixtures/LogViewer/terminal_pty.py."""
import fcntl
import os
import pty
import select
import signal
import struct
import subprocess
import termios
import time


def receive(fd, timeout=2, until_screen=True):
    end = time.monotonic() + timeout
    output = b''
    while time.monotonic() < end:
        if select.select([fd], [], [], 0.05)[0]:
            try:
                output += os.read(fd, 65536)
            except OSError:
                break
            if until_screen and b'Baander logs' in output:
                break
    return output


def run(action):
    master, slave = pty.openpty()
    before = termios.tcgetattr(slave)
    fcntl.ioctl(slave, termios.TIOCSWINSZ, struct.pack('HHHH', 24, 80, 0, 0))
    env = dict(os.environ, LOG_PTY_THROW='1' if action == 'exception' else '0', LOG_PTY_SQL='1' if action == 'paged-sql' else '0', LOG_PTY_CHANNELS='1' if action == 'channels' else '0')
    args = ['php', 'tests/Fixtures/LogViewer/terminal.php', 'app:log:view']
    if action == 'paged-sql':
        args += ['--source=archive', '--level=ERROR', '--search=error', '--channel=worker', '--channel=scheduler', '--correlation-id=abc', '--since=2026-11-01T00:00:00Z', '--until=2026-11-20T00:00:00Z', '--limit=12', '--order=asc']
    proc = subprocess.Popen(args, stdin=slave, stdout=slave, stderr=slave, env=env)
    try:
        output = receive(master)
        assert b'Baander logs' in output, output
        if action == 'resize':
            fcntl.ioctl(slave, termios.TIOCSWINSZ, struct.pack('HHHH', 12, 35, 0, 0))
            os.kill(proc.pid, signal.SIGWINCH)
            output = receive(master)
            assert b'Baander logs' in output
            for line in output.split(b'\r\n')[1:]:
                assert len(line.decode(errors='replace')) <= 35, line
        if action == 'channels':
            os.write(master, b'cworker, scheduler\r')
            output = receive(master, 0.5, False)
            assert b'CHANNELS:["worker","scheduler"]' in output, output
            os.write(master, b'c\r')
            output = receive(master, 0.5, False)
            assert b'CHANNELS:[]' in output, output
            os.write(master, b'q')
        elif action == 'paged-sql':
            os.write(master, b']:SELECT count(*) FROM logs\r')
            output += receive(master, 0.5, False)
            assert b'SQL_FILTERS_OK' in output, output
            os.write(master, b'q')
        elif action in ('quit', 'resize'):
            os.write(master, b'q')
        elif action == 'eof':
            os.write(master, b'\x04')
        elif action == 'exception':
            os.write(master, b'F')
        else:
            os.kill(proc.pid, signal.SIGINT if action == 'interrupt' else signal.SIGTERM)
        proc.wait(timeout=8)
        output += receive(master, 0.4, False)
        assert termios.tcgetattr(slave) == before, action + ': terminal mode changed'
        assert b'\x1b[?25h\x1b[?1049l' in output, action + ': missing screen/cursor restoration'
        assert proc.returncode == (1 if action == 'exception' else 0), (action, proc.returncode, output)
    finally:
        if proc.poll() is None:
            proc.kill()
            proc.wait()
        os.close(master)
        os.close(slave)


for case in ('quit', 'interrupt', 'terminate', 'eof', 'exception', 'resize', 'paged-sql', 'channels'):
    run(case)
    print(case + ': restored')
