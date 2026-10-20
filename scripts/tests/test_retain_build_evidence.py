"""Exercise build-source retention and final output binding without a Docker build."""
import hashlib
import json
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest


HELPER = Path(__file__).resolve().parents[1] / "retain-build-evidence.php"


@unittest.skipUnless(shutil.which("php"), "PHP CLI required")
class BuildEvidenceTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)

    def run_helper(self, command, *arguments, succeeds=True):
        result = subprocess.run(
            ["php", str(HELPER), command, *map(str, arguments)],
            text=True, capture_output=True, check=False,
        )
        self.assertEqual(result.returncode == 0, succeeds, result.stderr)
        return result

    def collect(self, name="native", notices=True):
        source = self.root / name
        source.mkdir()
        if notices:
            (source / "LICENSE").write_text("upstream terms")
            (source / "vendor").mkdir()
            (source / "vendor" / "NOTICE.txt").write_text("embedded attribution")
        package = source / "package.xml"
        package.write_text("<package><version><release>2.3.4</release></version></package>")
        output = self.root / (name + "-evidence")
        artifact = self.root / (name + ".so")
        artifact.write_bytes(b"compiled bytes")
        self.run_helper("collect", "--output", output, "--id", name,
                        "--name", name, "--source-dir", source,
                        "--package-xml", package, "--artifact", artifact,
                        "--artifact", self.root / "absent-*.so")
        return output, artifact

    def test_recursive_notices_and_final_artifact_hashes(self):
        inputs, artifact = self.collect()
        artifact.write_bytes(b"final stripped bytes")
        output = self.root / "final"
        self.run_helper("finalize", "--output", output, "--input", inputs)
        component = json.loads((output / "components.json").read_text())["components"][0]
        self.assertEqual(component["version"], "2.3.4")
        self.assertEqual(len(component["notices"]), 2)
        for notice in component["notices"]:
            self.assertTrue((output / notice.removeprefix("/usr/share/baander-build-evidence/")).is_file())
        self.assertEqual(component["artifacts"], [{"path": str(artifact), "sha256": hashlib.sha256(artifact.read_bytes()).hexdigest()}])
        self.assertEqual(component["embedded_dependencies"], "unreviewed")
        self.assertEqual(len(component["metadata"]["unmatched_artifact_patterns"]), 1)

    def test_missing_notices_remain_explicit(self):
        inputs, _ = self.collect(notices=False)
        output = self.root / "final"
        self.run_helper("finalize", "--output", output, "--input", inputs)
        component = json.loads((output / "components.json").read_text())["components"][0]
        self.assertEqual(component["notices"], [])
        self.assertIn("No upstream notices", component["metadata"]["notices_gap"])
        self.assertIsNone(component["source"]["url"])

    def test_collect_appends_components_in_one_stage_directory(self):
        output, artifact = self.collect()
        self.run_helper("collect", "--output", output, "--id", "second",
                        "--name", "second", "--source-dir", self.root / "native",
                        "--artifact", artifact)
        components = json.loads((output / "components.json").read_text())["components"]
        self.assertEqual([item["id"] for item in components], ["native", "second"])

    def test_missing_exact_artifact_fails_finalization(self):
        inputs, artifact = self.collect()
        artifact.unlink()
        result = self.run_helper("finalize", "--output", self.root / "final",
                                 "--input", inputs, succeeds=False)
        self.assertIn("Expected final artifact is missing", result.stderr)
        self.assertIn(str(artifact), result.stderr)

    def test_duplicate_components_fail(self):
        inputs, _ = self.collect()
        result = self.run_helper("finalize", "--output", self.root / "final", "--input", inputs, "--input", inputs, succeeds=False)
        self.assertIn("Duplicate component", result.stderr)

    def test_tool_versions_from_retained_literals_and_own_notice_gap(self):
        cases = [
            ("composer", "src/Composer/Composer.php", "public const VERSION = '2.10.3';", "2.10.3"),
            ("pie", "src/Util/PieVersion.php", 'private const PIE_VERSION = "1.5.2";', "1.5.2"),
            ("composer", "src/Composer/Composer.php", "public const VERSION = '@package_version@';", None),
            ("pie", "src/Other.php", "private const PIE_VERSION = '1.5.2';", None),
        ]
        create = r'''$p = new Phar($argv[1]);
$p->setAlias($argv[5]);
$p[$argv[2]] = $argv[3];
$p['vendor/dependency/LICENSE'] = 'dependency terms';
$p->setStub('<?php file_put_contents(' . var_export($argv[4], true) . ', "executed"); __HALT_COMPILER();');
'''
        for index, (identity, source_path, literal, expected) in enumerate(cases):
            with self.subTest(identity=identity, literal=literal, source_path=source_path):
                archive = self.root / (str(index) + ".phar")
                marker = self.root / (str(index) + "-executed")
                source = "<?php\n" + literal + "\nfile_put_contents(" + repr(str(marker)) + ", 'executed');"
                subprocess.run(["php", "-d", "phar.readonly=0", "-r", create,
                                str(archive), source_path, source, str(marker), identity + ".phar"],
                               check=True, capture_output=True)
                output = self.root / (str(index) + "-evidence")
                self.run_helper("phar", "--output", output, "--id", identity,
                                "--name", identity, "--archive", archive)
                component = json.loads((output / "components.json").read_text())["components"][0]
                self.assertEqual(component["version"], expected)
                self.assertFalse(marker.exists())
                self.assertEqual(len(component["notices"]), 1)
                self.assertEqual(component["metadata"]["own_notices"], [])
                self.assertIn("tool itself", component["metadata"]["own_notices_gap"])
                self.assertEqual(component["metadata"]["version_gap"] is None, expected is not None)
                if source_path != "src/Other.php":
                    retained = output / component["metadata"]["version_source"].removeprefix("/usr/share/baander-build-evidence/")
                    self.assertEqual(retained.read_text(), source)

    def test_phar_index_and_metadata_are_read_without_execution(self):
        archive = self.root / "tool.phar"
        marker = self.root / "executed"
        create = r'''
$p = new Phar($argv[1]);
$p->startBuffering();
$p['LICENSE'] = 'tool terms';
$p['vendor/embedded/COPYING'] = 'embedded terms';
$p['vendor/composer/installed.php'] = '<?php file_put_contents(' . var_export($argv[2], true) . ', "metadata executed");';
$p->setStub('<?php file_put_contents(' . var_export($argv[2], true) . ', "entrypoint executed"); __HALT_COMPILER();');
$p->stopBuffering();
'''
        subprocess.run(["php", "-d", "phar.readonly=0", "-r", create, str(archive), str(marker)], check=True, capture_output=True)
        output = self.root / "phar-evidence"
        self.run_helper("phar", "--output", output, "--id", "tool", "--name", "tool", "--archive", archive, "--artifact", archive)
        self.assertFalse(marker.exists())
        component = json.loads((output / "components.json").read_text())["components"][0]
        self.assertEqual(len(component["notices"]), 2)
        self.assertEqual(len(component["metadata"]["retained_metadata"]), 1)
        retained = output / component["metadata"]["retained_metadata"][0].removeprefix("/usr/share/baander-build-evidence/")
        self.assertIn("metadata executed", retained.read_text())
        self.assertEqual(component["source"]["sha256"], hashlib.sha256(archive.read_bytes()).hexdigest())


if __name__ == "__main__":
    unittest.main()
