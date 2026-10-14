"""Blocking coverage publication checks for PHPUnit text and Clover reports."""

import importlib.util
import json
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest.mock import patch


SCRIPT = Path(__file__).resolve().parents[1] / "validate-backend-coverage.py"
SPEC = importlib.util.spec_from_file_location("backend_coverage", SCRIPT)
coverage = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(coverage)

TEXT = """Code Coverage Report:
  Classes: 50.00% (1/2)
  Methods: 50.00% (1/2)
  Lines:   50.00% (2/4)

Baander\\Covered
  Methods: 100.00% (1/1)  Lines: 100.00% (2/2)
"""
XML = """<coverage><project><file name="/var/www/html/src/Covered.php">
<class name="Covered"><metrics methods="1" coveredmethods="1" statements="2" coveredstatements="2" conditionals="0" coveredconditionals="0"/></class>
<class name="Uncovered"><metrics methods="1" coveredmethods="0" statements="2" coveredstatements="0" conditionals="0" coveredconditionals="0"/></class>
<metrics classes="2" methods="2" coveredmethods="1" statements="4" coveredstatements="2"/>
</file><metrics files="1" classes="2" methods="2" coveredmethods="1" statements="4" coveredstatements="2"/></project></coverage>"""


class CoverageValidationTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.directory = Path(self.temporary.name)
        self.write()

    def write(self, text=TEXT, xml=XML):
        (self.directory / "report.txt").write_text(text)
        (self.directory / "clover.xml").write_text(xml)

    def rejected(self):
        with self.assertRaises((ValueError, OSError, coverage.ET.ParseError)):
            coverage.validate(self.directory)
        self.assertFalse((self.directory / "metrics.json").exists())

    def test_valid_reports_publish_all_metrics(self):
        expected = {"classes": 50.0, "methods": 50.0, "lines": 50.0}
        self.assertEqual(expected, coverage.validate(self.directory))
        self.assertEqual(expected, json.loads((self.directory / "metrics.json").read_text()))
        self.assertEqual([], list(self.directory.glob(".metrics-*")))

    def test_percentages_without_text_counts_are_supported(self):
        self.write(TEXT.replace(" (1/2)", "").replace(" (2/4)", ""))
        coverage.validate(self.directory)

    def test_missing_reports_are_rejected(self):
        for filename in ("report.txt", "clover.xml"):
            with self.subTest(filename=filename):
                self.write()
                (self.directory / filename).unlink()
                self.rejected()

    def test_missing_and_duplicate_summary_metrics_are_rejected(self):
        for text in (TEXT.replace("  Lines:   50.00% (2/4)\n", ""), TEXT + "  Lines: 50.00% (2/4)\n"):
            with self.subTest(text=text):
                self.write(text)
                self.rejected()

    def test_invalid_percentages_are_rejected(self):
        for value in ("nan", "inf", "-1", "100.01", "broken"):
            with self.subTest(value=value):
                self.write(TEXT.replace("Lines:   50.00%", f"Lines:   {value}%"))
                self.rejected()

    def test_invalid_xml_and_empty_projects_are_rejected(self):
        for xml in ("", "broken", "<coverage/>", "<coverage><project><metrics/></project></coverage>"):
            with self.subTest(xml=xml):
                self.write(xml=xml)
                self.rejected()

    def test_invalid_clover_counts_are_rejected(self):
        for replacement in ("-1", "nan", "2.5", "5"):
            with self.subTest(replacement=replacement):
                self.write(xml=XML.replace('coveredstatements="2"', f'coveredstatements="{replacement}"'))
                self.rejected()

    def test_zero_denominators_are_rejected(self):
        for name, value in (("statements", "4"), ("methods", "2"), ("classes", "2")):
            with self.subTest(name=name):
                self.write(xml=XML.replace(f'{name}="{value}"', f'{name}="0"'))
                self.rejected()

    def test_duplicate_project_metrics_are_rejected(self):
        self.write(xml=XML.replace("</project>", '<metrics files="1"/></project>'))
        self.rejected()

    def test_optional_clover_counts_are_also_validated(self):
        for attributes in ('conditionals="1" coveredconditionals="2"', 'elements="4" coveredelements="nan"', 'loc="-1"'):
            with self.subTest(attributes=attributes):
                self.write(xml=XML.replace('<metrics files="1"', f'<metrics {attributes} files="1"'))
                self.rejected()

    def test_zero_executable_statements_are_rejected(self):
        xml = XML.replace('statements="2"', 'statements="0"').replace('statements="4"', 'statements="0"')
        self.write(xml=xml)
        self.rejected()

    def test_project_counts_must_agree_with_files(self):
        self.write(xml=XML.replace('<metrics files="1"', '<metrics files="2"'))
        self.rejected()

    def test_text_counts_must_agree_with_clover(self):
        self.write(TEXT.replace("Lines:   50.00% (2/4)", "Lines:   50.00% (3/6)"))
        self.rejected()

    def test_text_percentage_must_agree_with_clover(self):
        self.write(TEXT.replace("Lines:   50.00%", "Lines:   49.98%"))
        self.rejected()

    def test_rounding_tolerance_is_accepted(self):
        self.write(TEXT.replace("Lines:   50.00%", "Lines:   49.99%"))
        coverage.validate(self.directory)

    def test_zero_covered_counts_are_valid(self):
        text = TEXT.split("\n\n")[0].replace("50.00%", "0.00%").replace("(1/2)", "(0/2)").replace("(2/4)", "(0/4)")
        xml = XML.replace('coveredmethods="1"', 'coveredmethods="0"').replace('coveredstatements="2"', 'coveredstatements="0"')
        self.write(text, xml)
        self.assertEqual({"classes": 0.0, "methods": 0.0, "lines": 0.0}, coverage.validate(self.directory))

    def test_branch_coverage_determines_covered_classes(self):
        xml = XML.replace('conditionals="0" coveredconditionals="0"', 'conditionals="2" coveredconditionals="1"')
        self.write(TEXT.replace("Classes: 50.00% (1/2)", "Classes: 0.00% (0/2)"), xml)
        self.assertEqual(0.0, coverage.validate(self.directory)["classes"])

    def test_failure_preserves_previous_metrics(self):
        published = self.directory / "metrics.json"
        published.write_text('{"previous": true}\n')
        self.write(TEXT.replace("Lines:   50.00%", "Lines:   12.00%"))
        with self.assertRaises(ValueError):
            coverage.validate(self.directory)
        self.assertEqual('{"previous": true}\n', published.read_text())

    def test_failed_atomic_replace_preserves_previous_metrics_and_removes_staging(self):
        published = self.directory / "metrics.json"
        published.write_text('{"previous": true}\n')
        with patch.object(coverage.os, "replace", side_effect=OSError("disk unavailable")), self.assertRaises(OSError):
            coverage.validate(self.directory)
        self.assertEqual('{"previous": true}\n', published.read_text())
        self.assertEqual([], list(self.directory.glob(".metrics-*")))

    def test_cli_default_directory_and_failure_status(self):
        directory = self.directory / "coverage"
        directory.mkdir()
        (directory / "report.txt").write_text(TEXT)
        (directory / "clover.xml").write_text(XML)
        valid = subprocess.run(["python3", str(SCRIPT)], cwd=self.directory, capture_output=True)
        self.assertEqual(0, valid.returncode, valid.stderr)
        (directory / "clover.xml").unlink()
        invalid = subprocess.run(["python3", str(SCRIPT), str(directory)], capture_output=True)
        self.assertEqual(1, invalid.returncode)


if __name__ == "__main__":
    unittest.main()
