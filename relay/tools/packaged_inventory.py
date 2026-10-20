#!/usr/bin/env python3
# SPDX-License-Identifier: Apache-2.0
"""Observe packaged Registry artifacts and check their retained license evidence."""
import argparse
import hashlib
import json
import pathlib
import re
import shutil
import shlex
import subprocess
import sys

BUNDLE_PATH = '/usr/share/registry-inventory'
EXECUTABLES = {'api': ['baander-registry'], 'rqlite': ['rqlited'], 'backup': ['age', 'age-keygen', 'rclone']}


class InventoryError(ValueError):
    pass


def digest(data):
    return hashlib.sha256(data).hexdigest()


def canonical(value):
    return json.dumps(value, sort_keys=True, indent=2) + '\n'


def root_file(root, name):
    """Resolve absolute and relative image symlinks without following host paths."""
    pending = list(pathlib.PurePosixPath('/' + str(name).lstrip('/')).parts[1:])
    parts = []
    links = 0
    while pending:
        part = pending.pop(0)
        if part in ('', '.'):
            continue
        if part == '..':
            if not parts:
                raise InventoryError('path escapes exported filesystem: ' + str(name))
            parts.pop()
            continue
        candidate = root.joinpath(*parts, part)
        if candidate.is_symlink():
            links += 1
            if links > 40:
                raise InventoryError('copyright symlink loop: ' + str(name))
            target = pathlib.PurePosixPath(candidate.readlink())
            if target.is_absolute():
                parts = []
            pending = list(target.parts[1:] if target.is_absolute() else target.parts) + pending
        else:
            parts.append(part)
    result = root.joinpath(*parts)
    if not result.is_file():
        raise InventoryError('missing retained file: ' + str(name))
    return result


def go_buildinfo(data, name):
    magic = b'\xff Go buildinf:'
    offset = data.find(magic)
    if offset < 0 or len(data) < offset + 32 or not data[offset + 15] & 2:
        raise InventoryError(name + ': missing usable Go build information')
    position = offset + 32

    def read_string():
        nonlocal position
        length = 0
        shift = 0
        while True:
            if position >= len(data) or shift > 63:
                raise InventoryError(name + ': invalid Go build information length')
            byte = data[position]
            position += 1
            length |= (byte & 127) << shift
            shift += 7
            if byte < 128:
                break
        end = position + length
        if end > len(data):
            raise InventoryError(name + ': truncated Go build information')
        result = data[position:end]
        position = end
        return result

    version = read_string().decode('utf-8')
    raw = read_string()
    if not version.startswith('go1.') or len(raw) < 32:
        raise InventoryError(name + ': invalid Go build information')
    modules = []
    settings = {}
    package = None
    for line in raw[16:-16].decode('utf-8').splitlines():
        fields = line.split('\t')
        if fields[0] == 'path' and len(fields) == 2:
            package = fields[1]
        elif fields[0] in ('mod', 'dep', '=>'):
            if len(fields) < 3:
                raise InventoryError(name + ': incomplete Go module identity: ' + line)
            entry = dict(zip(('path', 'version', 'sum'), fields[1:]))
            entry.setdefault('sum', '')
            if fields[0] == '=>':
                if not modules or 'replacement' in modules[-1]:
                    raise InventoryError(name + ': unmatched Go module replacement')
                modules[-1]['replacement'] = entry
            else:
                entry['main'] = fields[0] == 'mod'
                modules.append(entry)
        elif fields[0] == 'build' and len(fields) == 2 and '=' in fields[1]:
            key, value = fields[1].split('=', 1)
            settings[key] = value
    if not package or not modules:
        raise InventoryError(name + ': incomplete Go build information')
    return {'go_version': version, 'package': package,
            'modules': sorted(modules, key=lambda m: m['path']), 'settings': settings}


def debian_status(text):
    records = []
    for paragraph in text.split('\n\n'):
        fields = {}
        key = None
        for line in paragraph.splitlines():
            if line.startswith((' ', '\t')) and key:
                fields[key] += '\n' + line
            elif ': ' in line:
                key, value = line.split(': ', 1)
                fields[key] = value
        if fields.get('Status') == 'install ok installed':
            for key in ('Package', 'Version', 'Architecture'):
                if key not in fields:
                    raise InventoryError('incomplete Debian package: ' + str(fields))
            records.append(fields)
    if not records:
        raise InventoryError('no installed Debian packages observed')
    return sorted(records, key=lambda p: (p['Package'], p['Architecture']))


def read_json(path):
    return json.loads(path.read_text())


def unresolved_license(value):
    return not isinstance(value, str) or value.strip().casefold() in ('', 'unknown', 'noassertion')


def resolution_data(directory):
    """Reviewed component files are deliberately separate from observations."""
    components = []
    for path in sorted([*directory.glob('*/components.json'), *directory.glob('*/embedded.json')]):
        if path.parent.name == 'debian':
            continue
        value = read_json(path)
        components.extend(value if isinstance(value, list) else value['components'])
    ids = [c['id'] for c in components]
    if len(ids) != len(set(ids)):
        raise InventoryError('duplicate reviewed component identity')
    for component in components:
        missing_children = set(component.get('incorporated_components', [])) - set(ids)
        if missing_children:
            raise InventoryError('missing incorporated component evidence for ' + component['id'] + ': ' + ', '.join(sorted(missing_children)))
        if unresolved_license(component.get('license')):
            raise InventoryError('unresolved license: ' + component['id'])
        if not component.get('notices'):
            raise InventoryError('missing notices: ' + component['id'])
        for notice in component['notices']:
            path = root_file(directory, notice['path'])
            if digest(path.read_bytes()) != notice['sha256']:
                raise InventoryError('reviewed notice digest changed: ' + component['id'] + ': ' + notice['path'])
    return components


def package_observations(root, debian_resolutions):
    packages = []
    notice_paths = set()
    for fields in debian_status(root_file(root, '/var/lib/dpkg/status').read_text()):
        name = fields['Package']
        copyright_path = '/usr/share/doc/' + name + '/copyright'
        content = root_file(root, copyright_path).read_bytes()
        sha = digest(content)
        text = content.decode('utf-8', errors='replace')
        declarations = sorted(set(re.findall(r'^License:[ \t]*(\S[^\n]*)', text, re.M)))
        resolution = next((r for r in debian_resolutions if r['copyright_sha256'] == sha), None)
        if resolution:
            license_name = resolution['license']
        elif declarations and 'Format:' in text:
            license_name = 'LicenseRef-Debian-' + re.sub('[^A-Za-z0-9.-]', '-', name) + '-' + sha[:12]
        else:
            raise InventoryError(name + ': unresolved Debian copyright license ' + sha)
        if unresolved_license(license_name) or any(unresolved_license(value) for value in declarations):
            raise InventoryError(name + ': unresolved Debian copyright license ' + sha)
        notices = [copyright_path]
        # Debian documents routinely refer to common license texts by absolute path.
        for relative in re.findall(r'/usr/share/common-licenses/([A-Za-z0-9.+_-]+)', text):
            relative = relative.rstrip('.')
            if resolution:
                relative = resolution.get('common_license_aliases', {}).get(relative, relative)
            path = '/usr/share/common-licenses/' + relative
            root_file(root, path)
            notices.append(path)
        notices = sorted(set(notices))
        notice_paths.update(notices)
        packages.append({'name': name, 'version': fields['Version'], 'architecture': fields['Architecture'],
                         'source': fields.get('Source', name), 'license': license_name,
                         'copyright_sha256': sha, 'notices': notices,
                         'license_declarations': declarations,
                         'license_scope': 'Debian source-package copyright declarations; not per-file legal conclusion'})
    return packages, notice_paths


def component_ids(info, components):
    selected = {'go-toolchain:' + info['go_version']}
    for module in info['modules']:
        effective = module.get('replacement', module)
        selected.add('go:' + effective['path'] + '@' + effective['version'])
    for c in components:
        if c['id'] in selected and c.get('unresolved'):
            raise InventoryError('unresolved source evidence: ' + c['id'] + ': ' + str(c['unresolved']))
    all_ids = {c['id'] for c in components}
    missing = selected - all_ids
    if missing:
        raise InventoryError('unresolved executable components: ' + ', '.join(sorted(missing)))
    # Incorporated native/vendored code remains distinct from its module license.
    for c in components:
        if c.get('parent') in selected:
            selected.add(c['id'])
        if c['id'] in selected:
            selected.update(c.get('incorporated_components', []))
    return sorted(selected)


def api_static_archives(commands):
    return {token for token in shlex.split(commands) if token.endswith('.a')}


def validate_api_sources(evidence, source_files):
    observed = set()
    for item in evidence['inputs']:
        for marker, prefix in (('_deps/boost_headers-src/', 'boost/'), ('_deps/json-src/', 'json/')):
            if marker in item['path']:
                source = prefix + item['path'].split(marker, 1)[1]
                if source_files.get(source) != item['sha256']:
                    raise InventoryError('API compiled source differs from pinned archive: ' + source)
                observed.add(prefix)
    if observed != {'boost/', 'json/'}:
        raise InventoryError('API compiler evidence lacks pinned Boost/json source observations')
    command_archives = api_static_archives(evidence.get('link_commands', ''))
    recorded_archives = {item['path'] for item in evidence.get('static_libraries', [])}
    reviewed_archives = {'/opt/openssl/lib/libssl.a', '/opt/openssl/lib/libcrypto.a',
                         'libregistry_core.a', 'libregistry_transport.a', 'libregistry_boost_url.a'}
    unknown = (command_archives | recorded_archives) - reviewed_archives
    if unknown:
        raise InventoryError('unreviewed API static archive: ' + ', '.join(sorted(unknown)))
    if command_archives != recorded_archives:
        raise InventoryError('API static archive observations differ from linker commands')
    static_names = {pathlib.PurePosixPath(path).name for path in recorded_archives}
    if (not {'libssl.a', 'libcrypto.a'} <= static_names
            or not evidence.get('linker_map')
            or not all(x in evidence.get('link_commands', '') for x in ('libssl.a', 'libcrypto.a'))):
        raise InventoryError('API evidence lacks static OpenSSL linker inputs/map')


def module_identities(modules):
    result = []
    for module in modules:
        item = {key: module.get(key, '') for key in ('path', 'version', 'sum')}
        if 'replacement' in module:
            item['replacement'] = {key: module['replacement'].get(key, '') for key in ('path', 'version', 'sum')}
        result.append(item)
    return sorted(result, key=lambda item: item['path'])


def validate_source_build(name, info, expected):
    settings = info['settings']
    if (settings.get('vcs.revision') != expected['expected_vcs_revision']
            or settings.get('vcs.modified') != 'false'
            or info['go_version'] != expected['go_version']):
        raise InventoryError(name + ': source-built executable VCS/toolchain differs from reviewed source')
    runtime_keys = ('CGO_ENABLED', 'GOOS', 'GOARCH', 'GOAMD64', '-tags', '-buildmode', '-ldflags')
    reviewed_settings = expected['settings']
    if (info['package'] != expected['package']
            or any(settings.get(key, '') != reviewed_settings.get(key, '') for key in runtime_keys)):
        raise InventoryError(name + ': source-built package/runtime build settings differ from reviewed source')
    if module_identities(info['modules']) != module_identities(expected['modules']):
        raise InventoryError(name + ': source-built module graph differs from reviewed source')


def observe(root, kind, components, debian_resolutions, reviewed_executables, source_files=None):
    executables = []
    selected = set()
    allowed_scripts = {'api': set(), 'rqlite': {'rqlite-entrypoint'}, 'backup': {'registry-backup'}}
    unknown = {p.name for p in (root / 'usr/local/bin').iterdir() if p.is_file()} - set(EXECUTABLES[kind]) - allowed_scripts[kind]
    if unknown:
        raise InventoryError('unreviewed packaged executable: ' + ', '.join(sorted(unknown)))
    for name in EXECUTABLES[kind]:
        path = '/usr/local/bin/' + name
        data = root_file(root, path).read_bytes()
        record = {'path': path, 'sha256': digest(data)}
        if kind != 'api':
            info = go_buildinfo(data, name)
            record.update(info)
            expected = next((e for e in reviewed_executables if e['path'] == path), None)
            if not expected:
                raise InventoryError(name + ': missing reviewed executable observation')
            if expected.get('source_built'):
                validate_source_build(name, info, expected)
            elif expected['sha256'] != record['sha256']:
                raise InventoryError(name + ': executable differs from reviewed artifact')
            record['components'] = component_ids(info, components)
        else:
            evidence_path = '/usr/share/registry-inventory/api-build.json'
            evidence = read_json(root_file(root, evidence_path))
            if evidence['sha256'] != record['sha256']:
                raise InventoryError(name + ': API compiler/link evidence differs from binary')
            validate_api_sources(evidence, source_files or {})
            record['build_evidence'] = evidence
            record['components'] = sorted(c['id'] for c in components
                                          if c.get('family') == 'api' and c.get('scope') == 'runtime')
            if not record['components']:
                raise InventoryError('API source resolutions missing')
        selected.update(record['components'])
        executables.append(record)
    packages, notices = package_observations(root, debian_resolutions)
    return {'schema': 'baander-packaged-inventory/v1', 'kind': kind,
            'executables': executables, 'packages': packages,
            'components': sorted((c for c in components if c['id'] in selected), key=lambda c: c['id'])}, notices


def load_inputs(directory):
    components = resolution_data(directory)
    debian_path = directory / 'debian/components.json'
    debian = read_json(debian_path) if debian_path.exists() else []
    if isinstance(debian, dict):
        debian = debian['components']
    go_path = directory / 'go/executables.json'
    executables = read_json(go_path) if go_path.exists() else []
    if isinstance(executables, dict):
        executables = executables.get('executables', list(executables.values()))
    return components, debian, executables


def generate(root, kind, resolutions, output):
    components, debian, reviewed = load_inputs(resolutions)
    source_path = resolutions / 'native/source-files.json'
    source_files = read_json(source_path) if source_path.exists() else {}
    inventory, package_notices = observe(root, kind, components, debian, reviewed, source_files)
    output.mkdir(parents=True, exist_ok=True)
    retained = output / 'resolutions'
    shutil.copytree(resolutions, retained, dirs_exist_ok=True)
    notices = []
    for path in sorted(package_notices):
        data = root_file(root, path).read_bytes()
        target = output / 'debian-notices' / path.lstrip('/')
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_bytes(data)
        notices.append({'path': path, 'sha256': digest(data),
                        'retained': BUNDLE_PATH + '/debian-notices/' + path.lstrip('/')})
    inventory['debian_notices'] = notices
    (output / 'inventory.json').write_text(canonical(inventory))
    return inventory


def tree_manifest(directory):
    return {str(p.relative_to(directory)): digest(p.read_bytes())
            for p in sorted(directory.rglob('*')) if p.is_file()}


def verify(root, inventory_path, trusted_resolutions=None):
    inventory = read_json(inventory_path)
    bundle = inventory_path.parent
    if trusted_resolutions is not None and tree_manifest(bundle / 'resolutions') != tree_manifest(trusted_resolutions):
        raise InventoryError('retained resolutions differ from independently reviewed source evidence')
    components, debian, reviewed = load_inputs(bundle / 'resolutions')
    source_path = bundle / 'resolutions/native/source-files.json'
    source_files = read_json(source_path) if source_path.exists() else {}
    actual, notice_paths = observe(root, inventory['kind'], components, debian, reviewed, source_files)
    actual['debian_notices'] = [{'path': path, 'sha256': digest(root_file(root, path).read_bytes()),
                                 'retained': BUNDLE_PATH + '/debian-notices/' + path.lstrip('/')}
                                for path in sorted(notice_paths)]
    if {k: inventory[k] for k in actual} != actual:
        raise InventoryError('packaged inventory differs from final filesystem: executable, component or Debian package observation')
    for notice in inventory['debian_notices']:
        current = root_file(root, notice['path']).read_bytes()
        retained = root_file(root, notice['retained']).read_bytes()
        if digest(current) != notice['sha256'] or current != retained:
            raise InventoryError('Debian retained notice changed: ' + notice['path'])
    return inventory


def capture_api(build, output):
    commands = subprocess.check_output(['ninja', '-C', str(build), '-t', 'commands', 'baander-registry'], text=True)
    deps = subprocess.check_output(['ninja', '-C', str(build), '-t', 'deps'], text=True)
    compile_commands = read_json(build / 'compile_commands.json')
    # Record every observed include and source, not just top-level package declarations.
    paths = set()
    for line in deps.splitlines():
        if line.startswith('    '):
            path = pathlib.Path(line.strip())
            paths.add(path if path.is_absolute() else build / path)
    for command in compile_commands:
        paths.add(pathlib.Path(command['file']))
    if not paths or not any('_deps/boost_headers-src/boost/' in str(p) for p in paths) or not any('_deps/json-src/' in str(p) for p in paths):
        raise InventoryError('API compiler evidence lacks observed Boost/json headers')
    if 'libssl.a' not in commands or 'libcrypto.a' not in commands:
        raise InventoryError('API linker evidence lacks static OpenSSL archives')
    libraries = sorted(api_static_archives(commands))
    evidence = {'sha256': digest((build / 'baander-registry').read_bytes()),
                'static_libraries': [{'path': path, 'sha256': digest((build / path).read_bytes())} for path in libraries],
                'compile_commands': compile_commands, 'link_commands': commands,
                'inputs': [{'path': str(p), 'sha256': digest(p.read_bytes())} for p in sorted(paths)],
                'linker_map': (build / 'baander-registry.map').read_text()}
    output.parent.mkdir(parents=True, exist_ok=True)
    output.write_text(canonical(evidence))


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    sub = parser.add_subparsers(dest='command', required=True)
    gen = sub.add_parser('generate')
    gen.add_argument('--kind', choices=EXECUTABLES, required=True)
    gen.add_argument('--root', type=pathlib.Path, required=True)
    gen.add_argument('--resolutions', type=pathlib.Path, required=True)
    gen.add_argument('--output', type=pathlib.Path, required=True)
    check = sub.add_parser('verify')
    check.add_argument('--root', type=pathlib.Path, required=True)
    check.add_argument('--inventory', type=pathlib.Path, required=True)
    check.add_argument('--resolutions', type=pathlib.Path, default=pathlib.Path(__file__).resolve().parents[1] / 'third_party/inventory')
    cap = sub.add_parser('capture-api')
    cap.add_argument('--build', type=pathlib.Path, required=True)
    cap.add_argument('--output', type=pathlib.Path, required=True)
    offline = sub.add_parser('check-resolutions')
    offline.add_argument('--resolutions', type=pathlib.Path, required=True)
    args = parser.parse_args()
    try:
        if args.command == 'generate':
            result = generate(args.root, args.kind, args.resolutions, args.output)
        elif args.command == 'verify':
            result = verify(args.root, args.inventory, args.resolutions)
        elif args.command == 'capture-api':
            capture_api(args.build, args.output)
            return
        else:
            result = {'components': resolution_data(args.resolutions)}
        print(canonical({'status': 'passed', 'components': len(result['components']),
                         'packages': len(result.get('packages', []))}), end='')
    except (InventoryError, OSError, KeyError, ValueError) as error:
        parser.exit(1, 'Inventory qualification failed: ' + str(error) + '\n')


if __name__ == '__main__':
    main()
