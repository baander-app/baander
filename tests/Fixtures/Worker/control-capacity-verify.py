#!/usr/bin/env python3
"""Fail qualification on missing evidence; never infer timings from liveness alone."""
import json
import math
import pathlib
import sys

mode, directory, phase = sys.argv[1:]
root = pathlib.Path(directory)


def samples(name):
    result = []
    for line in (root / name).read_text().splitlines():
        try:
            result.append(json.loads(line))
        except json.JSONDecodeError:
            pass  # A final interrupted write is not a sample.
    return result


def counters(raw):
    return {key: int(value) for key, value in (line.split() for line in raw.splitlines())}


def exhausted():
    execution = samples('execution-samples.jsonl')
    key, event = ('memory.events', 'oom_kill') if phase == 'memory' else ('pids.events', 'max')
    assert len(execution) > 1, 'Missing execution cgroup samples'
    baseline = counters(execution[0][key]).get(event, 0)
    latest = max(counters(s[key]).get(event, 0) for s in execution)
    if latest > baseline:
        return {'source': 'execution-cgroup-counter', 'counter': event, 'baseline': baseline, 'maximum': latest}
    if phase == 'pid':
        pressure_path = root / 'pressure.log'
        pressure = samples(pressure_path.name) if pressure_path.exists() else []
        configured_limit = json.loads((root / 'containers-before.json').read_text())[1]['HostConfig']['PidsLimit']
        assert int(execution[0]['pids.max']) == configured_limit, 'Sampled execution PID ceiling differs from configured limit'
        for entry in pressure:
            kernel = entry.get('executionCgroup')
            if entry.get('forkRefused') is not True or not isinstance(kernel, dict):
                continue
            if kernel.get('cgroup') != execution[0]['cgroup']:
                continue
            if int(kernel['pids.max']) != configured_limit:
                continue
            observed = counters(kernel['pids.events']).get('max', 0)
            if observed <= baseline:
                continue
            assert kernel['monotonic'] >= execution[0]['monotonic'], 'PID refusal kernel evidence predates execution baseline'
            assert 0 < int(kernel['pids.current']) <= configured_limit, 'Invalid contemporaneous execution PID count'
            return {'source': 'execution-pressure-process-kernel-counter', 'counter': 'max',
                    'baseline': baseline, 'observed': observed, 'executionCgroup': kernel,
                    'configuredPidsLimit': configured_limit}
        raise AssertionError('No increased execution PID max counter observed by sampler or refusing process')
    assert phase == 'memory', f'No execution {event} event observed'
    identity = json.loads((root / 'containers-before.json').read_text())[1]['Id']
    event_path = root / ('worker-oom-events-final.jsonl' if mode == 'verify' else 'worker-oom-events.jsonl')
    events = samples(event_path.name) if event_path.exists() else []
    matches = [entry for entry in events if entry.get('Type') == 'container'
               and entry.get('Action', entry.get('status')) == 'oom'
               and entry.get('Actor', {}).get('ID', entry.get('id')) == identity]
    assert matches, 'Neither execution oom_kill counter nor exact-container Docker OOM event was observed'
    memory_max = int(execution[0]['memory.max'])
    configured_memory = json.loads((root / 'containers-before.json').read_text())[1]['HostConfig']['Memory']
    assert memory_max == configured_memory, 'Observed execution memory ceiling differs from configured limit'
    max_baseline = counters(execution[0]['memory.events']).get('max', 0)
    max_events = max(counters(s['memory.events']).get('max', 0) for s in execution)
    peak = max(int(s.get('memory.peak') or s['memory.current']) for s in execution)
    # A rejected charge can trigger OOM while the sampled peak remains just below max.
    assert max_events > max_baseline, 'Docker OOM event lacks observed execution memory-ceiling charge failures'
    return {'source': 'exact-container-docker-oom-event', 'containerId': identity, 'events': matches,
            'memoryLimitBytes': memory_max, 'observedPeakBytes': peak,
            'memoryMaxEventsBaseline': max_baseline, 'memoryMaxEventsMaximum': max_events}


if mode == 'exhausted':
    exhausted()
    sys.exit(0)

before = json.loads((root / 'containers-before.json').read_text())
control, execution, postgres, redis, web = before
capture_path = root / 'worker-oom-events-capture.json'
assert capture_path.is_file(), 'Missing successful bounded Docker OOM event capture receipt'
capture = json.loads(capture_path.read_text())
assert capture['containerIds'] == {'control': control['Id'], 'execution': execution['Id']}, 'Docker event capture used different container identities'
assert capture['captureExitCode'] == 0 and capture['monitorAliveAfterDrainAndCapture'] is True, 'Docker event observation ended before drain evidence was complete'
assert capture['monitorIntentionallyStopped'] is True and capture['monitorExitCode'] == 143, 'Docker event subscription did not stop under test ownership'
assert capture['sinceSource'] == 'daemon-info-before-pressure' and capture['untilSource'] == 'daemon-info-after-drain', 'Docker event interval did not cover pressure through drain'
assert capture['sinceUnixSeconds'] < capture['untilUnixSeconds'], 'Invalid bounded Docker event interval'
oom_events_path = root / 'worker-oom-events-final.jsonl'
assert oom_events_path.is_file(), 'Missing bounded Docker OOM event capture output'
live_events_path = root / 'worker-oom-events.jsonl'
assert live_events_path.is_file(), 'Missing live Docker OOM event output'
oom_events = samples(oom_events_path.name) + samples(live_events_path.name)
assert not any(entry.get('Type') == 'container' and entry.get('Action', entry.get('status')) == 'oom'
               and entry.get('Actor', {}).get('ID', entry.get('id')) == control['Id'] for entry in oom_events), 'Control container emitted Docker OOM event'

for unit, memory, pids, cpu in [(control, 2944, 129, 1_000_000_000), (execution, 4480, 256, 2_000_000_000)]:
    config = unit['HostConfig']
    assert config['Memory'] == memory * 1024 * 1024 and config['PidsLimit'] == pids
    assert config['NanoCpus'] == cpu
assert set(control['HostConfig']['CpusetCpus'].split(',')).isdisjoint(execution['HostConfig']['CpusetCpus'].split(','))
for unit in (postgres, redis):
    assert unit['HostConfig']['Memory'] > 0 and unit['HostConfig']['PidsLimit'] > 0 and unit['HostConfig']['NanoCpus'] > 0
control_samples = samples('control-samples.jsonl')
assert len(control_samples) > 10, 'Insufficient control samples'
initial = control_samples[0]
assert all(counters(s['memory.events']).get('oom_kill', 0) == counters(initial['memory.events']).get('oom_kill', 0) for s in control_samples), 'Control suffered OOM kill'
pre_drain = []
for sample in control_samples:
    beat = sample['heartbeat']
    if beat['draining']:
        break
    pre_drain.append(sample)
assert pre_drain, 'No active control heartbeat samples'
assert all(s['heartbeat']['epoch'] == initial['heartbeat']['epoch'] and s['heartbeat']['bootId'] == initial['heartbeat']['bootId'] for s in pre_drain), 'Control heartbeat authority changed'
assert all(s['lease']['epoch'] == initial['lease']['epoch'] and s['lease']['owner_boot_id'] == initial['lease']['owner_boot_id'] and s['lease']['state'] == 'active' and s['lease']['unexpired'] in (True, 1, 't', '1') for s in pre_drain), 'Lease continuity failed'
ages = [s['monotonic'] - s['heartbeat']['time'] for s in pre_drain]
assert min(ages) >= -0.25, 'Invalid monotonic heartbeat clock'


def timings(values):
    ordered = sorted(values)
    return {'count': len(ordered), 'p99Seconds': ordered[max(0, math.ceil(len(ordered) * .99) - 1)], 'maxSeconds': max(ordered)}


report = {'controlOomProof': {'cgroupOomKillCounterUnchanged': True, 'exactContainerDockerOomEvents': 0, 'boundedEventCapture': capture}, 'phase': phase, 'heartbeat': timings(ages), 'drainSeconds': int((root / 'drain-seconds.txt').read_text()),
          'containerIds': {name: unit['Id'] for name, unit in [('control', control), ('execution', execution)]}}
assert report['drainSeconds'] <= 35
final_units = json.loads((root / 'drain.json').read_text())
assert [c['Id'] for c in final_units] == [control['Id'], execution['Id']], 'Container identity changed during qualification'
assert all(c.get('RestartCount', 0) == 0 for c in final_units), 'A failed unit automatically restarted'
if phase == 'cpu':
    assert pre_drain[-1]['monotonic'] - pre_drain[0]['monotonic'] >= 60, 'Missing sixty-second pressure window'
    media = json.loads((root / 'media.json').read_text())['evidence']
    report['desiredState'] = timings([s['seconds'] for s in media['desiredStateSamples']])
    pressure_start, pressure_end = media['pressureStartedAt'], media['pressureEndedAt']
    assert pressure_end - pressure_start >= 60, 'Pressure window lasted less than sixty seconds'
    report['sampling'] = {}
    for name, source in [('control', pre_drain), ('execution', samples('execution-samples.jsonl'))]:
        window = [s['monotonic'] for s in source if pressure_start <= s['monotonic'] <= pressure_end]
        assert len(window) >= 2, f'Missing {name} pressure-window samples'
        gaps = [later - earlier for earlier, later in zip(window, window[1:])]
        assert min(gaps) > 0, f'Non-increasing {name} sample clock'
        first_gap, last_gap = window[0] - pressure_start, pressure_end - window[-1]
        maximum_gap = max(gaps)
        report['sampling'][name] = {'count': len(window), 'maxAdjacentGapSeconds': maximum_gap,
                                    'startBoundaryGapSeconds': first_gap, 'endBoundaryGapSeconds': last_gap}
        assert maximum_gap <= 2, f'{name} sampling gap exceeded two seconds: {maximum_gap}'
        assert first_gap <= 2 and last_gap <= 2, f'{name} samples did not cover both pressure boundaries'
    backlog_samples = [s for s in pre_drain if media['pressureStartedAt'] <= s['monotonic'] <= media['pressureEndedAt']]
    assert backlog_samples and min(s['ordinaryReceiverDepth'] for s in backlog_samples) > 0, 'Ordinary receiver backlog disappeared during pressure'
    backlog = media['ordinaryBacklog']
    assert backlog[-1]['acknowledged'] > backlog[0]['acknowledged'], 'Ordinary consumer made no acknowledged progress'
    assert backlog[-1]['relayedEvents'] > backlog[0]['relayedEvents'], 'Outbox relay made no progress'
    for start in range(0, 50, 10):
        window = [s for s in backlog if media['pressureStartedAt'] + start <= s['monotonic'] < media['pressureStartedAt'] + start + 10]
        assert len(window) > 1 and window[-1]['acknowledged'] > window[0]['acknowledged'], 'Ordinary consumer stalled for ten seconds'
    assert len(media['dueOccurrences']) >= 4
    for occurrence in media['dueOccurrences']:
        receipt = occurrence['execution']
        assert receipt and receipt['returned'] in (True, 1, 't', '1'), 'Due scheduler occurrence did not return under ordinary backlog'
        assert receipt['deployment_boot_id'] == initial['lease']['owner_boot_id'] and receipt['deployment_epoch'] == initial['lease']['epoch'], 'Scheduler occurrence used different lease authority'
    report['ordinaryBacklog'] = {'minimumReceiverDepth': min(s['ordinaryReceiverDepth'] for s in backlog_samples), 'acknowledgedProgress': backlog[-1]['acknowledged'] - backlog[0]['acknowledged'], 'dueOccurrencesReturned': len(media['dueOccurrences'])}

    assert len(media['videoJobs']) == 3 and media['audioBytes'] > 0
    occupied = [s for s in samples('execution-samples.jsonl') if media['pressureStartedAt'] <= s['monotonic'] <= media['pressureEndedAt']]
    assert occupied and occupied[-1]['monotonic'] - occupied[0]['monotonic'] >= 59
    for sample in occupied:
        processes = sample['nativeProcesses']
        assert sum('/media/source/video.mkv' in p['argv'] for p in processes) >= 3, 'Three video native slots did not stay occupied'
        assert sum('/media/source/track.flac' in p['argv'] for p in processes) >= 1, 'Audio native slot did not stay occupied'
    report['occupiedSlotSamples'] = len(occupied)
    report['audioPressureMethod'] = 'Admitted FFmpeg process held with SIGSTOP; resumed after pressure'
    for timing in [report['heartbeat'], report['desiredState']]:
        assert timing['p99Seconds'] <= 1 and timing['maxSeconds'] <= 2, timing
    execution_samples = samples('execution-samples.jsonl')
    usage = counters(execution_samples[-1]['cpu.stat'])['usage_usec'] - counters(execution_samples[0]['cpu.stat'])['usage_usec']
    report['executionCpuSeconds'] = usage / 1e6
    assert usage >= 100_000_000, 'Execution did not sustain CPU pressure on its two CPUs'
else:
    report['exhaustion'] = exhausted()
    after = json.loads((root / 'containers-after-pressure.json').read_text())
    assert not after[0]['State']['OOMKilled'], 'Control OOM during execution exhaustion'
    failures = samples('owned-failure.jsonl')
    terminal_failures = [s for s in failures if s.get('ownedTerminal', {}).get('state') == 'exited' and s['ownedTerminal'].get('exitCode', 0) != 0]
    uncertain = [s for s in failures if 'nativeFailure' in s]
    assert terminal_failures or uncertain, 'No actual owned execution failure observed'
    trigger = (root / 'drain-trigger.txt').read_text().strip()
    assert trigger == 'automatic-execution-exhaustion', 'Exhaustion must cause automatic joint drain'
    assert all(not c['State']['Running'] for c in json.loads((root / 'drain.json').read_text())), 'Automatic pair drain missing'
    if phase == 'memory':
        pressure = samples('pressure.log')
        targets = [s for s in pressure if 'oomTarget' in s]
        deaths = [s for s in pressure if 'oomTargetDied' in s]
        assert len(targets) == 1 and deaths and targets[0]['oomTarget'] == deaths[0]['oomTargetDied'], 'Selected exact native role death was not observed after OOM'
        report['executionOomTarget'] = targets[0]
    else:
        assert any(s.get('forkRefused') for s in samples('pressure.log')), 'PID fork refusal missing'
    report['ownedFailures'] = terminal_failures + uncertain
    report['drainTrigger'] = trigger
    report['controlSurvivedExecutionExhaustion'] = not after[0]['State']['OOMKilled']
(root / 'qualification.json').write_text(json.dumps(report, indent=2) + '\n')
print(json.dumps(report))
