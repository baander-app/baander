#!/usr/bin/env python3
"""Bounded PostgreSQL cgroup and proportional process-memory diagnostics."""
import json
import pathlib
import subprocess
import sys
import time

seconds = min(180.0, float(sys.argv[1] if len(sys.argv) > 1 else 180))
database = sys.argv[2] if len(sys.argv) > 2 else 'worker_operator_test'
stop_at = time.monotonic() + seconds
previous = {}
context_requested = False


def read(path):
    try:
        return pathlib.Path(path).read_text()
    except (OSError, UnicodeError):
        return None


def fields(raw):
    result = {}
    for line in (raw or '').splitlines():
        key, separator, value = line.partition(':')
        if separator:
            result[key] = value.strip()
    return result


while time.monotonic() < stop_at:
    sample = {'monotonic': time.monotonic(), 'cgroup': {}, 'processes': []}
    for name in ['memory.current', 'memory.events', 'memory.stat', 'memory.peak', 'memory.max', 'pids.current']:
        sample['cgroup'][name] = read('/sys/fs/cgroup/' + name)
    growth = []
    for path in pathlib.Path('/proc').glob('[0-9]*'):
        status = fields(read(path / 'status'))
        if status.get('Name') != 'postgres':
            continue
        stat = read(path / 'stat')
        if stat is None:
            continue
        suffix = stat.rsplit(') ', 1)
        if len(suffix) != 2 or len(suffix[1].split()) < 20:
            continue
        pid = int(path.name)
        start_ticks = suffix[1].split()[19]
        identity = (pid, start_ticks)
        rollup = fields(read(path / 'smaps_rollup'))
        process = {'pid': pid, 'startTicks': start_ticks,
                   'cmdline': (read(path / 'cmdline') or '').replace('\x00', ' ').strip(),
                   'status': {key: status.get(key) for key in ['Name', 'State', 'PPid', 'Uid', 'VmRSS', 'RssAnon', 'RssFile', 'RssShmem', 'VmSize']},
                   'smapsRollup': {key: rollup.get(key) for key in ['Rss', 'Pss', 'Pss_Anon', 'Pss_File', 'Pss_Shmem', 'Private_Clean', 'Private_Dirty', 'Shared_Clean', 'Shared_Dirty', 'Swap']}}
        sample['processes'].append(process)
        pss = int(rollup.get('Pss', '0 kB').split()[0])
        growth.append((pss - previous.get(identity, pss), pss, pid))
        previous[identity] = pss
    current = sample['cgroup']['memory.current']
    if not context_requested and current is not None and int(current) >= 1536 * 1024 * 1024 and growth:
        context_requested = True
        target = max(growth)[2]
        try:
            result = subprocess.run(['psql', '-X', '-w', '-U', 'baander', '-d', database, '-At', '-c',
                                     f'SELECT pg_log_backend_memory_contexts({target})'],
                                    capture_output=True, text=True, timeout=2)
            sample['memoryContextRequest'] = {'pid': target, 'exitCode': result.returncode,
                                              'stdout': result.stdout[:1024], 'stderr': result.stderr[:1024]}
        except (OSError, subprocess.TimeoutExpired) as error:
            sample['memoryContextRequest'] = {'pid': target, 'error': type(error).__name__}
    print(json.dumps(sample), flush=True)
    time.sleep(.5)
