#!/usr/bin/env python3
"""Check bounded media controls, phase occupancy, and exact native launch evidence."""
import json
import math
import pathlib
import sys

root = pathlib.Path(sys.argv[1])
media = json.loads((root / 'media.json').read_text())['evidence']


def samples(name):
    lines = (root / name).read_text().splitlines()
    result = []
    for index, line in enumerate(lines):
        try:
            result.append(json.loads(line))
        except json.JSONDecodeError:
            assert index == len(lines) - 1, f'Malformed interior sample in {name}'
    return result


def timing(values):
    assert values, 'Missing control timing samples'
    ordered = sorted(values)
    return {'count': len(ordered), 'p99Seconds': ordered[math.ceil(len(ordered) * .99) - 1], 'maxSeconds': max(ordered)}


before = json.loads((root / 'containers-before.json').read_text())
after = json.loads((root / 'containers-after.json').read_text())
assert [unit['Id'] for unit in before[:2]] == [unit['Id'] for unit in after]
assert all(unit['State']['Running'] and unit.get('RestartCount', 0) == 0 for unit in after)
control, execution, postgres, redis, web = before
for unit in before:
    config = unit['HostConfig']
    assert config['Memory'] > 0 and config['PidsLimit'] > 0 and config['NanoCpus'] > 0
assert set(control['HostConfig']['CpusetCpus'].split(',')).isdisjoint(execution['HostConfig']['CpusetCpus'].split(','))
start, end = media['pressureStartedAt'], media['pressureEndedAt']
assert end - start >= 60, 'Missing sixty-second pressure period'
controls = media['desiredStateSamples']
assert len(controls) >= 40
assert all(sample['observedRevision'] >= sample['desiredRevision'] for sample in controls)
report = {'controlAcknowledgement': timing([sample['seconds'] for sample in controls]),
          'seekHandlingSeconds': media['seekHandlingSeconds'], 'cancelHandlingSeconds': media['cancelHandlingSeconds'],
          'seekReadinessSeconds': media['seekReadinessSeconds'], 'cancelRetirementSeconds': media['cancelRetirementSeconds'],
          'attachments': media['attachments'], 'independentOwners': media['independentOwners'], 'unaffectedOwnerJobs': media['unaffectedOwnerJobs'], 'generations': media['generations'],
          'retiredOutputStatuses': media['retiredOutputStatuses'], 'retainedOutputFiles': media['retainedOutputFiles'],
          'socketReconnectReplay': media['socketReconnectReplay'], 'pendingCancellation': media['pendingCancellation'],
          'containerIds': {'control': control['Id'], 'execution': execution['Id']}}
assert report['controlAcknowledgement']['p99Seconds'] <= 1 and report['controlAcknowledgement']['maxSeconds'] <= 2
assert max(media['seekHandlingSeconds'], media['cancelHandlingSeconds']) <= 2
assert media['pendingCancellation']['outcome'] == 'cancelled' and media['pendingCancellation']['nativeLaunches'] == 0 and media['pendingCancellation']['references'] == 0
assert 0 <= report['seekReadinessSeconds'] <= 30 and 0 <= report['cancelRetirementSeconds'] <= 30
assert media['generations'][1] > media['generations'][0]
assert all(status in (403, 404, 410) for status in media['retiredOutputStatuses'].values())
assert media['retainedOutputFiles'] >= 2
assert media['socketReconnectReplay'] is True
assert media['independentOwners'] == 4 and len(media['unaffectedOwnerJobs']) == 2
assert {entry['phase'] for entry in media['attachments']} == {'running', 'paused'}
assert all(entry['references'] == 1 for entry in media['attachments'])
report['sampling'] = {}
for name in ('control', 'execution'):
    window = [sample for sample in samples(name + '-samples.jsonl') if start <= sample['monotonic'] <= end]
    assert len(window) >= 2, f'Missing {name} pressure samples'
    times = [sample['monotonic'] for sample in window]
    gaps = [right - left for left, right in zip(times, times[1:])]
    assert min(gaps) > 0 and max(gaps) <= 2
    assert times[0] - start <= 2 and end - times[-1] <= 2
    report['sampling'][name] = {'count': len(window), 'maximumGapSeconds': max(gaps)}
    if name == 'execution':
        saturated = 0
        cancellation = 0
        for sample in window:
            stamp = sample['monotonic']
            processes = sample['nativeProcesses']
            videos = sum('/media/source/video.mkv' in process['argv'] for process in processes)
            audio = sum('/media/source/track.flac' in process['argv'] for process in processes)
            assert audio >= 1, 'Admitted audio slot disappeared during controls'
            # Seeking deliberately retires a generation; cancellation releases its slot.
            if stamp < media['seekStartedAt'] or media['seekReadyAt'] <= stamp < media['cancelStartedAt']:
                assert videos >= 3, 'Full video occupancy disappeared before cancellation'
                saturated += 1
            elif media['cancelRetiredAt'] <= stamp:
                assert videos >= 2, 'Unaffected video work disappeared after cancellation'
                cancellation += 1
        assert cancellation > 0, 'Missing released-slot cancellation observation'
        assert saturated > 10, 'Insufficient saturated control samples'
        report['fullOccupancySamples'] = saturated
        report['postCancellationSamples'] = cancellation
        usage = lambda sample: dict(line.split() for line in sample['cpu.stat'].splitlines())
        cpu_seconds = (int(usage(window[-1])['usage_usec']) - int(usage(window[0])['usage_usec'])) / 1e6
        assert cpu_seconds >= (times[-1] - times[0]) * 1.5, 'Execution CPU pressure was not sustained'
        report['executionCpuSeconds'] = cpu_seconds
    else:
        initial = window[0]
        oom = lambda sample: dict(line.split() for line in sample['memory.events'].splitlines()).get('oom_kill', '0')
        assert all(oom(sample) == oom(initial) for sample in window)
        assert all(sample['heartbeat']['bootId'] == initial['heartbeat']['bootId'] and sample['heartbeat']['epoch'] == initial['heartbeat']['epoch'] for sample in window)
        assert all(sample['lease']['owner_boot_id'] == initial['lease']['owner_boot_id'] and sample['lease']['epoch'] == initial['lease']['epoch'] and sample['lease']['state'] == 'active' and sample['lease']['unexpired'] in (True, 1, 't', '1') for sample in window)
        heartbeat = timing([sample['monotonic'] - sample['heartbeat']['time'] for sample in window])
        assert heartbeat['p99Seconds'] <= 1 and heartbeat['maxSeconds'] <= 2
        report['heartbeat'] = heartbeat
        assert min(sample['ordinaryReceiverDepth'] for sample in window) > 0, 'Ordinary backlog disappeared'
backlog = [sample for sample in samples('backlog.jsonl') if start <= sample['monotonic'] <= end]
assert len(backlog) > 1 and backlog[-1]['acknowledged'] > backlog[0]['acknowledged'], 'Ordinary consumer made no progress'
report['ordinaryBacklog'] = {'acknowledgedProgress': backlog[-1]['acknowledged'] - backlog[0]['acknowledged']}
launches = samples('native-launches.jsonl')
seen = set()
for launch in launches:
    key = (launch['jobId'], launch['generation'])
    assert key not in seen and not launch['predecessors'], 'Duplicate or overlapping native launch'
    seen.add(key)
assert launches and len(media['videoJobs']) == 3 and media['audioBytes'] > 0
report['nativeLaunches'] = len(launches)
(root / 'qualification.json').write_text(json.dumps(report, indent=2) + '\n')
print(json.dumps(report))
