"""Focused correctness checks; these do not execute benchmark samples."""
import hashlib
import json
from pathlib import Path
import sys
import tempfile
import unittest
from unittest.mock import patch

import measure


class MeasurementTests(unittest.TestCase):
    def test_child_failure_keeps_output_and_resource_observations(self):
        record = measure.child([sys.executable, '-c',
                                'import sys; print("failure detail"); sys.exit(7)'])
        self.assertEqual(7, record['returncode'])
        self.assertEqual('failure detail\n', record['stdout'])
        self.assertGreater(record['wall_ns'], 0)
        self.assertGreater(record['peak_rss_kib'], 0)
        with self.assertRaisesRegex(ValueError, 'Child exited 7'):
            measure.validate(record, 'console', 1, '')

    def test_sustained_checksum_is_independent_expected_result(self):
        expected = hashlib.sha256(
            b'{"public_id":"0123456789abcdefghijk",'
            b'"uuid":"01890f47-14c4-7cc0-98c4-dc0c1c073ef6",'
            b'"binary":"01890f4714c47cc098c4dc0c1c073ef6","equal":true}' * 2
        ).hexdigest()
        self.assertEqual(expected, measure.expected_checksum(2))
        record = {'returncode': 0, 'stderr': '', 'stdout': json.dumps({
            'iterations': 2, 'checksum': expected, 'elapsed_ns': 500,
        })}
        measure.validate(record, 'sustained', 2, expected)
        self.assertEqual(500, record['internal_ns'])
        for field, wrong in [('iterations', 3), ('checksum', 'wrong'), ('elapsed_ns', 0)]:
            with self.subTest(field=field):
                result = dict(record['result'], **{field: wrong})
                with self.assertRaises(ValueError):
                    measure.validate(dict(record, stdout=json.dumps(result)), 'sustained', 2, expected)

    def test_statistics_use_nearest_rank_p95_and_true_median(self):
        result = measure.stats(list(range(1, 31)))
        self.assertEqual({'count': 30, 'median': 15.5, 'p95': 29, 'min': 1, 'max': 30}, result)
        for invalid in [[], [0], [-1], [float('nan')], [float('inf')]]:
            with self.subTest(invalid=invalid), self.assertRaises(ValueError):
                measure.stats(invalid)

    def test_failed_workload_has_no_ratio(self):
        records = [dict(phase='sample', workload='console', runtime='normal', status='failed')]
        self.assertEqual({}, measure.summarize(records))

    def test_ratios_are_typephp_divided_by_normal(self):
        records = [dict(phase='sample', workload='console', runtime=name, status='accepted',
                        wall_ns=value, cpu_seconds=value, peak_rss_kib=value)
                   for name, value in [('normal', 10), ('typephp', 20)]]
        self.assertEqual(2, measure.summarize(records)['console']['wall_ns']['typephp_over_normal_median'])

    def test_failed_run_is_written_before_nonzero_exit(self):
        def fail(args, report):
            report['records'].append({'status': 'failed', 'returncode': 9})
            raise ValueError('deliberate probe failure')

        with tempfile.TemporaryDirectory() as directory:
            output = Path(directory) / 'failure.json'
            arguments = ['measure.py', '--normal', '/normal', '--typephp', '/typephp', '--output', str(output)]
            with patch.object(sys, 'argv', arguments), patch.object(measure, 'collect', fail):
                self.assertEqual(1, measure.main())
            report = json.loads(output.read_text())
            self.assertEqual('failed', report['status'])
            self.assertEqual(9, report['records'][0]['returncode'])
            self.assertNotIn('summary', report)

    def test_runtime_command_selects_actual_binary_and_matching_settings(self):
        for name, binary in [('normal', '/normal'), ('typephp', '/package')]:
            runtime = measure.Runtime(name, binary)
            command = runtime.command('/bench/workload.php', [12])
            self.assertEqual(binary, command[0])
            self.assertEqual(['-f', '/bench/workload.php', '--', '12'], command[-4:])
            self.assertIn('auto_prepend_file=', command)
            self.assertIn('opcache.enable_cli=1', command)
            self.assertEqual(measure.EMPTY_INI, runtime.environment()['PHPRC'])


if __name__ == '__main__':
    unittest.main()
