#!/usr/bin/env python3
"""Exercise qualification rejection paths without containers or shared services."""
import importlib.util
import json
import pathlib
import tempfile
import unittest

spec = importlib.util.spec_from_file_location('qualification', pathlib.Path(__file__).with_name('autoscaling-verify.py'))
qualification = importlib.util.module_from_spec(spec)
spec.loader.exec_module(qualification)


def sample_gap_receipt():
    def health(at):
        return dict(sampledAt=at, lease=dict(owner_boot_id='boot', epoch=1, state='active', unexpired=True),
                    peers={unit: dict(namespace='qualification', bootId='boot', epoch=1, unit=unit, ready=True, draining=False, time=at-.1)
                           for unit in ('control', 'execution')})
    return dict(namespace='qualification', bootId='boot', epoch=1,
        controller=dict(pid=100, startTicks='42', role='autoscaler', generation=1),
        startedAt=100, stoppedAt=100.1, publishedAt=103.4, staleAt=104.6, resumedAt=104.7,
        recoveredAt=108, finishedAt=108.1, maxSampleAgeSeconds=3, messageId='1000-0',
        unreadProof=dict(present=1, pending=0, lastDeliveredId='999-0', unread=1),
        staleStatus=dict(state='unavailable', authorityValid=True, controllerFresh=False, controllerAgeSeconds=4.6),
        recoveredStatus=dict(state='known', authorityValid=True, controllerFresh=True, controllerAgeSeconds=.1),
        staleHealth=health(104.6), recoveredHealth=health(108),
        completion=dict(batch='observation-gap', sequence=0, started=106, finished=108, pid=200, slot='consumer-0'))


class QualificationTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.root = pathlib.Path(self.temporary.name)
        (self.root / 'gap.json').write_text(json.dumps(sample_gap_receipt()))
        self.values = []
        completed = []
        for batch, start in [('cold', 1), ('repeat', 150)]:
            (self.root / (batch + '.json')).write_text(json.dumps(dict(started=start, count=48, kind='io')))
            completed.extend(dict(batch=batch, sequence=i, started=start + i * 2, finished=start + (i + 1) * 2, kind='io', cpuSeconds=.001, pid=99, slot='consumer-0') for i in range(48))
        (self.root / 'cpu.json').write_text(json.dumps(dict(started=250, count=16, kind='cpu')))
        completed.extend(dict(batch='cpu', sequence=i, started=250+i*2, finished=252+i*2, kind='cpu', cpuSeconds=1.8, pid=99, slot='consumer-0') for i in range(16))
        for at in range(400):
            self.values.append(dict(monotonic=at, wallTime=1000+at, lease=dict(owner_boot_id='boot', epoch=1, state='active', unexpired=True),
                admission=dict(namespace='qualification', bootId='boot', epoch=1, target=1 if at >= 390 else 2, fatal=False, controllerAt=at,
                    slots={str(i): dict(requestId=None, state='parked') for i in range(2)},
                    evidence=dict(durationSeconds=2, cpuUtilization=.95 if 255 <= at <= 282 or 305 <= at <= 360 else .1, queueState='known', unreadCount=0, pendingCount=0, dueCount=0, resourceState='known', queueSampleAgeSeconds=0, resourceSampleAgeSeconds=0), reason='resource_growth_blocked' if 305 <= at <= 360 else 'stable'),
                completions=''.join(json.dumps(r) + '\n' for r in completed if r['finished'] <= at),
                control=dict(namespace='qualification', bootId='boot', epoch=1, unit='control', ready=True, draining=False, time=at),
                execution=dict(namespace='qualification', bootId='boot', epoch=1, unit='execution', ready=True, draining=False, time=at),
                resource=dict(namespace='qualification', bootId='boot', epoch=1, observation=dict(state='known', sampledAt=at, cpuUtilization=.1, memoryRatio=.3, pidsRatio=.1)),
                status=dict(state='known', authorityValid=True, controllerFresh=True, resourceFresh=True, controllerAgeSeconds=0, resources=dict(state='known')),
                connections=10, **{'memory.current': '1234', 'pids.current': '4', 'cpu.stat': 'usage_usec ' + str(200 + at*2_000_000), 'processes': [dict(pid=i+1, startTicks='100', rssBytes=4096,
                    cmdline='/usr/bin/ffmpeg\0-i\0/media/source/' + ('video.mkv' if i < 3 else 'track.flac') + '\0') for i in range(4)]}))
        (self.root / 'media.json').write_text(json.dumps(dict(evidence=dict(videoJobs=['one', 'two', 'three'], audioBytes=8192,
            desiredStateSamples=[dict(seconds=.5)], pressureStartedAt=300, pressureEndedAt=360,
            ordinaryBacklog=[dict(monotonic=300+i, receiverDepth=2000, acknowledged=i, relayedEvents=i) for i in range(61)],
            dueOccurrences=[dict(execution=dict(deployment_namespace='qualification', deployment_boot_id='boot', deployment_epoch=1, returned=True, completion_seconds=5)) for _ in range(4)]))))
        (self.root / 'containers-before.json').write_text(json.dumps([
            dict(Created='1970-01-01T00:15:00Z', State=dict(StartedAt='1970-01-01T00:15:50Z')),
            dict(Created='1970-01-01T00:15:00Z', State=dict(StartedAt='1970-01-01T00:15:50Z'))]))
        self.write()

    def write(self):
        for unit in ('control', 'execution'):
            (self.root / (unit + '-samples.jsonl')).write_text(''.join(json.dumps(v) + '\n' for v in self.values))

    def test_complete_evidence(self):
        qualification.verify(self.root, 2)
        self.assertTrue((self.root / 'qualification.json').is_file())

    def test_missing_completion_rejected(self):
        for value in self.values:
            value['completions'] = ''
        self.write()
        with self.assertRaises(AssertionError): qualification.burst(self.root, 'cold')

    def test_resident_overadmission_rejected(self):
        self.values[10]['admission']['target'] = 3
        self.write()
        with self.assertRaises(AssertionError): qualification.verify(self.root, 2)

    def test_foreign_lease_rejected(self):
        self.values[10]['lease']['epoch'] = 2
        self.write()
        with self.assertRaises(AssertionError): qualification.verify(self.root, 2)

    def test_stalled_control_rejected(self):
        self.values[10]['monotonic'] = 20
        self.write()
        with self.assertRaises(AssertionError): qualification.verify(self.root, 2)

    def test_unmeasured_pressure_rejected(self):
        for value in self.values:
            value['admission']['reason'] = 'stable'
        self.write()
        with self.assertRaises(AssertionError): qualification.verify(self.root, 2)

    def test_idle_stale_controller_rejected(self):
        self.values[-1]['admission']['controllerAt'] = 0
        self.write()
        with self.assertRaises(AssertionError): qualification.idle(self.root)

    def test_pressure_uses_recovered_after_evidence(self):
        for at in range(300, 306): self.values[at]['admission']['target'] = 1
        self.values[306]['admission']['evidence']['cpuUtilization'] = .1
        self.write()
        qualification.verify(self.root, 2)

    def test_pressure_rejects_growth_on_after_high_evidence(self):
        for at in range(300, 306): self.values[at]['admission']['target'] = 1
        self.values[305]['admission']['evidence']['cpuUtilization'] = .1
        self.write()
        with self.assertRaises(AssertionError): qualification.verify(self.root, 2)

    def test_middle_stale_status_rejected(self):
        self.values[200]['status'] = dict(state='unavailable', authorityValid=False, controllerFresh=False, resourceFresh=False)
        self.write()
        with self.assertRaises(AssertionError): qualification.verify(self.root, 2)

    def test_truncated_execution_rejected(self):
        (self.root / 'execution-samples.jsonl').write_text(json.dumps(self.values[0]) + '\n')
        with self.assertRaises(AssertionError): qualification.verify(self.root, 2)

    def test_gapped_execution_rejected(self):
        (self.root / 'execution-samples.jsonl').write_text(''.join(json.dumps(v) + '\n' for v in self.values if not 320 <= v['monotonic'] < 330))
        with self.assertRaises(AssertionError): qualification.verify(self.root, 2)

    def test_disappearing_native_job_rejected(self):
        self.values[320]['processes'] = [dict(pid=1)]
        self.write()
        with self.assertRaises(AssertionError): qualification.verify(self.root, 2)

    def test_middle_stale_resource_rejected(self):
        self.values[200]['resource']['observation']['sampledAt'] = 190
        self.write()
        with self.assertRaises(AssertionError): qualification.verify(self.root, 2)

    def test_middle_foreign_peer_rejected(self):
        self.values[200]['execution']['epoch'] = 2
        self.write()
        with self.assertRaises(AssertionError): qualification.verify(self.root, 2)

    def test_middle_stale_known_resource_status_rejected(self):
        self.values[200]['status']['resourceFresh'] = False
        self.write()
        with self.assertRaises(AssertionError): qualification.verify(self.root, 2)

    def test_middle_unknown_resource_is_classified(self):
        self.values[200]['resource']['observation']['state'] = 'unknown'
        self.values[200]['status']['resources']['state'] = 'unknown'
        self.values[200]['status']['resourceFresh'] = False
        self.write()
        qualification.verify(self.root, 2)
        report = json.loads((self.root / 'qualification.json').read_text())
        self.assertEqual(report['resourceStates']['unknown'], 1)

    def test_short_pressure_window_rejected(self):
        media = json.loads((self.root / 'media.json').read_text())
        media['evidence']['pressureEndedAt'] = 350
        (self.root / 'media.json').write_text(json.dumps(media))
        with self.assertRaises(AssertionError): qualification.verify(self.root, 2)

    def test_pressure_boundary_missing_rejected(self):
        (self.root / 'execution-samples.jsonl').write_text(''.join(json.dumps(v) + '\n' for v in self.values if v['monotonic'] >= 303))
        with self.assertRaises(AssertionError): qualification.verify(self.root, 2)

    def test_lease_and_publication_authority_differ_rejected(self):
        for sample in self.values:
            sample['lease']['owner_boot_id'] = 'foreign'
        self.write()
        with self.assertRaises(AssertionError): qualification.verify(self.root, 2)

    def test_known_resource_missing_pressure_rejected(self):
        self.values[200]['resource']['observation']['cpuUtilization'] = None
        self.write()
        with self.assertRaises(AssertionError): qualification.verify(self.root, 2)

    def test_native_video_only_disappearance_rejected(self):
        self.values[320]['processes'] = [p for p in self.values[320]['processes'] if 'track.flac' in p['cmdline']]
        self.write()
        with self.assertRaises(AssertionError): qualification.verify(self.root, 2)

    def test_native_audio_only_disappearance_rejected(self):
        self.values[320]['processes'] = [p for p in self.values[320]['processes'] if 'video.mkv' in p['cmdline']]
        self.write()
        with self.assertRaises(AssertionError): qualification.verify(self.root, 2)

    def test_missing_cpu_batch_rejected(self):
        for sample in self.values:
            sample['completions'] = ''.join(line + '\n' for line in sample['completions'].splitlines() if json.loads(line)['batch'] != 'cpu')
        self.write()
        with self.assertRaises(AssertionError): qualification.verify(self.root, 2)

    def test_fake_cpu_sleep_rejected(self):
        for sample in self.values:
            rows = [json.loads(line) for line in sample['completions'].splitlines()]
            for row in rows:
                if row['batch'] == 'cpu': row['cpuSeconds'] = .001
            sample['completions'] = ''.join(json.dumps(row) + '\n' for row in rows)
        self.write()
        with self.assertRaises(AssertionError): qualification.verify(self.root, 2)

    def test_foreign_scheduler_identity_rejected(self):
        media = json.loads((self.root / 'media.json').read_text())
        media['evidence']['dueOccurrences'][0]['execution']['deployment_boot_id'] = 'foreign'
        (self.root / 'media.json').write_text(json.dumps(media))
        with self.assertRaises(AssertionError): qualification.verify(self.root, 2)

    def test_unreturned_scheduler_rejected(self):
        media = json.loads((self.root / 'media.json').read_text())
        media['evidence']['dueOccurrences'][0]['execution']['returned'] = False
        (self.root / 'media.json').write_text(json.dumps(media))
        with self.assertRaises(AssertionError): qualification.verify(self.root, 2)

    def test_stalled_ordinary_progress_rejected(self):
        media = json.loads((self.root / 'media.json').read_text())
        for row in media['evidence']['ordinaryBacklog']: row['acknowledged'] = 0
        (self.root / 'media.json').write_text(json.dumps(media))
        with self.assertRaises(AssertionError): qualification.verify(self.root, 2)

    def test_cooldown_oscillation_rejected(self):
        self.values[10]['admission']['target'] = 1
        self.write()
        with self.assertRaises(AssertionError): qualification.verify(self.root, 2)

    def test_cpu_cgroup_pressure_missing_rejected(self):
        for at in range(250, 283): self.values[at]['cpu.stat'] = 'usage_usec 500000000'
        self.write()
        with self.assertRaises(AssertionError): qualification.verify(self.root, 2)

    def test_cpu_growth_on_high_own_evidence_rejected(self):
        for at in range(250, 266): self.values[at]['admission']['target'] = 1
        self.write()
        with self.assertRaises(AssertionError): qualification.verify(self.root, 2)

    def test_scheduler_completion_deadline_rejected(self):
        media = json.loads((self.root / 'media.json').read_text())
        media['evidence']['dueOccurrences'][0]['execution']['completion_seconds'] = 11
        (self.root / 'media.json').write_text(json.dumps(media))
        with self.assertRaises(AssertionError): qualification.verify(self.root, 2)

    def test_stalled_outbox_progress_rejected(self):
        media = json.loads((self.root / 'media.json').read_text())
        for row in media['evidence']['ordinaryBacklog']: row['relayedEvents'] = 0
        (self.root / 'media.json').write_text(json.dumps(media))
        with self.assertRaises(AssertionError): qualification.verify(self.root, 2)

    def test_idle_unread_backlog_during_receiving_rejected(self):
        for sample in self.values[-3:]:
            sample['admission']['evidence']['unreadCount'] = 800
            for slot in sample['admission']['slots'].values():
                slot.update(state='receiving', requestId='empty-poll')
        self.write()
        with self.assertRaises(AssertionError): qualification.idle(self.root)

    def test_idle_pending_backlog_rejected(self):
        for sample in self.values[-3:]: sample['admission']['evidence']['pendingCount'] = 1
        self.write()
        with self.assertRaises(AssertionError): qualification.idle(self.root)

    def test_idle_due_backlog_rejected(self):
        for sample in self.values[-3:]: sample['admission']['evidence']['dueCount'] = 1
        self.write()
        with self.assertRaises(AssertionError): qualification.idle(self.root)

    def test_idle_unknown_queue_rejected(self):
        for sample in self.values[-3:]: sample['admission']['evidence']['queueState'] = 'unknown'
        self.write()
        with self.assertRaises(AssertionError): qualification.idle(self.root)

    def test_idle_stale_queue_rejected(self):
        for sample in self.values[-3:]: sample['admission']['evidence']['queueSampleAgeSeconds'] = 4
        self.write()
        with self.assertRaises(AssertionError): qualification.idle(self.root)

    def test_idle_empty_receiving_poll_accepted(self):
        for sample in self.values[-3:]:
            for slot in sample['admission']['slots'].values(): slot.update(state='receiving', requestId='empty-poll')
        self.write()
        qualification.idle(self.root)

    def test_partial_line_during_sampling(self):
        with (self.root / 'control-samples.jsonl').open('a') as stream: stream.write('{')
        self.assertEqual(len(qualification.samples(self.root)), 400)


class GapReceiptTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.root = pathlib.Path(self.temporary.name)
        self.receipt = sample_gap_receipt()

    def check(self):
        (self.root / 'gap.json').write_text(json.dumps(self.receipt))
        return qualification.gap_receipt(self.root)

    def test_valid_stale_recovery_receipt(self): self.check()

    def test_consumed_while_stale_rejected(self):
        self.receipt['unreadProof']['pending'] = 1
        with self.assertRaises(AssertionError): self.check()

    def test_falsely_fresh_stale_status_rejected(self):
        self.receipt['staleStatus']['controllerFresh'] = True
        with self.assertRaises(AssertionError): self.check()

    def test_missing_recovery_rejected(self):
        self.receipt['recoveredStatus']['state'] = 'unavailable'
        with self.assertRaises(AssertionError): self.check()

    def test_foreign_lease_rejected(self):
        self.receipt['staleHealth']['lease']['owner_boot_id'] = 'foreign'
        with self.assertRaises(AssertionError): self.check()

    def test_overlong_gap_rejected(self):
        self.receipt['finishedAt'] = 126
        with self.assertRaises(AssertionError): self.check()


if __name__ == '__main__': unittest.main()
