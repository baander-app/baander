#!/usr/bin/env python3
"""Focused application image inventory contract checks."""
import importlib.util
import json
import pathlib
import tempfile
import io
import tarfile
import unittest

SPEC = importlib.util.spec_from_file_location('application_inventory', pathlib.Path(__file__).parents[1] / 'packaged_inventory.py')
inv = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(inv)


class InventoryTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = pathlib.Path(self.temp.name) / 'root'
        self.root.mkdir()
        self.bundle = pathlib.Path(self.temp.name) / 'bundle'
        self.put('/var/lib/dpkg/status', 'Package: libc\nStatus: install ok installed\nVersion: 1\nArchitecture: amd64\n\n')
        self.put('/var/lib/dpkg/info/libc.list', '/usr/lib/libc.so\n')
        self.put('/usr/lib/libc.so', b'\x7fELFfixture')
        self.put('/usr/share/doc/libc/copyright', 'License: fixture\nSee /usr/share/common-licenses/MIT\n')
        self.put('/usr/share/common-licenses/MIT', 'retained notice')
        self.put('/var/www/html/vendor/composer/installed.json', json.dumps({'packages': [{'name': 'acme/runtime', 'version': '1', 'license': ['MIT'], 'install-path': '../acme/runtime'}]}))
        self.put('/var/www/html/vendor/acme/runtime/LICENSE', 'composer notice')
        self.put('/var/www/html/composer.lock', json.dumps({'packages-dev': [{'name': 'acme/dev'}]}))
        self.identity = {'image_id': 'sha256:abc', 'target': 'production', 'platform': 'linux/amd64', 'revision': 'abc'}

    def put(self, name, value):
        path = self.root / name.lstrip('/')
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_bytes(value if isinstance(value, bytes) else value.encode())
        return path

    def generate(self):
        return inv.generate(self.root, self.identity, self.bundle, 'abc')

    def test_installed_composer_excludes_lockfile_dev(self):
        result = self.generate()
        self.assertEqual(['acme/runtime'], [p['name'] for p in result['composer']])
        self.assertEqual('installed-composer', next(f['group'] for f in result['files'] if f['path'].endswith('runtime/LICENSE')))

    def test_composer_metapackage_is_metadata_only_installed_membership(self):
        package = {'name': 'upscale/ext-swoole', 'type': 'metapackage', 'version': 'v6.2.0',
                   'install-path': None, 'license': ['Apache-2.0']}
        self.put('/var/www/html/vendor/composer/installed.json', json.dumps({'packages': [package]}))
        result = self.generate()
        component = result['composer'][0]
        self.assertEqual('upscale/ext-swoole', component['name'])
        self.assertEqual('metapackage', component['type'])
        self.assertIsNone(component['install_path'])
        self.assertFalse(any(component['id'] in f['owners'] for f in result['files']))
        self.assertFalse(any(g.get('component') == component['id'] for g in result['review_gaps']))
        inv.verify(self.root, self.bundle / 'inventory.json', 'abc')

    def test_composer_regular_package_requires_installation_path(self):
        for path in (None, ''):
            with self.subTest(path=path):
                package = {'name': 'acme/runtime', 'type': 'library', 'version': '1',
                           'install-path': path, 'license': ['MIT']}
                self.put('/var/www/html/vendor/composer/installed.json', json.dumps({'packages': [package]}))
                with self.assertRaisesRegex(inv.InventoryError, 'installation path.*acme/runtime'):
                    self.generate()
        package.pop('install-path')
        self.put('/var/www/html/vendor/composer/installed.json', json.dumps({'packages': [package]}))
        with self.assertRaisesRegex(inv.InventoryError, 'installation path.*acme/runtime'):
            self.generate()

    def test_composer_path_repository_owns_physical_source_and_notice(self):
        package = {'name': 'swoole-bundle/swoole-bundle', 'type': 'library', 'version': '1',
                   'install-path': '../swoole-bundle/swoole-bundle', 'license': ['MIT']}
        self.put('/var/www/html/vendor/composer/installed.json', json.dumps({'packages': [package]}))
        self.put('/var/www/html/packages/swoole-bundle/LICENSE', 'local package notice')
        self.put('/var/www/html/packages/swoole-bundle/src/Bundle.php', '<?php // source')
        link = self.root / 'var/www/html/vendor/swoole-bundle/swoole-bundle'
        link.parent.mkdir(parents=True)
        link.symlink_to('../../packages/swoole-bundle/')
        result = self.generate()
        self.assertEqual('/var/www/html/vendor/swoole-bundle/swoole-bundle', result['composer'][0]['install_path'])
        for path in ('/var/www/html/packages/swoole-bundle/src/Bundle.php',
                     '/var/www/html/vendor/swoole-bundle/swoole-bundle'):
            entry = next(f for f in result['files'] if f['path'] == path)
            self.assertEqual('installed-composer', entry['group'])
            self.assertEqual(['composer:swoole-bundle/swoole-bundle'], entry['owners'])
        self.assertTrue(any(e['path'] == '/var/www/html/packages/swoole-bundle/LICENSE' for e in result['evidence']))
        self.assertFalse(any(g.get('component') == 'composer:swoole-bundle/swoole-bundle' for g in result['review_gaps']))
        inv.verify(self.root, self.bundle / 'inventory.json', 'abc')

    def test_debian_absolute_copyright_alias(self):
        path = self.root / 'usr/share/doc/libc/copyright'
        path.unlink()
        self.put('/usr/share/doc/shared/copyright', 'See /usr/share/common-licenses/MIT')
        path.symlink_to('/usr/share/doc/shared/copyright')
        result = self.generate()
        self.assertIn('/usr/share/common-licenses/MIT', [e['path'] for e in result['evidence']])
        inv.verify(self.root, self.bundle / 'inventory.json', 'abc')

    def test_copied_manifest_and_unknown_wasm_are_not_installed_js(self):
        self.put('/var/www/html/ui/package.json', '{"dependencies":{"example":"1"}}')
        self.put('/var/www/html/public/code.wasm', b'\0asmcode')
        result = self.generate()
        self.assertTrue(any(f['kind'] == 'manifest' and f['group'] == 'copied-repository' for f in result['files']))
        self.assertTrue(any(g['path'].endswith('code.wasm') for g in result['review_gaps']))
        self.assertEqual('not-qualified', result['redistribution_review'])

    def test_root_escape_rejected(self):
        with self.assertRaises(inv.InventoryError):
            inv.root_file(self.root, '../../etc/passwd')

    def test_symlink_loop_rejected(self):
        (self.root / 'loop').symlink_to('/loop')
        with self.assertRaises(inv.InventoryError):
            self.generate()

    def test_artifact_tampering_rejected(self):
        self.generate()
        self.put('/usr/lib/libc.so', b'changed')
        with self.assertRaisesRegex(inv.InventoryError, 'libc.so'):
            inv.verify(self.root, self.bundle / 'inventory.json', 'abc')

    def test_retained_notice_tampering_rejected(self):
        result = self.generate()
        (self.bundle / result['evidence'][0]['retained']).write_text('tamper')
        with self.assertRaisesRegex(inv.InventoryError, 'evidence'):
            inv.verify(self.root, self.bundle / 'inventory.json', 'abc')

    def test_new_and_missing_artifacts_rejected(self):
        self.generate()
        path = self.put('/opt/new-tool', b'\x7fELFnew')
        with self.assertRaisesRegex(inv.InventoryError, '/opt/new-tool'):
            inv.verify(self.root, self.bundle / 'inventory.json', 'abc')
        path.unlink()
        (self.root / 'usr/lib/libc.so').unlink()
        with self.assertRaisesRegex(inv.InventoryError, 'libc.so'):
            inv.verify(self.root, self.bundle / 'inventory.json', 'abc')

    def test_revision_mismatch_rejected(self):
        with self.assertRaisesRegex(inv.InventoryError, 'revision'):
            inv.generate(self.root, self.identity, self.bundle, 'other')

    def test_native_unknown_identity_remains_gap(self):
        self.put('/usr/local/bin/native', b'\x7fELFnative')
        self.put('/usr/share/baander-build-evidence/components.json', json.dumps({'schema': 'baander-native-build-evidence/v1', 'components': [{'id': 'native', 'name': 'native', 'version': 'unknown', 'source': {'url': 'unknown'}, 'artifacts': [{'path': '/usr/local/bin/native', 'sha256': inv.file_hash(self.root / 'usr/local/bin/native')}], 'notices': []}]}))
        result = self.generate()
        self.assertTrue(any(g['reason'] == 'native source identity or version incomplete' for g in result['review_gaps']))

    def test_native_embedded_review_flag_does_not_grant_clearance(self):
        self.put('/usr/local/bin/native', b'\x7fELFnative')
        self.put('/usr/share/baander-build-evidence/notices/native/LICENSE', 'native notice')
        self.put('/usr/share/baander-build-evidence/components.json', json.dumps({'schema': 'baander-native-build-evidence/v1', 'components': [{'id': 'native', 'name': 'native', 'version': '1', 'source': {'url': 'https://baander.app/native'}, 'artifacts': [{'path': '/usr/local/bin/native', 'sha256': inv.file_hash(self.root / 'usr/local/bin/native')}], 'notices': ['/usr/share/baander-build-evidence/notices/native/LICENSE'], 'embedded_dependencies': 'reviewed'}]}))
        result = self.generate()
        self.assertEqual('native-build', next(f['group'] for f in result['files'] if f['path'] == '/usr/local/bin/native'))
        self.assertEqual('not-qualified', result['redistribution_review'])

    def test_deduplicated_notices_preserve_both_paths(self):
        self.put('/var/www/html/LICENSE', 'composer notice')
        result = self.generate()
        evidence = [e for e in result['evidence'] if e['path'].endswith('/LICENSE')]
        self.assertEqual(2, len(evidence))
        self.assertEqual(1, len({e['retained'] for e in evidence}))

    def test_inspected_identity_must_match_bundle(self):
        self.generate()
        identity = dict(self.identity, image_id='sha256:other')
        with self.assertRaisesRegex(inv.InventoryError, 'identity'):
            inv.verify(self.root, self.bundle / 'inventory.json', 'abc', identity)

    def test_archive_absolute_symlink_stays_inside_image_resolution(self):
        archive = pathlib.Path(self.temp.name) / 'export.tar'
        with tarfile.open(archive, 'w') as stream:
            link = tarfile.TarInfo('alias')
            link.type = tarfile.SYMTYPE
            link.linkname = '/usr/share/notice'
            stream.addfile(link)
            data = b'image notice'
            item = tarfile.TarInfo('usr/share/notice')
            item.size = len(data)
            stream.addfile(item, io.BytesIO(data))
        exported = pathlib.Path(self.temp.name) / 'exported'
        inv.extract(archive, exported)
        self.assertEqual(b'image notice', inv.root_file(exported, '/alias').read_bytes())

    def test_archive_traversal_rejected(self):
        archive = pathlib.Path(self.temp.name) / 'export.tar'
        with tarfile.open(archive, 'w') as stream:
            item = tarfile.TarInfo('../escaped')
            stream.addfile(item)
        with self.assertRaisesRegex(inv.InventoryError, 'unsafe archive path'):
            inv.extract(archive, pathlib.Path(self.temp.name) / 'exported')

    def test_native_merged_usr_alias_preserves_ownership_and_other_gaps(self):
        (self.root / 'bin').symlink_to('/usr/bin')
        self.put('/usr/bin/pie', b'#!/usr/bin/env php\npie fixture')
        self.put('/usr/bin/unknown', b'\x7fELFunknown')
        self.put('/usr/share/baander-build-evidence/notices/pie/LICENSE', 'PIE notice')
        self.put('/usr/share/baander-build-evidence/components.json', json.dumps({
            'schema': 'baander-native-build-evidence/v1', 'components': [{
                'id': 'pie', 'name': 'PIE', 'version': '1',
                'source': {'url': 'https://baander.app/pie'},
                'artifacts': [{'path': '/bin/pie', 'sha256': inv.file_hash(self.root / 'usr/bin/pie')}],
                'notices': ['/usr/share/baander-build-evidence/notices/pie/LICENSE']
            }]}))
        result = self.generate()
        pie = next(f for f in result['files'] if f['path'] == '/usr/bin/pie')
        self.assertEqual('native-build', pie['group'])
        self.assertEqual(['pie'], pie['owners'])
        self.assertFalse(any(g['path'] == '/usr/bin/pie' for g in result['review_gaps']))
        self.assertTrue(any(g['path'] == '/usr/bin/unknown' for g in result['review_gaps']))
        self.assertTrue(any(g.get('component') == 'pie' and 'embedded' in g['reason'] for g in result['review_gaps']))
        inv.verify(self.root, self.bundle / 'inventory.json', 'abc')

    def test_native_output_hash_bound_to_provenance(self):
        self.put('/usr/local/bin/native', b'\x7fELFnative')
        self.put('/usr/share/baander-build-evidence/components.json', json.dumps({'schema': 'baander-native-build-evidence/v1', 'components': [{'id': 'native', 'name': 'native', 'version': '1', 'source': {'url': 'https://baander.app/native'}, 'artifacts': [{'path': '/usr/local/bin/native', 'sha256': 'incorrect'}], 'notices': []}]}))
        with self.assertRaisesRegex(inv.InventoryError, '/usr/local/bin/native'):
            self.generate()


if __name__ == '__main__':
    unittest.main()
