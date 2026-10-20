# SPDX-License-Identifier: Apache-2.0
"""Artifact inventory controls use disposable exported filesystems."""
import importlib.util
import pathlib
import tempfile
import unittest

SPEC = importlib.util.spec_from_file_location('packaged_inventory', pathlib.Path(__file__).parents[1] / 'tools/packaged_inventory.py')
INV = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(INV)


class InventoryTests(unittest.TestCase):
    def test_modern_go_buildinfo_extracts_replacement(self):
        def s(value):
            data = value.encode()
            return bytes([len(data)]) + data
        modules = '\x00' * 16 + 'path\tmain/cmd\nmod\tmain\tv1\th1:main\ndep\tdep\tv2\th1:dep\n=>\treplaced\tv3\th1:replacement\n' + '\x00' * 16
        binary = b'\xff Go buildinf:' + b'\x08\x02' + bytes(16) + s('go1.25.3') + s(modules)
        observed = INV.go_buildinfo(binary, 'test-binary')
        self.assertEqual('go1.25.3', observed['go_version'])
        self.assertEqual({'path': 'replaced', 'version': 'v3', 'sum': 'h1:replacement'}, observed['modules'][0]['replacement'])

    def test_missing_buildinfo_names_binary(self):
        with self.assertRaisesRegex(INV.InventoryError, 'age-keygen.*Go build information'):
            INV.go_buildinfo(b'ELF', 'age-keygen')

    def test_root_symlinks_resolve_inside_export(self):
        with tempfile.TemporaryDirectory() as directory:
            root = pathlib.Path(directory)
            (root / 'usr/share/doc/common').mkdir(parents=True)
            (root / 'usr/share/doc/common/copyright').write_text('MIT license')
            (root / 'usr/share/doc/pkg').symlink_to('/usr/share/doc/common')
            self.assertEqual(root / 'usr/share/doc/common/copyright', INV.root_file(root, '/usr/share/doc/pkg/copyright'))
            (root / 'broken').symlink_to('/missing')
            with self.assertRaisesRegex(INV.InventoryError, 'missing'):
                INV.root_file(root, '/broken')

    def test_debian_status_excludes_removed_packages(self):
        text = 'Package: live\nStatus: install ok installed\nVersion: 1\nArchitecture: amd64\n\nPackage: removed\nStatus: deinstall ok config-files\nVersion: 1\n'
        self.assertEqual(['live'], [p['Package'] for p in INV.debian_status(text)])


def go_binary(name, dependencies=(), settings=()):
    def string(value):
        data = value.encode()
        size = len(data)
        prefix = bytearray()
        while size >= 128:
            prefix.append((size & 127) | 128)
            size >>= 7
        prefix.append(size)
        return bytes(prefix) + data
    raw = '\x00' * 16 + 'path\tfixture/' + name + '\nmod\tfixture/main\tv1.0.0\th1:main\n'
    for path, version in dependencies:
        raw += 'dep\t' + path + '\t' + version + '\th1:dependency\n'
    for key, value in settings:
        raw += 'build\t' + key + '=' + value + '\n'
    raw += '\x00' * 16
    return b'\xff Go buildinf:\x08\x02' + bytes(16) + string('go1.25.3') + string(raw)


class BundleTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = pathlib.Path(self.temp.name) / 'root'
        self.resolutions = pathlib.Path(self.temp.name) / 'resolutions'
        self.bundle = self.root / INV.BUNDLE_PATH.lstrip('/')
        (self.root / 'var/lib/dpkg').mkdir(parents=True)
        (self.root / 'var/lib/dpkg/status').write_text((pathlib.Path(__file__).parent / 'inventory_fixtures/packages.txt').read_text())
        (self.root / 'usr/share/doc/fixture-lib').mkdir(parents=True)
        (self.root / 'usr/share/doc/fixture-lib/copyright').write_text('Format: https://www.debian.org/doc/packaging-manuals/copyright-format/1.0/\nFiles: *\nCopyright: Fixture authors\nLicense: MIT\n Full fixture license.\n')
        (self.root / 'usr/local/bin').mkdir(parents=True)
        (self.resolutions / 'go').mkdir(parents=True)
        (self.resolutions / 'go/NOTICE').write_text('Fixture license notice\n')
        notice = {'path': 'go/NOTICE', 'sha256': INV.digest((self.resolutions / 'go/NOTICE').read_bytes())}
        self.components = [{'id': key, 'name': key, 'version': 'fixture', 'license': 'MIT',
                            'notices': [notice], 'source': {'url': 'https://baander.app/fixtures/source', 'sha256': 'a' * 64}}
                           for key in ('go-toolchain:go1.25.3', 'go:fixture/main@v1.0.0', 'go:fixture/dependency@v2.0.0')]
        self.components += [{'id': 'native:sqlite', 'name': 'SQLite', 'version': 'fixture', 'license': 'LicenseRef-Public-Domain', 'notices': [notice], 'parent': 'go:fixture/dependency@v2.0.0'},
                            {'id': 'native:api', 'name': 'native API dependency', 'version': 'fixture', 'license': 'MIT', 'notices': [notice], 'family': 'api', 'scope': 'runtime'},
                            {'id': 'native:googletest', 'name': 'GoogleTest', 'version': 'fixture', 'license': 'BSD-3-Clause', 'notices': [notice], 'family': 'api', 'scope': 'build-test'}]
        self.components[2]['incorporated_components'] = ['native:sqlite']
        self.executables = []
        for name in ('rqlited', 'age', 'age-keygen', 'rclone'):
            data = go_binary(name, [('fixture/dependency', 'v2.0.0')])
            (self.root / 'usr/local/bin' / name).write_bytes(data)
            self.executables.append({'path': '/usr/local/bin/' + name, 'sha256': INV.digest(data)})
        api = b'fixture native executable'
        (self.root / 'usr/local/bin/baander-registry').write_bytes(api)
        self.bundle.mkdir(parents=True)
        (self.resolutions / 'native').mkdir()
        source_files = {'boost/boost/fixture.hpp': 'b' * 64, 'json/include/fixture.hpp': 'c' * 64}
        (self.resolutions / 'native/source-files.json').write_text(INV.canonical(source_files))
        (self.bundle / 'api-build.json').write_text(INV.canonical({'sha256': INV.digest(api),
            'linker_map': 'fixture map', 'link_commands': '/opt/openssl/lib/libssl.a /opt/openssl/lib/libcrypto.a',
            'static_libraries': [{'path': '/opt/openssl/lib/libssl.a', 'sha256': 'd' * 64},
                                 {'path': '/opt/openssl/lib/libcrypto.a', 'sha256': 'e' * 64}],
            'inputs': [{'path': '/build/_deps/boost_headers-src/boost/fixture.hpp', 'sha256': 'b' * 64},
                       {'path': '/build/_deps/json-src/include/fixture.hpp', 'sha256': 'c' * 64}]}))
        self.binary_fixture = {p.name: p.read_bytes() for p in (self.root / 'usr/local/bin').iterdir()}
        self.save_resolutions()

    def save_resolutions(self):
        (self.resolutions / 'go/components.json').write_text(INV.canonical(self.components))
        (self.resolutions / 'go/executables.json').write_text(INV.canonical(self.executables))

    def generate(self, kind='backup'):
        for name in self.binary_fixture:
            path = self.root / 'usr/local/bin' / name
            if name not in INV.EXECUTABLES[kind] and path.exists():
                path.unlink()
        return INV.generate(self.root, kind, self.resolutions, self.bundle)

    def test_all_image_kinds_have_deterministic_inventories(self):
        for kind in ('api', 'rqlite', 'backup'):
            with self.subTest(kind=kind):
                for name, content in self.binary_fixture.items():
                    (self.root / 'usr/local/bin' / name).write_bytes(content)
                first = self.generate(kind)
                self.assertEqual(first, self.generate(kind))
                self.assertEqual(first, INV.verify(self.root, self.bundle / 'inventory.json'))
                self.assertEqual(len(INV.EXECUTABLES[kind]), len(first['executables']))
                self.assertNotIn('native:googletest', [c['id'] for c in first['components']])

    def test_removed_transitive_module_names_missing_identity(self):
        self.components = [c for c in self.components if c['id'] != 'go:fixture/dependency@v2.0.0']
        self.save_resolutions()
        with self.assertRaisesRegex(INV.InventoryError, 'fixture/dependency@v2.0.0'):
            self.generate()

    def test_unknown_license_and_missing_notice_fail(self):
        self.components[0]['license'] = 'unknown'
        self.save_resolutions()
        with self.assertRaisesRegex(INV.InventoryError, 'unresolved license.*go-toolchain'):
            self.generate()
        self.components[0]['license'] = 'MIT'
        self.save_resolutions()
        (self.resolutions / 'go/NOTICE').unlink()
        with self.assertRaisesRegex(INV.InventoryError, 'missing retained file'):
            self.generate()

    def test_changed_executable_fails(self):
        self.generate()
        (self.root / 'usr/local/bin/rclone').write_bytes(go_binary('rclone', [('unreviewed/module', 'v9')]))
        with self.assertRaisesRegex(INV.InventoryError, 'rclone.*differs'):
            INV.verify(self.root, self.bundle / 'inventory.json')

    def test_distinct_age_keygen_observation_is_required(self):
        (self.root / 'usr/local/bin/age-keygen').unlink()
        with self.assertRaisesRegex(INV.InventoryError, 'age-keygen'):
            self.generate()

    def test_embedded_native_component_cannot_be_removed_from_bundle(self):
        self.generate('rqlite')
        path = self.bundle / 'inventory.json'
        value = INV.read_json(path)
        value['components'] = [c for c in value['components'] if c['id'] != 'native:sqlite']
        path.write_text(INV.canonical(value))
        with self.assertRaisesRegex(INV.InventoryError, 'differs'):
            INV.verify(self.root, path)

    def test_debian_version_and_retained_notice_tampering_fail(self):
        self.generate()
        status = self.root / 'var/lib/dpkg/status'
        status.write_text(status.read_text().replace('1.2.3-1', '1.2.4-1'))
        with self.assertRaisesRegex(INV.InventoryError, 'differs'):
            INV.verify(self.root, self.bundle / 'inventory.json')
        status.write_text(status.read_text().replace('1.2.4-1', '1.2.3-1'))
        notice = self.bundle / 'debian-notices/usr/share/doc/fixture-lib/copyright'
        notice.write_text('tampered')
        with self.assertRaisesRegex(INV.InventoryError, 'notice changed'):
            INV.verify(self.root, self.bundle / 'inventory.json')

    def test_dirty_source_correspondence_fails_qualification(self):
        self.components[1]['unresolved'] = 'dirty source identity'
        self.save_resolutions()
        with self.assertRaisesRegex(INV.InventoryError, 'fixture/main.*dirty source'):
            self.generate()

    def test_retained_notice_or_resolution_cannot_be_removed(self):
        self.generate()
        path = self.bundle / 'inventory.json'
        value = INV.read_json(path)
        value['debian_notices'] = []
        path.write_text(INV.canonical(value))
        with self.assertRaisesRegex(INV.InventoryError, 'differs'):
            INV.verify(self.root, path)
        self.generate()
        (self.bundle / 'resolutions/go/NOTICE').write_text('unreviewed notice')
        with self.assertRaisesRegex(INV.InventoryError, 'independently reviewed'):
            INV.verify(self.root, path, self.resolutions)

    def test_api_header_must_match_pinned_source(self):
        path = self.bundle / 'api-build.json'
        evidence = INV.read_json(path)
        evidence['inputs'][0]['sha256'] = 'f' * 64
        path.write_text(INV.canonical(evidence))
        with self.assertRaisesRegex(INV.InventoryError, 'compiled source differs.*boost'):
            self.generate('api')

    def test_source_build_requires_exact_clean_revision_and_module_graph(self):
        settings = [('vcs.revision', 'a' * 40), ('vcs.modified', 'false'), ('CGO_ENABLED', '1')]
        path = self.root / 'usr/local/bin/rqlited'
        binary = go_binary('rqlited', [('fixture/dependency', 'v2.0.0')], settings)
        path.write_bytes(binary)
        observation = INV.go_buildinfo(binary, 'rqlited')
        self.executables[0] = {'path': '/usr/local/bin/rqlited', 'source_built': True,
                               'expected_vcs_revision': 'a' * 40,
                               'go_version': observation['go_version'], 'modules': observation['modules'],
                               'package': observation['package'], 'settings': observation['settings']}
        self.save_resolutions()
        self.generate('rqlite')
        settings[2] = ('CGO_ENABLED', '0')
        path.write_bytes(go_binary('rqlited', [('fixture/dependency', 'v2.0.0')], settings))
        with self.assertRaisesRegex(INV.InventoryError, 'runtime build settings differ'):
            self.generate('rqlite')
        settings[2] = ('CGO_ENABLED', '1')
        path.write_bytes(go_binary('rqlited', [('fixture/dependency', 'v9.0.0')], settings))
        with self.assertRaisesRegex(INV.InventoryError, 'module graph differs'):
            self.generate('rqlite')
        settings[1] = ('vcs.modified', 'true')
        path.write_bytes(go_binary('rqlited', [('fixture/dependency', 'v2.0.0')], settings))
        with self.assertRaisesRegex(INV.InventoryError, 'VCS/toolchain differs'):
            self.generate('rqlite')

    def test_missing_native_resolution_cannot_use_parent_module_license(self):
        self.components = [c for c in self.components if c['id'] != 'native:sqlite']
        self.save_resolutions()
        with self.assertRaisesRegex(INV.InventoryError, 'missing incorporated.*fixture/dependency.*sqlite'):
            self.generate('rqlite')

    def test_unreviewed_extra_executable_fails(self):
        (self.root / 'usr/local/bin/unknown-tool').write_bytes(b'unknown')
        with self.assertRaisesRegex(INV.InventoryError, 'unreviewed packaged executable.*unknown-tool'):
            self.generate()

    def test_unresolved_debian_license_declarations_and_resolutions_fail(self):
        copyright_path = self.root / 'usr/share/doc/fixture-lib/copyright'
        original = copyright_path.read_text()
        self.generate()
        for license_name in ('Unknown', 'NOASSERTION'):
            with self.subTest(declaration=license_name):
                copyright_path.write_text(original.replace('License: MIT', 'License: ' + license_name))
                with self.assertRaisesRegex(INV.InventoryError, 'fixture-lib.*unresolved Debian'):
                    self.generate()
                with self.assertRaisesRegex(INV.InventoryError, 'fixture-lib.*unresolved Debian'):
                    INV.verify(self.root, self.bundle / 'inventory.json')
        copyright_path.write_text(original)
        (self.resolutions / 'debian').mkdir()
        for license_name in ('', 'Unknown', 'NOASSERTION'):
            with self.subTest(resolution=license_name):
                (self.resolutions / 'debian/components.json').write_text(INV.canonical([
                    {'copyright_sha256': INV.digest(copyright_path.read_bytes()), 'license': license_name}]))
                with self.assertRaisesRegex(INV.InventoryError, 'fixture-lib.*unresolved Debian'):
                    self.generate()

    def test_unreviewed_static_archives_fail_generation_and_verification(self):
        evidence_path = self.bundle / 'api-build.json'
        original = INV.read_json(evidence_path)
        self.generate('api')
        for archive in ('/opt/unreviewed/libextra.a', 'libextra.a'):
            with self.subTest(archive=archive):
                evidence = INV.read_json(evidence_path)
                evidence['static_libraries'].append({'path': archive, 'sha256': 'f' * 64})
                evidence['link_commands'] += ' ' + archive
                evidence_path.write_text(INV.canonical(evidence))
                with self.assertRaisesRegex(INV.InventoryError, 'unreviewed API static archive.*libextra.a'):
                    self.generate('api')
                with self.assertRaisesRegex(INV.InventoryError, 'unreviewed API static archive.*libextra.a'):
                    INV.verify(self.root, self.bundle / 'inventory.json')
                evidence_path.write_text(INV.canonical(original))
