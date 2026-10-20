#!/usr/bin/env python3
# SPDX-License-Identifier: Apache-2.0
"""Collect application image membership and notices without asserting license clearance."""
import argparse
import hashlib
import json
import os
import pathlib
import re
import shutil
import stat
import sys
import tarfile

NATIVE = '/usr/share/baander-build-evidence'
APP = '/var/www/html'
SCHEMA = 'baander-application-packaged-inventory/v1'


class InventoryError(ValueError):
    pass


class MissingImageFileError(InventoryError):
    pass


def canonical(value):
    return json.dumps(value, sort_keys=True, separators=(',', ':')) + '\n'


def file_hash(path):
    with path.open('rb') as stream:
        return hashlib.file_digest(stream, 'sha256').hexdigest()


def root_path(root, name):
    """Resolve image symlinks inside the export, including absolute Debian aliases."""
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
                raise InventoryError('image symlink loop: ' + str(name))
            target = pathlib.PurePosixPath(candidate.readlink())
            if target.is_absolute():
                parts = []
            pending = list(target.parts[1:] if target.is_absolute() else target.parts) + pending
        else:
            parts.append(part)
    return root.joinpath(*parts)


def root_file(root, name):
    result = root_path(root, name)
    if not result.is_file():
        raise MissingImageFileError('missing image file: ' + str(name))
    return result


def read_json(path):
    return json.loads(path.read_text())


def image_identity(inspect, target):
    value = inspect[0] if isinstance(inspect, list) and len(inspect) == 1 else inspect
    platform = value['Os'] + '/' + value['Architecture']
    if value.get('Variant'):
        platform += '/' + value['Variant']
    return {'image_id': value['Id'], 'target': target, 'platform': platform,
            'revision': (value.get('Config', {}).get('Labels') or {}).get('org.opencontainers.image.revision', '')}


def check_identity(identity, revision):
    if not all(identity.get(k) for k in ('image_id', 'target', 'platform', 'revision')):
        raise InventoryError('image identity, platform, target and observed revision are required')
    if identity['target'] not in ('production', 'worker'):
        raise InventoryError('unsupported application target')
    if identity['revision'] != revision:
        raise InventoryError('observed image revision differs from expected revision')


def debian_status(text):
    packages = []
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
            if not all(fields.get(k) for k in ('Package', 'Version', 'Architecture')):
                raise InventoryError('incomplete installed Debian package metadata')
            packages.append(fields)
    if not packages:
        raise InventoryError('no installed Debian packages observed')
    return sorted(packages, key=lambda p: (p['Package'], p['Architecture']))


def known(value):
    return value is not None and str(value).strip().casefold() not in ('', 'unknown', 'noassertion', 'n/a')


def notice_name(name):
    return bool(re.match(r'^(license|licence|copying|copyright|notice|authors)([._-].*)?$', name, re.I))


def file_kind(path, local):
    with local.open('rb') as stream:
        magic = stream.read(8)
    name = pathlib.PurePosixPath(path).name
    if magic.startswith(b'\x7fELF'):
        return 'native'
    if magic.startswith(b'\0asm') or name.endswith('.wasm'):
        return 'wasm'
    if magic.startswith((b'!<arch>\n', b'PK\x03\x04', b'\x1f\x8b', b'\xfd7zXZ\0')) or name.endswith(('.tar', '.phar', '.zip', '.a', '.deb', '.7z', '.bz2')):
        return 'archive'
    if name in ('package.json', 'composer.json', 'composer.lock', 'package-lock.json', 'pnpm-lock.yaml', 'yarn.lock', 'installed.json'):
        return 'manifest'
    if notice_name(name):
        return 'notice'
    if path.startswith(('/usr/local/bin/', '/usr/local/sbin/', '/opt/')) or magic.startswith(b'#!'):
        return 'tool-or-script'
    return 'file'


def observe(root, identity):
    gaps = []
    evidence_paths = set()

    def gap(path, reason, component=None):
        value = {'path': path, 'reason': reason}
        if component:
            value['component'] = component
        gaps.append(value)

    def notice(path, component):
        try:
            root_file(root, path)
        except MissingImageFileError:
            gap(path, 'missing license or copyright evidence', component)
            return
        evidence_paths.add(path)

    status_path = '/var/lib/dpkg/status'
    packages = []
    owners = {}
    evidence_paths.add(status_path)
    for package in debian_status(root_file(root, status_path).read_text()):
        name = package['Package']
        architecture = package['Architecture']
        component = 'deb:' + name + ':' + architecture
        record = {'id': component, 'name': name, 'version': package['Version'], 'architecture': architecture,
                  'source': package.get('Source', name), 'declared_license': None}
        packages.append(record)
        list_path = '/var/lib/dpkg/info/' + name + ':' + architecture + '.list'
        if not (root / list_path.lstrip('/')).is_file():
            list_path = '/var/lib/dpkg/info/' + name + '.list'
        try:
            entries = root_file(root, list_path).read_text().splitlines()
            evidence_paths.add(list_path)
        except MissingImageFileError:
            entries = []
            gap(list_path, 'installed package has no ownership list', component)
        for path in entries:
            owners.setdefault(path, []).append(component)
        copyright_path = '/usr/share/doc/' + name + '/copyright'
        notice(copyright_path, component)
        if copyright_path in evidence_paths:
            text = root_file(root, copyright_path).read_text(errors='replace')
            licenses = sorted(set(re.findall(r'^License:\s*(.+)', text, re.M)))
            record['declared_license'] = licenses or None
            if not licenses:
                gap(copyright_path, 'Debian copyright has no machine-readable license declaration', component)
            for common in sorted(set(re.findall(r'/usr/share/common-licenses/[A-Za-z0-9_.+\-]+', text))):
                notice(common.rstrip('.'), component)

    installed_path = APP + '/vendor/composer/installed.json'
    installed = read_json(root_file(root, installed_path))
    evidence_paths.add(installed_path)
    if isinstance(installed, dict):
        installed = installed.get('packages')
    if not isinstance(installed, list):
        raise InventoryError('unusable installed Composer package metadata')
    composer = []
    composer_roots = []
    for package in installed:
        name = package['name']
        package_type = package.get('type')
        full = None
        component = 'composer:' + name
        if package_type != 'metapackage':
            install_path = package.get('install-path')
            if not isinstance(install_path, str) or not install_path:
                raise InventoryError('missing Composer installation path: ' + name)
            full = os.path.normpath(APP + '/vendor/composer/' + install_path)
            if not full.startswith(APP + '/'):
                raise InventoryError('Composer installation path escapes application: ' + name)
            composer_roots.append((full, component))
        record = {'id': component, 'name': name, 'type': package_type, 'version': package.get('version'),
                  'source': package.get('source'), 'dist': package.get('dist'),
                  'declared_license': package.get('license'), 'install_path': full}
        composer.append(record)
        if not record['declared_license'] or not all(known(value) for value in record['declared_license']):
            gap(installed_path, 'missing declared Composer license', component)
        if package_type == 'metapackage':
            continue
        found = []
        directory = root_path(root, full)
        if directory.is_dir():
            physical_prefix = '/' + str(directory.relative_to(root))
            if physical_prefix != full:
                composer_roots.append((physical_prefix, component))
            found = ['/' + str(p.relative_to(root)) for p in directory.iterdir() if notice_name(p.name) and (p.is_file() or p.is_symlink())]
        if not found:
            gap(full, 'installed Composer package has no retained top-level notice', component)
        for path in found:
            notice(path, component)

    composer_roots.sort(key=lambda p: len(p[0]), reverse=True)
    native = []
    native_owners = {}
    native_path = NATIVE + '/components.json'
    if (root / native_path.lstrip('/')).is_file():
        data = read_json(root_file(root, native_path))
        if data.get('schema') != 'baander-native-build-evidence/v1' or not isinstance(data.get('components'), list):
            raise InventoryError('unusable native build evidence')
        evidence_paths.add(native_path)
        native = data['components']
        for component in native:
            key = component['id']
            source = component.get('source') or {}
            if not known(component.get('version')) or not any(known(source.get(k)) for k in ('url', 'revision', 'sha256')):
                gap(native_path, 'native source identity or version incomplete', key)
            artifacts = component.get('artifacts', [])
            if not artifacts:
                gap(native_path, 'native component has no bound final output', key)
            for artifact in artifacts:
                path = artifact['path']
                if not path.startswith('/') or file_hash(root_file(root, path)) != artifact['sha256']:
                    raise InventoryError('native output differs from build evidence: ' + path)
                native_owners.setdefault(path, []).append(key)
            if not component.get('notices'):
                gap(native_path, 'native component has no upstream notices', key)
            for path in component.get('notices', []):
                notice(path, key)
            metadata = component.get('metadata') or {}
            if metadata.get('own_notices_gap'):
                gap(native_path, metadata['own_notices_gap'], key)
            for pattern in metadata.get('unmatched_artifact_patterns', []):
                gap(pattern, 'native build artifact pattern matched no final files', key)
            if component.get('embedded_dependencies') != 'reviewed':
                gap(native_path, 'embedded native dependency coverage requires review', key)
    else:
        gap(native_path, 'native build provenance is absent')

    files = []
    copied_manifests = []
    for directory, dirs, names in os.walk(root, followlinks=False):
        dirs.sort()
        # Symlinked directories are filesystem members too; do not traverse them.
        for name in list(dirs):
            if (pathlib.Path(directory) / name).is_symlink():
                names.append(name)
        for name in sorted(names):
            local = pathlib.Path(directory) / name
            path = '/' + str(local.relative_to(root))
            mode = local.lstat().st_mode
            entry = {'path': path}
            if stat.S_ISLNK(mode):
                entry.update(type='symlink', target=str(local.readlink()))
                try:
                    resolved = root_file(root, path)
                except MissingImageFileError:
                    resolved = None
                if resolved:
                    entry.update(sha256=file_hash(resolved), size=resolved.stat().st_size, kind=file_kind(path, resolved))
                else:
                    entry['kind'] = 'symlink'
            elif stat.S_ISREG(mode):
                entry.update(type='file', sha256=file_hash(local), size=local.stat().st_size, kind=file_kind(path, local))
            else:
                entry.update(type='special', kind='special')
            group = 'unresolved'
            assigned = owners.get(path, [])
            if assigned:
                group = 'debian'
            elif path == NATIVE or path.startswith(NATIVE + '/'):
                group = 'build-evidence'
            else:
                for prefix, component in composer_roots:
                    if path == prefix or path.startswith(prefix + '/'):
                        group, assigned = 'installed-composer', [component]
                        break
                if group == 'unresolved' and path.startswith(APP + '/'):
                    group = 'copied-repository'
                if path in native_owners:
                    group, assigned = 'native-build', native_owners[path]
            entry.update(group=group, owners=sorted(assigned))
            files.append(entry)
            kind = entry['kind']
            if group == 'build-evidence' and entry.get('sha256'):
                evidence_paths.add(path)
            if kind == 'notice':
                evidence_paths.add(path)
            if path.startswith(APP + '/third_party/inventory/') and name == 'source.json' and entry.get('sha256'):
                evidence_paths.add(path)
            if group in ('unresolved', 'copied-repository') and kind in ('native', 'wasm', 'archive', 'tool-or-script'):
                gap(path, 'unresolved ownership or embedded dependency evidence for ' + kind)
            if group == 'copied-repository' and name in ('composer.json', 'package.json') and entry.get('sha256'):
                try:
                    manifest = read_json(root_file(root, path))
                    if not isinstance(manifest, dict):
                        raise ValueError('manifest must contain an object')
                    copied_manifests.append({'path': path, 'name': manifest.get('name'),
                                             'version': manifest.get('version'), 'declared_license': manifest.get('license')})
                    evidence_paths.add(path)
                except (ValueError, UnicodeError):
                    gap(path, 'copied package manifest is not usable JSON metadata')
            if group == 'copied-repository' and kind == 'manifest':
                gap(path, 'copied manifest describes source requirements; installed or generated contents require review')
            if group == 'unresolved' and kind == 'file':
                gap(path, 'unowned filesystem material requires classification')
    # Merged-/usr symlinks can give Debian and native artifact owners alias paths.
    # Preserve existing assignments and Debian precedence when resolving unowned files.
    by_resolved = {'debian': {}, 'native-build': {}}
    for group, ownership in (('debian', owners), ('native-build', native_owners)):
        for path, assigned in ownership.items():
            try:
                resolved = root_file(root, path)
            except MissingImageFileError:
                continue
            by_resolved[group].setdefault(str(resolved), set()).update(assigned)
    resolved_aliases = set()
    for entry in files:
        if entry['group'] == 'unresolved' and entry.get('sha256'):
            resolved = str(root_file(root, entry['path']))
            for group in ('debian', 'native-build'):
                assigned = by_resolved[group].get(resolved)
                if assigned:
                    entry.update(group=group, owners=sorted(assigned))
                    resolved_aliases.add(entry['path'])
                    break
    gaps = [g for g in gaps if not (g['path'] in resolved_aliases and
            (g['reason'].startswith('unresolved ownership or embedded dependency evidence for ') or
             g['reason'] == 'unowned filesystem material requires classification'))]
    return {'schema': SCHEMA, 'identity': identity, 'debian': packages,
            'composer': sorted(composer, key=lambda p: p['name']), 'native': native,
            'files': sorted(files, key=lambda p: p['path']),
            'copied_manifests': sorted(copied_manifests, key=lambda p: p['path']),
            'review_gaps': sorted(gaps, key=lambda g: (g['path'], g.get('component', ''), g['reason'])),
            'redistribution_review': 'not-qualified'}, evidence_paths


def evidence_records(root, paths):
    result = []
    for path in sorted(paths):
        local = root_file(root, path)
        sha = file_hash(local)
        result.append({'path': path, 'sha256': sha, 'retained': 'evidence/sha256/' + sha})
    return result


def summary(inventory):
    lines = ['Application packaged inventory', '',
             'Image: ' + inventory['identity']['image_id'],
             'Target: ' + inventory['identity']['target'],
             'Platform: ' + inventory['identity']['platform'],
             'Observed revision: ' + inventory['identity']['revision'], '',
             'Observed files: ' + str(len(inventory['files'])),
             'Installed Debian packages: ' + str(len(inventory['debian'])),
             'Installed Composer packages: ' + str(len(inventory['composer'])),
             'Native build components: ' + str(len(inventory['native'])),
             'Retained evidence paths: ' + str(len(inventory['evidence'])), '',
             'Redistribution qualification remains open. Integrity verification does not approve licensing.',
             'A filesystem census cannot establish all dependencies embedded in compiled or generated bytes.',
             'JavaScript manifests and lockfiles establish copied source requirements, not installed dependencies.', '',
             'Review gaps: ' + str(len(inventory['review_gaps']))]
    for gap in inventory['review_gaps']:
        lines.append('- ' + gap['path'] + ': ' + gap['reason'] + (' [' + gap['component'] + ']' if gap.get('component') else ''))
    return '\n'.join(lines) + '\n'


def generate(root, identity, output, revision):
    check_identity(identity, revision)
    if output.resolve().is_relative_to(root.resolve()):
        raise InventoryError('bundle output must be outside exported filesystem')
    inventory, paths = observe(root, identity)
    inventory['evidence'] = evidence_records(root, paths)
    output.mkdir(parents=True, exist_ok=True)
    copied = set()
    for evidence in inventory['evidence']:
        if evidence['retained'] in copied:
            continue
        destination = output / evidence['retained']
        destination.parent.mkdir(parents=True, exist_ok=True)
        shutil.copyfile(root_file(root, evidence['path']), destination)
        copied.add(evidence['retained'])
    (output / 'inventory.json').write_text(canonical(inventory))
    (output / 'SUMMARY.txt').write_text(summary(inventory))
    checksums = {'inventory.json': file_hash(output / 'inventory.json'),
                 'SUMMARY.txt': file_hash(output / 'SUMMARY.txt')}
    checksums.update({e['retained']: e['sha256'] for e in inventory['evidence']})
    (output / 'checksums.json').write_text(canonical(checksums))
    return inventory


def verify(root, inventory_path, revision, identity=None):
    inventory = read_json(inventory_path)
    if inventory.get('schema') != SCHEMA:
        raise InventoryError('unsupported inventory schema')
    check_identity(inventory['identity'], revision)
    if identity is not None and identity != inventory['identity']:
        raise InventoryError('observed image identity differs from inventory')
    actual, paths = observe(root, inventory['identity'])
    actual['evidence'] = evidence_records(root, paths)
    if inventory != actual:
        old = {f['path']: f for f in inventory.get('files', [])}
        new = {f['path']: f for f in actual['files']}
        changed = sorted(p for p in old.keys() | new.keys() if old.get(p) != new.get(p))
        raise InventoryError('inventory differs from exported filesystem: ' + ', '.join(changed[:20] or ['package metadata or review evidence']))
    bundle = inventory_path.parent
    expected = {'inventory.json': file_hash(inventory_path), 'SUMMARY.txt': hashlib.sha256(summary(inventory).encode()).hexdigest()}
    retained_hashes = {}
    for evidence in inventory['evidence']:
        name = evidence['retained']
        if name not in retained_hashes:
            retained_hashes[name] = file_hash(root_file(bundle, name))
        if retained_hashes[name] != evidence['sha256']:
            raise InventoryError('retained evidence changed: ' + evidence['path'])
        expected[evidence['retained']] = evidence['sha256']
    if read_json(bundle / 'checksums.json') != expected:
        raise InventoryError('bundle checksums differ from inventory evidence')
    if file_hash(root_file(bundle, 'SUMMARY.txt')) != expected['SUMMARY.txt']:
        raise InventoryError('readable summary changed')
    return inventory


def extract(archive, root):
    """Extract regular files before links so archive paths cannot follow image symlinks."""
    root.mkdir(parents=True, exist_ok=True)
    if any(root.iterdir()):
        raise InventoryError('export destination must be empty')
    links = []
    with tarfile.open(archive) as stream:
        for member in stream:
            name = pathlib.PurePosixPath(member.name)
            if name.is_absolute() or '..' in name.parts:
                raise InventoryError('unsafe archive path: ' + member.name)
            target = root.joinpath(*name.parts)
            if member.isdir():
                target.mkdir(parents=True, exist_ok=True)
            elif member.isfile():
                target.parent.mkdir(parents=True, exist_ok=True)
                with stream.extractfile(member) as source, target.open('wb') as destination:
                    shutil.copyfileobj(source, destination)
            elif member.issym() or member.islnk():
                links.append(member)
            # Device nodes and sockets carry no dependency bytes and are not recreated on the host.
        # Hardlinks refer to archive-root names, symlinks refer to image-root or parent names.
        for member in sorted(links, key=lambda m: m.issym()):
            target = root / member.name
            target.parent.mkdir(parents=True, exist_ok=True)
            if any(parent.is_symlink() for parent in target.parents if parent != root and root in parent.parents):
                raise InventoryError('archive link parent is a symlink: ' + member.name)
            if member.islnk():
                shutil.copyfile(root_file(root, member.linkname), target)
            else:
                target.symlink_to(member.linkname)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    sub = parser.add_subparsers(dest='command', required=True)
    gen = sub.add_parser('generate')
    gen.add_argument('--root', type=pathlib.Path, required=True)
    gen.add_argument('--image-inspect', type=pathlib.Path, required=True)
    gen.add_argument('--target', choices=('production', 'worker'), required=True)
    gen.add_argument('--revision', required=True)
    gen.add_argument('--output', type=pathlib.Path, required=True)
    check = sub.add_parser('verify')
    check.add_argument('--root', type=pathlib.Path, required=True)
    check.add_argument('--inventory', type=pathlib.Path, required=True)
    check.add_argument('--revision', required=True)
    check.add_argument('--image-inspect', type=pathlib.Path, required=True)
    unpack = sub.add_parser('extract')
    unpack.add_argument('--archive', type=pathlib.Path, required=True)
    unpack.add_argument('--root', type=pathlib.Path, required=True)
    args = parser.parse_args()
    try:
        if args.command == 'extract':
            extract(args.archive, args.root)
            return
        if args.command == 'generate':
            identity = image_identity(read_json(args.image_inspect), args.target)
            result = generate(args.root, identity, args.output, args.revision)
        else:
            identity = image_identity(read_json(args.image_inspect), read_json(args.inventory)['identity']['target'])
            result = verify(args.root, args.inventory, args.revision, identity)
        print(canonical({'integrity': 'passed', 'redistribution_review': result['redistribution_review'],
                         'files': len(result['files']), 'review_gaps': len(result['review_gaps'])}), end='')
    except (InventoryError, OSError, KeyError, TypeError, ValueError, tarfile.TarError) as error:
        parser.exit(1, 'Application inventory failed: ' + str(error) + '\n')


if __name__ == '__main__':
    main()
