#!/usr/bin/env python3
"""Bounded qualification assertions; thresholds are emitted before work starts."""
import datetime
import json
import math
import statistics
import pathlib
import sys

THRESHOLDS = dict(cpuMessages=16, cpuDeadlineSeconds=70, minimumHandlerCpuSeconds=16, changeCooldownSeconds=5, schedulerDeadlineSeconds=10, messagesPerBurst=48, handlerSeconds=2, burstDeadlineSeconds=150,
                  idleDeadlineSeconds=110, callbackDeadlineSeconds=480,
                  profileDeadlineSeconds=600, maximumSampleGapSeconds=5,
                  mediaControlDeadlineSeconds=3, peerMaximumAgeSeconds=2, publicationMaximumAgeSeconds=3,
                  pressureBoundaryGapSeconds=2, pressureMinimumSeconds=60, minimumSpeedups={'2': 1.15, '4': 1.4})


def samples(root, unit='control'):
    values = []
    for line in (root / (unit + '-samples.jsonl')).read_text().splitlines():
        try:
            values.append(json.loads(line))
        except json.JSONDecodeError:
            pass  # The writer may still be completing the last line.
    return values


def receipts(values, batch):
    result = {}
    for sample in values:
        for line in sample.get('completions', '').splitlines():
            receipt = json.loads(line)
            if receipt['batch'] == batch:
                key = receipt['sequence']
                assert key not in result or result[key] == receipt, 'Duplicate workload completion'
                result[key] = receipt
    return result


def burst(root, batch):
    publication = json.loads((root / (batch + '.json')).read_text())
    values = samples(root)
    completed = receipts(values, batch)
    expected = THRESHOLDS['cpuMessages'] if batch == 'cpu' else THRESHOLDS['messagesPerBurst']
    kind = 'cpu' if batch == 'cpu' else 'io'
    assert publication['count'] == expected and publication['kind'] == kind, 'Workload publication differs from declared fixture'
    assert set(completed) == set(range(expected)), f'{batch}: {len(completed)}/{expected} complete'
    ended = max(r['finished'] for r in completed.values())
    elapsed = ended - publication['started']
    assert 0 < elapsed <= (THRESHOLDS['cpuDeadlineSeconds'] if kind == 'cpu' else THRESHOLDS['burstDeadlineSeconds']), 'Burst exceeded deadline'
    assert all(r['finished'] - r['started'] >= 1.9 for r in completed.values()), 'I/O wait missing'
    assert all(r['kind'] == kind for r in completed.values()), 'Wrong handler workload kind'
    cpu_seconds = sum(r['cpuSeconds'] for r in completed.values())
    if kind == 'cpu':
        assert cpu_seconds >= THRESHOLDS['minimumHandlerCpuSeconds'], 'Ordinary handlers did not perform the declared CPU work'
    return dict(batch=batch, seconds=elapsed, throughput=expected / elapsed, handlerCpuSeconds=cpu_seconds,
                pids=sorted({r['pid'] for r in completed.values()}), ended=ended)


def idle(root):
    values = samples(root)
    assert len(values) >= 3
    for sample in values[-3:]:
        state = sample['admission']
        assert state['target'] == 1 and not state['fatal']
        assert all(s['state'] not in ('busy', 'completing') for s in state['slots'].values())
        assert all(s['requestId'] is None or s['state'] == 'receiving' for s in state['slots'].values()), 'Owned delivery remained at idle checkpoint'
        controller_age = sample['monotonic'] - state['controllerAt']
        assert -.25 <= controller_age < THRESHOLDS['publicationMaximumAgeSeconds']
        evidence = state['evidence']
        assert evidence.get('queueState') == 'known', 'Idle checkpoint requires known queue evidence'
        queue_age = evidence.get('queueSampleAgeSeconds')
        assert isinstance(queue_age, (int, float)) and math.isfinite(queue_age) and queue_age >= 0
        assert max(0, controller_age) + queue_age < THRESHOLDS['publicationMaximumAgeSeconds'], 'Idle checkpoint queue evidence was stale'
        assert all(evidence.get(key) == 0 for key in ('unreadCount', 'pendingCount', 'dueCount')), 'Queue work remained at idle checkpoint'



def timings(values):
    ordered = sorted(values)
    assert ordered
    return dict(count=len(ordered), medianSeconds=statistics.median(ordered),
                p99Seconds=ordered[max(0, math.ceil(len(ordered) * .99) - 1)], maxSeconds=max(ordered))


def stream_window(values, start, end, name):
    assert len(values) > 20, f'Insufficient {name} samples'
    gaps = [b['monotonic'] - a['monotonic'] for a, b in zip(values, values[1:])]
    assert gaps and min(gaps) > 0 and max(gaps) <= THRESHOLDS['maximumSampleGapSeconds'], f'{name} sampling stalled or reversed'
    window = [s for s in values if start <= s['monotonic'] <= end]
    assert len(window) > 1, f'Missing {name} pressure window'
    assert window[0]['monotonic'] - start <= 2 and end - window[-1]['monotonic'] <= 2, f'Truncated {name} pressure window'
    return window


def current_authority(sample, identity):
    now = sample['monotonic']
    state = sample['admission']
    for key in ('namespace', 'bootId', 'epoch'):
        assert state[key] == identity[key], 'Foreign ledger authority'
    assert -.25 <= now - state['controllerAt'] < 3, 'Stale controller publication'
    for unit in ('control', 'execution'):
        peer = sample[unit]
        assert peer and all(peer[key] == identity[key] for key in ('namespace', 'bootId', 'epoch')), 'Foreign peer authority'
        assert peer['unit'] == unit and peer['ready'] is True and peer['draining'] is False, 'Peer unavailable'
        assert -.25 <= now - peer['time'] < 2, 'Peer heartbeat exceeded continuity deadline'
    resource = sample['resource']
    assert resource and all(resource[key] == identity[key] for key in ('namespace', 'bootId', 'epoch')), 'Foreign resource authority'
    observation = resource['observation']
    assert -.25 <= now - observation['sampledAt'] < 3, 'Stale resource publication'
    assert observation['state'] in ('known', 'unknown', 'unavailable'), 'Invalid resource state'
    if observation['state'] == 'known':
        assert all(isinstance(observation.get(key), (int, float)) and math.isfinite(observation[key]) and observation[key] >= 0
                   for key in ('cpuUtilization', 'memoryRatio', 'pidsRatio')), 'Known resource publication lacks pressure measurements'
    status = sample['status']
    assert status and status['state'] == 'known' and status['authorityValid'] is True and status['controllerFresh'] is True, 'Unavailable status authority/controller'
    assert 0 <= status['controllerAgeSeconds'] < 3, 'Status controller age exceeded deadline'
    if status['resources']['state'] == 'known':
        assert status['resourceFresh'] is True, 'Known resource status was stale'
    else:
        assert status['resourceFresh'] is False, 'Unknown resource status advertised as fresh known evidence'
    evidence = state['evidence']
    for component in ('queue', 'resource'):
        if evidence.get(component + 'State') == 'known':
            assert 0 <= evidence[component + 'SampleAgeSeconds'] < 3, 'Known decision measurement was stale'
    return observation['state']


def native_processes(sample):
    result = []
    for process in sample['processes']:
        arguments = (process.get('cmdline') or '').rstrip('\0').split('\0')
        if arguments and any(arg.rsplit('/', 1)[-1] == 'ffmpeg' for arg in arguments):
            assert process['pid'] > 0 and str(process['startTicks']).isdigit(), 'Missing native PID identity'
            result.append((process, arguments))
    return result


def counters(raw):
    return {key: int(value) for key, value in (line.split() for line in raw.splitlines())}


def startup_metrics(containers, values, execution):
    result = {}
    for unit, container, source in [('control', containers[0], values), ('execution', containers[1], execution)]:
        first = source[0]
        started = datetime.datetime.fromisoformat(container['State']['StartedAt'].replace('Z', '+00:00')).timestamp()
        created = datetime.datetime.fromisoformat(container['Created'].replace('Z', '+00:00')).timestamp()
        baseline = first['processes']
        result[unit] = dict(createdToStartedSeconds=started-created, startedToCheckpointSeconds=first['wallTime']-started,
                            firstCheckpointMemoryBytes=int(first['memory.current']), firstCheckpointPids=int(first['pids.current']),
                            residentProcessCount=len(baseline), residentRssBytes=sum(p.get('rssBytes') or 0 for p in baseline))
        assert result[unit]['startedToCheckpointSeconds'] >= 0
    result['databaseConnectionsAtFirstCheckpoint'] = values[0]['connections']
    result['scope'] = 'Container startup through first qualification sample includes application readiness, harness checks and the observation-gap probe; dependency/media preparation is outside this interval. First checkpoint process/RSS counts include the qualification observer.'
    return result


def verify(root, maximum):
    values = samples(root)
    assert len(values) > 20
    assert max(b['monotonic'] - a['monotonic'] for a, b in zip(values, values[1:])) <= THRESHOLDS['maximumSampleGapSeconds'], 'Control sampling stalled'
    first = values[0]['lease']
    assert all(s['lease']['owner_boot_id'] == first['owner_boot_id'] and s['lease']['epoch'] == first['epoch']
               and s['lease']['state'] == 'active' and s['lease']['unexpired'] in (True, 1, 't', '1') for s in values), 'Lease continuity failed'
    identity = {key: values[0]['admission'][key] for key in ('namespace', 'bootId', 'epoch')}
    assert first['owner_boot_id'] == identity['bootId'] and first['epoch'] == identity['epoch'], 'Lease and publication authority differ'
    resource_states = {'known': 0, 'unknown': 0, 'unavailable': 0}
    for sample in values:
        resource_states[current_authority(sample, identity)] += 1
        state = sample['admission']
        assert state and not state['fatal'] and len(state['slots']) == maximum
        occupied = sum(slot['requestId'] is not None for slot in state['slots'].values())
        assert 0 <= state['target'] <= maximum and occupied <= maximum, 'Resident occupancy exceeded'
        assert sample['control'] and sample['execution'] and sample['resource'], 'Peer/resource evidence missing'
        assert sample.get('status'), 'Status evidence missing'
    bursts = [burst(root, name) for name in ('cold', 'repeat')]
    for name, result in zip(('cold', 'repeat'), bursts):
        start = json.loads((root / (name + '.json')).read_text())['started']
        phase = [s for s in values if start <= s['monotonic'] <= result['ended']]
        assert phase and max(s['admission']['target'] for s in phase) == maximum, 'I/O burst did not reach admitted maximum'
        assert any(s['admission']['evidence'].get('durationSeconds') is not None for s in phase), 'Duration evidence missing'
    media = json.loads((root / 'media.json').read_text())['evidence']
    assert len(set(media['videoJobs'])) == 3 and media['audioBytes'] >= 8192
    assert media['desiredStateSamples'] and max(s['seconds'] for s in media['desiredStateSamples']) <= 3, 'Media control exceeded deadline'
    cpu_burst = burst(root, 'cpu')
    pressure_start = media['pressureStartedAt']
    pressure_end = media['pressureEndedAt']
    assert pressure_end - pressure_start >= 60, 'Pressure window lasted less than sixty seconds'
    control_window = stream_window(values, pressure_start, pressure_end, 'control')
    pressure = [s for s in values if pressure_start + 5 <= s['monotonic'] <= pressure_end]
    assert pressure and any(s['admission']['reason'] in ('resource_growth_blocked', 'resource_pressure', 'severe_resource_pressure') for s in pressure), 'CPU pressure did not block growth'
    blocked = [s for s in pressure if (s['admission']['evidence'].get('cpuUtilization') or 0) >= .85
               or (s['admission']['evidence'].get('cpuThrottledRatio') or 0) >= .1]
    assert blocked, 'No measured CPU pressure'
    for before, after in zip(pressure, pressure[1:]):
        evidence = after['admission']['evidence']
        if (evidence.get('cpuUtilization') or 0) >= .85 or (evidence.get('cpuThrottledRatio') or 0) >= .1:
            assert after['admission']['target'] <= before['admission']['target'], 'Admission grew during measured CPU pressure'
    transitions = [dict(monotonic=b['monotonic'], controllerAt=b['admission']['controllerAt'], before=a['admission']['target'], after=b['admission']['target'], reason=b['admission']['reason'])
                   for a, b in zip(values, values[1:]) if a['admission']['target'] != b['admission']['target']]
    normal_changes = [change for change in transitions if change['before'] > 0 and change['after'] > 0
                      and change['reason'] not in ('measurements_unavailable', 'authority_unavailable', 'severe_resource_pressure')]
    for before, after in zip(normal_changes, normal_changes[1:]):
        assert after['controllerAt'] - before['controllerAt'] >= THRESHOLDS['changeCooldownSeconds'] - .5, 'Target changes violated cooldown'
    idle(root)
    execution = samples(root, 'execution')
    assert execution and all(s['memory.current'] and s['pids.current'] and s['cpu.stat'] and s['processes'] for s in execution)
    execution_window = stream_window(execution, pressure_start, pressure_end, 'execution')
    for sample in execution:
        current_authority(sample, identity)
    cpu_start = json.loads((root / 'cpu.json').read_text())['started']
    cpu_control = [s for s in values if cpu_start <= s['monotonic'] <= cpu_burst['ended']]
    cpu_execution = [s for s in execution if cpu_start <= s['monotonic'] <= cpu_burst['ended']]
    assert len(cpu_control) > 2 and len(cpu_execution) > 2, 'Missing ordinary CPU observations'
    cpu_usage = (counters(cpu_execution[-1]['cpu.stat'])['usage_usec'] - counters(cpu_execution[0]['cpu.stat'])['usage_usec']) / 1e6
    assert cpu_usage >= 16, 'Execution cgroup did not account ordinary CPU pressure'
    cpu_pressure_samples = [s for s in cpu_control if s['admission']['evidence'].get('cpuUtilization') is not None]
    assert cpu_pressure_samples and max(s['admission']['evidence']['cpuUtilization'] for s in cpu_pressure_samples) > 0, 'Ordinary CPU utilization evidence missing'
    for before, after in zip(cpu_control, cpu_control[1:]):
        evidence = after['admission']['evidence']
        if (evidence.get('cpuUtilization') or 0) >= .85 or (evidence.get('cpuThrottledRatio') or 0) >= .1:
            assert after['admission']['target'] <= before['admission']['target'], 'Ordinary admission grew on high CPU decision evidence'
    cpu_burst.update(executionCpuSeconds=cpu_usage, peakUtilization=max(s['admission']['evidence']['cpuUtilization'] for s in cpu_pressure_samples),
                     minimumTarget=min(s['admission']['target'] for s in cpu_control), maximumTarget=max(s['admission']['target'] for s in cpu_control),
                     transitions=[c for c in transitions if cpu_start <= c['monotonic'] <= cpu_burst['ended']])
    backlog = media['ordinaryBacklog']
    assert len(backlog) > 1 and min(row['receiverDepth'] for row in backlog) > 0, 'Ordinary media backlog disappeared'
    assert backlog[-1]['acknowledged'] > backlog[0]['acknowledged'], 'Ordinary consumer made no acknowledged media progress'
    assert backlog[-1]['relayedEvents'] > backlog[0]['relayedEvents'], 'Outbox relay made no media progress'
    for offset in range(0, 50, 10):
        window = [row for row in backlog if pressure_start + offset <= row['monotonic'] < pressure_start + offset + 10]
        assert len(window) > 1 and window[-1]['acknowledged'] > window[0]['acknowledged'], 'Ordinary consumer stalled for ten seconds under media pressure'
    occurrences = media['dueOccurrences']
    assert len(occurrences) >= 4, 'Missing due scheduler pressure probes'
    completion_times = []
    for occurrence in occurrences:
        returned = occurrence['execution']
        assert returned and returned['returned'] in (True, 1, 't', '1'), 'Due scheduler occurrence did not return'
        assert returned['deployment_namespace'] == identity['namespace'] and returned['deployment_boot_id'] == identity['bootId'] and int(returned['deployment_epoch']) == identity['epoch'], 'Scheduler occurrence used foreign deployment authority'
        duration = float(returned['completion_seconds'])
        assert 0 <= duration <= THRESHOLDS['schedulerDeadlineSeconds'], 'Due scheduler occurrence exceeded completion deadline'
        completion_times.append(duration)
    native_counts = []
    audio_identities = None
    for sample in execution_window:
        native = native_processes(sample)
        videos = [p for p, args in native if '/media/source/video.mkv' in args]
        audio = [p for p, args in native if '/media/source/track.flac' in args]
        assert len(videos) >= 3 and len(audio) >= 1, 'Three video and one audio native slots did not stay occupied'
        identities = {(p['pid'], str(p['startTicks'])) for p in audio}
        audio_identities = identities if audio_identities is None else audio_identities & identities
        native_counts.append(len(native))
    assert audio_identities, 'Admitted held audio PID identity changed during pressure'
    for source in (values, execution):
        assert source[0]['monotonic'] <= values[0]['monotonic'] + 2 and source[-1]['monotonic'] >= values[-1]['monotonic'] - 2, 'Truncated complete observation interval'
    heartbeat = timings([s['monotonic'] - s['control']['time'] for s in values])
    cpu_seconds = (counters(execution[-1]['cpu.stat'])['usage_usec'] - counters(execution[0]['cpu.stat'])['usage_usec']) / 1e6
    containers = json.loads((root / 'containers-before.json').read_text())
    result = dict(maximum=maximum, bursts=bursts, ordinaryCpu=cpu_burst,
                  mediaOrdinaryProgress=dict(minimumReceiverDepth=min(row['receiverDepth'] for row in backlog), acknowledged=backlog[-1]['acknowledged']-backlog[0]['acknowledged'], relayedEvents=backlog[-1]['relayedEvents']-backlog[0]['relayedEvents']),
                  mediaScheduler=dict(returned=len(occurrences), completion=timings(completion_times)), samples=len(values), transitions=transitions,
                  peakMemoryBytes=max(int(s['memory.current']) for s in execution),
                  peakPids=max(int(s['pids.current']) for s in execution),
                  peakDatabaseConnections=max(s['connections'] for s in values), thresholds=THRESHOLDS,
                  heartbeat=heartbeat, mediaControl=timings([s['seconds'] for s in media['desiredStateSamples']]),
                  resourceStates=resource_states, observationGap=gap_receipt(root), startup=startup_metrics(containers, values, execution),
                  executionCpuSeconds=cpu_seconds, nativePressure=dict(samples=len(execution_window), minimumProcesses=min(native_counts),
                    heldAudioIdentities=sorted(audio_identities)),
                  peakResidentRssBytes=max(sum(p.get('rssBytes') or 0 for p in s['processes']) for s in execution),
                  coverageLimits=['The cold artifact labels the first 48-message burst after harness work and the observation-gap probe. Duration EWMA is already initialized; pristine cold-start performance is not measured. Pure policy tests cover absent duration history.',
                                  'Blocking wait models I/O, not an external provider latency distribution.',
                                  'Retry race coverage comes from the separate real Messenger integration suite.'])
    (root / 'qualification.json').write_text(json.dumps(result, indent=2) + '\n')
    print(json.dumps(result))



def gap_receipt(root):
    receipt = json.loads((root / 'gap.json').read_text())
    identity = {key: receipt[key] for key in ('namespace', 'bootId', 'epoch')}
    controller = receipt['controller']
    assert controller['pid'] > 0 and str(controller['startTicks']).isdigit()
    assert controller['role'] == 'autoscaler' and controller['generation'] == 1
    assert 0 < receipt['finishedAt'] - receipt['startedAt'] <= 25, 'Observation gap exceeded bounded deadline'
    assert receipt['publishedAt'] - receipt['stoppedAt'] >= receipt['maxSampleAgeSeconds'] + .19, 'Message published before controller became stale'
    assert receipt['staleAt'] - receipt['publishedAt'] >= 1.19, 'Insufficient unread hold observation'
    assert receipt['staleAt'] <= receipt['resumedAt'] < receipt['recoveredAt'], 'Invalid pause/recovery chronology'
    assert receipt['recoveredAt'] - receipt['resumedAt'] <= 12, 'Observation recovery exceeded twelve seconds'
    stale = receipt['staleStatus']
    assert stale['state'] == 'unavailable' and stale['controllerFresh'] is False and stale['authorityValid'] is True
    assert stale['controllerAgeSeconds'] >= receipt['maxSampleAgeSeconds'], 'Unavailability did not represent controller staleness'
    recovered = receipt['recoveredStatus']
    assert recovered['state'] == 'known' and recovered['controllerFresh'] is True and recovered['authorityValid'] is True
    assert 0 <= recovered['controllerAgeSeconds'] < receipt['maxSampleAgeSeconds']
    proof = receipt['unreadProof']
    assert proof['present'] == 1 and proof['pending'] == 0 and proof['unread'] == 1, 'Work consumed during stale controller admission'
    redis_id = lambda value: tuple(int(part) for part in value.split('-'))
    assert redis_id(proof['lastDeliveredId']) < redis_id(receipt['messageId'])
    for name in ('staleHealth', 'recoveredHealth'):
        health = receipt[name]
        authority = health['lease']
        assert authority['owner_boot_id'] == identity['bootId'] and int(authority['epoch']) == identity['epoch']
        assert authority['state'] == 'active' and authority['unexpired'] in (True, 1, 't', '1')
        for unit in ('control', 'execution'):
            peer = health['peers'][unit]
            assert all(peer[key] == identity[key] for key in ('namespace', 'bootId', 'epoch'))
            assert peer['unit'] == unit and peer['ready'] is True and peer['draining'] is False
            assert -.25 <= health['sampledAt'] - peer['time'] < 2, 'Observation gap disturbed peer freshness'
    completion = receipt['completion']
    assert completion['batch'] == 'observation-gap' and completion['sequence'] == 0
    assert receipt['resumedAt'] <= completion['started'] < completion['finished'] <= receipt['recoveredAt'] + .25
    assert completion['finished'] - completion['started'] >= 1.9
    return dict(seconds=receipt['finishedAt'] - receipt['startedAt'],
                staleControllerAgeSeconds=stale['controllerAgeSeconds'], recoverySeconds=receipt['recoveredAt'] - receipt['resumedAt'],
                controller=controller, messageId=receipt['messageId'], unreadWhileStale=True, completedAfterRecovery=True)


def compare(root):
    results = {str(m): json.loads((root / str(m) / 'qualification.json').read_text()) for m in (1, 2, 4)}
    speedups = {}
    for maximum in ('2', '4'):
        speedups[maximum] = [base['seconds'] / measured['seconds'] for base, measured in zip(results['1']['bursts'], results[maximum]['bursts'])]
        assert min(speedups[maximum]) >= THRESHOLDS['minimumSpeedups'][maximum], f'Insufficient measured speedup: {speedups}'
    (root / 'comparison.json').write_text(json.dumps(dict(speedups=speedups, thresholds=THRESHOLDS), indent=2) + '\n')


if __name__ == '__main__':
    mode = sys.argv[1]
    if mode == 'thresholds':
        print(json.dumps(THRESHOLDS, indent=2))
    else:
        root = pathlib.Path(sys.argv[2])
        if mode == 'burst': burst(root, sys.argv[3])
        elif mode == 'idle': idle(root)
        elif mode == 'verify': verify(root, int(sys.argv[3]))
        elif mode == 'compare': compare(root)
        elif mode == 'gap': gap_receipt(root)
        else: raise ValueError(mode)
