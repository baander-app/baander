from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from dev_environment.build import snapshot_worktree, checkout_revision
from dev_environment.model import WorkflowError
from dev_environment.process import Runner


class SnapshotTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.repo = self.root / 'repo'
        self.repo.mkdir()
        self.git('init', '-b', 'master')
        self.git('config', 'user.email', 'dev@baander.app')
        self.git('config', 'user.name', 'Development fixture')
        (self.repo / 'src').mkdir()
        (self.repo / 'src/code.py').write_text('original')
        (self.repo / '.env').write_text('APP_SECRET=template')
        (self.repo / '.gitignore').write_text('.env.local\nvar/\n')
        self.git('add', '.')
        self.git('commit', '-m', 'fixture')

    def git(self, *args):
        return subprocess.run(['git', '-C', str(self.repo), *args], check=True,
                              capture_output=True, text=True).stdout.strip()

    def test_preview_captures_edits_without_host_secrets_or_generated_state(self):
        (self.repo / 'src/code.py').write_text('edited')
        (self.repo / 'src/new.py').write_text('new')
        (self.repo / '.env').write_text('APP_SECRET=actual-secret')
        (self.repo / '.env.local').write_text('private')
        (self.repo / 'var').mkdir()
        (self.repo / 'var/state').write_text('runtime')
        target = self.root / 'snapshot'
        first = snapshot_worktree(Runner(), self.repo, target)
        self.assertEqual('edited', (target / 'src/code.py').read_text())
        self.assertTrue((target / 'src/new.py').exists())
        self.assertEqual('APP_SECRET=template', (target / '.env').read_text())
        self.assertFalse((target / '.env.local').exists())
        self.assertFalse((target / 'var').exists())
        self.assertFalse((target / '.git').exists())
        self.assertEqual(first, snapshot_worktree(Runner(), self.repo, self.root / 'again'))
        (self.repo / 'src/code.py').write_text('newer')
        self.assertNotEqual(first, snapshot_worktree(Runner(), self.repo, self.root / 'newer'))

    def test_external_symlink_is_not_followed(self):
        (self.repo / 'src/escape').symlink_to(self.root)
        with self.assertRaises(WorkflowError):
            snapshot_worktree(Runner(), self.repo, self.root / 'snapshot')

    def test_managed_checkout_uses_remote_master_not_dirty_caller(self):
        revision = self.git('rev-parse', 'HEAD')
        (self.repo / 'src/code.py').write_text('uncommitted')
        managed, resolved = checkout_revision(Runner(), str(self.repo), self.root / 'managed', self.root / 'release')
        self.assertEqual(revision, resolved)
        self.assertEqual('original', (managed / 'src/code.py').read_text())
        self.assertEqual('uncommitted', (self.repo / 'src/code.py').read_text())


if __name__ == '__main__':
    unittest.main()
