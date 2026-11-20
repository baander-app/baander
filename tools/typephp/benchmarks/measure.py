#!/usr/bin/env python3
"""Collect fresh-process and in-process timings from matched PHP runtimes."""
import argparse
import hashlib
import json
import math
import os
from pathlib import Path
import statistics
import subprocess
import tempfile
import time

INI_SETTINGS = {
    'memory_limit': '512M', 'ffi.enable': '1', 'opcache.enable': '1',
    'opcache.enable_cli': '1', 'opcache.jit': 'disable',
    'opcache.jit_buffer_size': '0', 'zend.assertions': '-1',
    'opcache.optimization_level': '0x7FFFBFFF',
    'opcache.memory_consumption': '256', 'opcache.interned_strings_buffer': '16',
    'opcache.max_accelerated_files': '20000', 'opcache.validate_timestamps': '0',
    'opcache.file_cache': '', 'opcache.file_cache_only': '0',
    'auto_prepend_file': '', 'auto_append_file': '', 'date.timezone': 'UTC',
    'display_errors': 'stderr', 'display_startup_errors': '1',
    'error_reporting': '32767', 'log_errors': '0', 'html_errors': '0',
}
WORKDIR = '/var/www/html'
DRIVER = '/bench/workload.php'
EMPTY_INI = '/tmp/baander-benchmark-empty.ini'
EMPTY_SCAN = '/tmp/baander-benchmark-empty-conf'


class Runtime:
    def __init__(self, name, binary):
        self.name = name
        self.binary = binary

    def command(self, script, args=()):
        options = [item for key, value in INI_SETTINGS.items() for item in ('-d', f'{key}={value}')]
        return [self.binary, *options, '-f', script, '--', *map(str, args)]

    def environment(self):
        environment = dict(os.environ, PHPRC=EMPTY_INI,
                    PHP_INI_SCAN_DIR='/usr/local/etc/php/conf.d' if self.name == 'normal' else EMPTY_SCAN,
                    APP_ENV='prod', APP_DEBUG='0', NO_COLOR='1', TERM='dumb')
        environment.pop('BAANDER_TYPEPHP_QUALIFICATION', None)
        return environment


def child(command, env=None, cwd=None):
    """wait4 reports this child's CPU and Linux peak RSS (KiB), never accumulated siblings."""
    with tempfile.TemporaryFile() as stdout, tempfile.TemporaryFile() as stderr:
        started = time.monotonic_ns()
        process = subprocess.Popen(command, env=env, cwd=cwd, stdout=stdout, stderr=stderr)
        _, status, usage = os.wait4(process.pid, 0)
        wall_ns = time.monotonic_ns() - started
        process.returncode = os.waitstatus_to_exitcode(status)
        stdout.seek(0)
        stderr.seek(0)
        return {'returncode': process.returncode, 'wall_ns': wall_ns,
                'cpu_user_seconds': usage.ru_utime, 'cpu_system_seconds': usage.ru_stime,
                'peak_rss_kib': usage.ru_maxrss,
                'stdout': stdout.read().decode('utf-8', errors='replace'),
                'stderr': stderr.read().decode('utf-8', errors='replace')}


def expected_checksum(iterations):
    value = (b'{"public_id":"0123456789abcdefghijk",'
             b'"uuid":"01890f47-14c4-7cc0-98c4-dc0c1c073ef6",'
             b'"binary":"01890f4714c47cc098c4dc0c1c073ef6","equal":true}')
    digest = hashlib.sha256()
    for _ in range(iterations):
        digest.update(value)
    return digest.hexdigest()


def validate(record, workload, iterations, checksum):
    if record['returncode'] != 0:
        raise ValueError(f"Child exited {record['returncode']}")
    if record['stderr']:
        raise ValueError('Child emitted stderr')
    if workload == 'sustained':
        result = json.loads(record['stdout'])
        if result['iterations'] != iterations or result['checksum'] != checksum:
            raise ValueError('Incorrect sustained iteration count or checksum')
        if not isinstance(result['elapsed_ns'], int) or result['elapsed_ns'] <= 0:
            raise ValueError('Invalid internal duration')
        record['internal_ns'] = result['elapsed_ns']
        record['result'] = result


def stats(values):
    ordered = sorted(values)
    if not ordered or any(not math.isfinite(v) or v <= 0 for v in ordered):
        raise ValueError('Expected finite positive observations')
    return {'count': len(ordered), 'median': statistics.median(ordered),
            'p95': ordered[math.ceil(len(ordered) * .95) - 1],
            'min': ordered[0], 'max': ordered[-1]}


def summarize(records):
    summary = {}
    for workload in ('console', 'operator', 'sustained'):
        observations = [r for r in records if r['phase'] == 'sample' and r['workload'] == workload]
        if not observations or any(r['status'] != 'accepted' for r in observations):
            continue
        metrics = ['wall_ns', 'cpu_seconds', 'peak_rss_kib']
        if workload == 'sustained':
            metrics.append('internal_ns')
        summary[workload] = {}
        for metric in metrics:
            variants = {name: stats([r[metric] for r in observations if r['runtime'] == name])
                        for name in ('normal', 'typephp')}
            variants['typephp_over_normal_median'] = variants['typephp']['median'] / variants['normal']['median']
            summary[workload][metric] = variants
    return summary


def collect(args, report):
    Path(EMPTY_INI).write_text('')
    Path(EMPTY_SCAN).mkdir(exist_ok=True)
    runtimes = [Runtime('normal', args.normal), Runtime('typephp', args.typephp)]
    identities = {}
    for runtime in runtimes:
        info = child(runtime.command(DRIVER, ['--runtime-info', ','.join(INI_SETTINGS)]),
                     runtime.environment(), WORKDIR)
        report['identity_processes'].append(dict(runtime=runtime.name, **info))
        validate(info, 'identity', 0, '')
        identities[runtime.name] = json.loads(info['stdout'])
    report['runtimes'] = identities
    if identities['normal']['version'] != identities['typephp']['version']:
        raise ValueError('PHP versions differ')
    if identities['normal']['ini'] != identities['typephp']['ini']:
        raise ValueError('Selected effective PHP INI settings differ')
    for name, info in identities.items():
        if 'Zend OPcache' not in info['extensions']:
            raise ValueError(f'{name} lacks required OPcache')
    checksum = expected_checksum(args.iterations)
    report['expected_checksum'] = checksum
    workloads = {
        'console': ('tools/typephp/application-entry.php', ['console', 'list', '--raw', '--no-interaction']),
        'operator': ('tools/typephp/application-entry.php', ['operator', '--help']),
        'sustained': (DRIVER, [args.iterations]),
    }
    outputs = report['startup_outputs'] = {}
    for phase, count in [('warmup', args.warmups), ('sample', args.samples)]:
        for workload, (script, arguments) in workloads.items():
            for index in range(count):
                order = runtimes if index % 2 == 0 else list(reversed(runtimes))
                for runtime in order:
                    record = dict(phase=phase, workload=workload, index=index, runtime=runtime.name,
                                  command=runtime.command(script, arguments), status='failed')
                    report['records'].append(record)
                    record.update(child(record['command'], runtime.environment(), WORKDIR))
                    record['cpu_seconds'] = record['cpu_user_seconds'] + record['cpu_system_seconds']
                    try:
                        validate(record, workload, args.iterations, checksum)
                        if workload != 'sustained':
                            previous = outputs.setdefault(workload, record['stdout'])
                            if record['stdout'] != previous:
                                raise ValueError('Startup output differs between runs or runtimes')
                            encoded = record['stdout'].encode('utf-8')
                            record['stdout_sha256'] = hashlib.sha256(encoded).hexdigest()
                            record['stdout_bytes'] = len(encoded)
                            del record['stdout']
                        record['status'] = 'accepted'
                    except (ValueError, KeyError, TypeError) as error:
                        record['failure'] = str(error)
                        raise


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--normal', required=True)
    parser.add_argument('--typephp', required=True)
    parser.add_argument('--output', required=True)
    parser.add_argument('--samples', type=int, default=30)
    parser.add_argument('--warmups', type=int, default=3)
    parser.add_argument('--iterations', type=int, default=100000)
    args = parser.parse_args()
    if args.samples < 30 or args.warmups < 3 or args.iterations < 1:
        parser.error('Require at least 30 samples, 3 warmups and 1 iteration')
    report = {'schema_version': 1, 'parameters': vars(args), 'ini_settings': INI_SETTINGS,
              'identity_processes': [], 'records': [], 'status': 'failed',
              'rss_method': 'Linux wait4 child ru_maxrss, KiB',
              'ordering': 'Alternating runtime order by sample index; normal first at index zero',
              'output_normalization': 'None; startup stdout must match exactly'}
    try:
        collect(args, report)
        report['summary'] = summarize(report['records'])
        report['status'] = 'accepted'
    except Exception as error:
        report['failure'] = f'{type(error).__name__}: {error}'
    Path(args.output).parent.mkdir(parents=True, exist_ok=True)
    Path(args.output).write_text(json.dumps(report, indent=2) + '\n')
    return 0 if report['status'] == 'accepted' else 1


if __name__ == '__main__':
    raise SystemExit(main())
