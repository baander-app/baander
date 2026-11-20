"""Exercise source provenance through the runner before any Docker operation."""

import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import unittest


class SourceIdentityTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        self.repo = self.root / 'repo'
        self.source = self.root / 'production'
        bench = self.repo / 'tools/typephp/benchmarks'
        bench.mkdir(parents=True)
        self.script = bench / 'run.py'
        shutil.copyfile(Path(__file__).with_name('run.py'), self.script)
        for root in (self.repo, self.source):
            (root / 'src').mkdir(parents=True)
            (root / 'src/application.php').write_text('<?php echo "application";\n')
        (self.source / 'vendor/composer').mkdir(parents=True)
        (self.source / 'vendor/composer/installed.json').write_text(
            json.dumps({'dev': False, 'packages': []}))
        self.git('init', '--quiet')
        self.git('add', 'src/application.php')
        self.tree = self.git('write-tree').strip()
        self.marker = self.root / 'docker-was-called'
        binaries = self.root / 'commands'
        binaries.mkdir()
        docker = binaries / 'docker'
        docker.write_text('#!/bin/sh\nprintf reached > "$BENCHMARK_DOCKER_MARKER"\nexit 91\n')
        docker.chmod(0o755)
        self.environment = dict(os.environ, PATH=f'{binaries}:{os.environ["PATH"]}',
                                BENCHMARK_DOCKER_MARKER=str(self.marker))

    def git(self, *args):
        return subprocess.check_output(['git', *args], cwd=self.repo, text=True)

    def collect(self):
        return subprocess.run([
            sys.executable, str(self.script), '--source', str(self.source),
            '--ordinary-image', 'ordinary', '--typephp-image', 'package',
            '--binary-sha256', 'unused-before-docker', '--source-commit', self.tree,
            '--output', str(self.root / 'results'),
        ], cwd=self.repo, env=self.environment, capture_output=True, text=True, timeout=10)

    def test_extra_application_source_is_rejected_before_docker(self):
        (self.source / 'src/extra.php').write_text('<?php echo "untracked application";\n')
        result = self.collect()
        self.assertNotEqual(0, result.returncode)
        self.assertIn('src/extra.php', result.stderr)
        self.assertFalse(self.marker.exists())

    def test_matching_source_allows_generated_vendor_metadata(self):
        result = self.collect()
        self.assertNotEqual(0, result.returncode)
        self.assertTrue(self.marker.exists(), result.stderr)

    def test_changed_tracked_source_is_rejected_before_docker(self):
        (self.source / 'src/application.php').write_text('<?php echo "changed";\n')
        result = self.collect()
        self.assertNotEqual(0, result.returncode)
        self.assertIn('src/application.php', result.stderr)
        self.assertFalse(self.marker.exists())


if __name__ == '__main__':
    unittest.main()
