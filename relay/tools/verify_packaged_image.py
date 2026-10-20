#!/usr/bin/env python3
# SPDX-License-Identifier: Apache-2.0
"""Verify an immutable local image and retain its inventory before tag cleanup."""
import argparse
from pathlib import Path
import shutil
import subprocess
import tempfile

import packaged_inventory


def qualify(image, kind, output):
    image_id = subprocess.check_output(
        ['docker', 'image', 'inspect', '--format', '{{.Id}}', image], text=True).strip()
    container = subprocess.check_output(['docker', 'create', image_id], text=True).strip()
    try:
        with tempfile.TemporaryDirectory(prefix='registry-inventory-') as directory:
            root = Path(directory) / 'rootfs'
            root.mkdir()
            subprocess.run(['docker', 'cp', f'{container}:/.', str(root)], check=True)
            bundle = root / packaged_inventory.BUNDLE_PATH.lstrip('/')
            inventory_path = bundle / 'inventory.json'
            subprocess.run([
                'python3', str(Path(__file__).with_name('packaged_inventory.py')),
                'verify', '--root', str(root), '--inventory', str(inventory_path),
                '--resolutions', str(Path(__file__).parents[1] / 'third_party/inventory'),
            ], check=True)
            inventory = packaged_inventory.read_json(inventory_path)
            if inventory['kind'] != kind:
                raise packaged_inventory.InventoryError(
                    f'expected {kind} image, found {inventory["kind"]} inventory')
            destination = output / kind / image_id.removeprefix('sha256:')
            destination.mkdir(parents=True, exist_ok=True)
            shutil.copytree(bundle, destination / 'bundle', dirs_exist_ok=True)
            receipt = {
                'image_id': image_id,
                'kind': kind,
                'inventory_sha256': packaged_inventory.digest(inventory_path.read_bytes()),
                'packages': len(inventory['packages']),
                'components': len(inventory['components']),
                'executables': inventory['executables'],
                'status': 'passed',
            }
            (destination / 'receipt.json').write_text(packaged_inventory.canonical(receipt))
            print(f'{kind}: verified {receipt["packages"]} packages and '
                  f'{receipt["components"]} components in {image_id}; evidence {destination}')
    finally:
        subprocess.run(['docker', 'rm', container], check=True, stdout=subprocess.DEVNULL)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--image', required=True)
    parser.add_argument('--kind', required=True, choices=packaged_inventory.EXECUTABLES)
    parser.add_argument('--output', required=True, type=Path)
    args = parser.parse_args()
    try:
        qualify(args.image, args.kind, args.output)
    except (packaged_inventory.InventoryError, OSError, ValueError, subprocess.CalledProcessError) as error:
        parser.exit(1, f'Image inventory qualification failed: {error}\n')


if __name__ == '__main__':
    main()
