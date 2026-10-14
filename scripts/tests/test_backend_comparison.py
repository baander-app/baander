"""Exact coverage comparison contracts without requiring PHP on the test host."""

import hashlib
import importlib.util
import json
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest.mock import patch


SCRIPT = Path(__file__).resolve().parents[1] / "compare-backend-runs.py"
SPEC = importlib.util.spec_from_file_location("backend_comparison", SCRIPT)
comparison = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(comparison)
SOURCE = "/var/www/html/src/Probe.php"


class CoverageComparisonTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.directory = Path(self.temporary.name)
        (self.directory / "run").mkdir()
        (self.directory / "reports").mkdir()
        (self.directory / "run/discovery.json").write_text(json.dumps({
            "runId": "current", "coverage": True, "seed": 123,
            "files": {"tests/Unit/ProbeTest.php": ["ProbeTest::testProbe"]}}))
        self.lane("ordinary")
        self.clover(1, 1, '<line type="method" num="5" count="1"/>')

    def lane(self, name):
        path = self.directory / f"run/{name}.cov"
        path.write_bytes(b"serialized coverage fixture")
        result = {"status": 0, "runId": "current", "identities": ["ProbeTest::testProbe"],
                  "hashes": {"coverage": hashlib.sha256(path.read_bytes()).hexdigest()}}
        (self.directory / f"run/{name}.result.json").write_text(json.dumps(result))

    def clover(self, statements, covered, entries):
        (self.directory / "reports/clover.xml").write_text(
            f'<coverage><project><file name="{SOURCE}">{entries}</file>'
            f'<metrics files="1" classes="1" methods="1" statements="{statements}" '
            f'coveredstatements="{covered}"/></project></coverage>')

    def extracted(self, lines, covered, returncode=0):
        return subprocess.CompletedProcess([], returncode, json.dumps({
            "files": [SOURCE], "lines": [[SOURCE, line] for line in lines],
            "covered": [[SOURCE, line] for line in covered]}), "invalid serialization")

    def test_one_line_method_uses_exact_serialized_executable_identity(self):
        with patch.object(comparison.subprocess, "run", return_value=self.extracted([5], [5])) as run:
            metrics, lines, covered = comparison.coverage(self.directory)
        self.assertEqual(1, metrics["statements"])
        self.assertEqual({(SOURCE, 5)}, lines)
        self.assertEqual(lines, covered)
        command = run.call_args.args[0]
        self.assertEqual(["php", "-d", "memory_limit=2G", "-r"], command[:4])
        self.assertEqual(str((self.directory / "run/ordinary.cov").resolve()), command[-1])

    def test_nonexecutable_method_header_is_excluded(self):
        self.clover(1, 1, '<line type="method" num="10" count="1"/><line type="stmt" num="12" count="1"/>')
        with patch.object(comparison.subprocess, "run", return_value=self.extracted([12], [12])):
            _, lines, covered = comparison.coverage(self.directory)
        self.assertEqual({(SOURCE, 12)}, lines)
        self.assertEqual(lines, covered)

    def test_all_validated_lanes_are_passed_to_extractor(self):
        self.lane("stack-1")
        self.clover(2, 1, '<line type="method" num="5" count="1"/><line type="stmt" num="8" count="0"/>')
        with patch.object(comparison.subprocess, "run", return_value=self.extracted([5, 8], [5])) as run:
            _, lines, covered = comparison.coverage(self.directory)
        self.assertEqual({(SOURCE, 5), (SOURCE, 8)}, lines)
        self.assertEqual({(SOURCE, 5)}, covered)
        self.assertEqual([str(path.resolve()) for path in sorted((self.directory / "run").glob("*.cov"))], run.call_args.args[0][-2:])

    def test_missing_and_changed_serialization_are_rejected_before_extraction(self):
        path = self.directory / "run/ordinary.cov"
        for mode in ("missing", "changed"):
            with self.subTest(mode=mode):
                self.lane("ordinary")
                path.unlink() if mode == "missing" else path.write_bytes(b"changed")
                with patch.object(comparison.subprocess, "run") as run, self.assertRaises(ValueError):
                    comparison.coverage(self.directory)
                run.assert_not_called()

    def test_stale_results_are_rejected_before_extraction(self):
        path = self.directory / "run/ordinary.result.json"
        result = json.loads(path.read_text())
        result["runId"] = "previous"
        path.write_text(json.dumps(result))
        with patch.object(comparison.subprocess, "run") as run, self.assertRaises(ValueError):
            comparison.coverage(self.directory)
        run.assert_not_called()

    def test_corrupt_serialization_extractor_failure_is_blocking(self):
        with patch.object(comparison.subprocess, "run", return_value=self.extracted([], [], 1)), self.assertRaisesRegex(ValueError, "Cannot read serialized coverage"):
            comparison.coverage(self.directory)

    def test_zero_or_mismatched_denominators_are_rejected(self):
        for statements in (0, 2):
            with self.subTest(statements=statements):
                self.clover(statements, 1, '<line type="method" num="5" count="1"/>')
                with patch.object(comparison.subprocess, "run", return_value=self.extracted([5], [5])), self.assertRaises(ValueError):
                    comparison.coverage(self.directory)

    def test_covered_line_count_must_agree_with_clover(self):
        with patch.object(comparison.subprocess, "run", return_value=self.extracted([5], [])), self.assertRaisesRegex(ValueError, "covered-line count"):
            comparison.coverage(self.directory)

    def test_exact_line_difference_survives_equal_clover_counts(self):
        with patch.object(comparison.subprocess, "run", return_value=self.extracted([5], [5])):
            _, baseline, baseline_covered = comparison.coverage(self.directory)
        with patch.object(comparison.subprocess, "run", return_value=self.extracted([6], [6])):
            _, candidate, candidate_covered = comparison.coverage(self.directory)
        self.assertNotEqual(baseline, candidate)
        self.assertNotEqual(baseline_covered, candidate_covered)

    def test_empty_selected_identities_are_rejected(self):
        path = self.directory / "run/discovery.json"
        discovery = json.loads(path.read_text())
        discovery["files"] = {}
        path.write_text(json.dumps(discovery))
        with self.assertRaises(ValueError):
            comparison.identities(self.directory)


if __name__ == "__main__":
    unittest.main()
