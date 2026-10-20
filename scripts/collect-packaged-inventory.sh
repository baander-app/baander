#!/usr/bin/env bash
# SPDX-License-Identifier: Apache-2.0
set -euo pipefail

usage() {
    cat <<'EOF'
Usage: scripts/collect-packaged-inventory.sh --image IMAGE --target production|worker --revision REV --output DIRECTORY

Export a stopped container, collect packaged membership and retained licensing
notices, and verify their integrity. Review gaps remain release blockers.
EOF
}

image= target= revision= output=
while (($#)); do
    case "$1" in
        --image|--target|--revision|--output)
            if (($# < 2)); then usage >&2; exit 2; fi
            case "$1" in
                --image) image=$2 ;;
                --target) target=$2 ;;
                --revision) revision=$2 ;;
                --output) output=$2 ;;
            esac
            shift 2 ;;
        --help|-h) usage; exit 0 ;;
        *) usage >&2; exit 2 ;;
    esac
done
if [[ -z "$image" || -z "$revision" || -z "$output" || ! "$target" =~ ^(production|worker)$ ]]; then
    usage >&2
    exit 2
fi

script_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
mkdir -p -- "$output"
output=$(cd -- "$output" && pwd)
work_dir=$(mktemp -d "${TMPDIR:-/tmp}/baander-application-inventory.XXXXXXXX")
container_id=
cleanup() {
    local status=$?
    trap - EXIT
    if [[ -n "$container_id" ]]; then
        docker rm -v -- "$container_id" >> "$output/collection.log" 2>&1 || true
    fi
    rm -rf -- "$work_dir"
    exit "$status"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

# Keep diagnostics and generated reports outside disposable export storage.
exec > >(tee "$output/collection.log") 2>&1
docker image inspect -- "$image" > "$output/image-inspect.json"
image_id=$(python3 - "$output/image-inspect.json" "$revision" <<'PY'
import json
import sys
with open(sys.argv[1]) as stream:
    image = json.load(stream)[0]
revision = (image.get('Config', {}).get('Labels') or {}).get('org.opencontainers.image.revision')
if not revision or revision != sys.argv[2]:
    raise SystemExit('Observed image revision differs from expected revision')
print(image['Id'])
PY
)
# Creating a container does not execute its entrypoint or application process.
container_id=$(docker create --entrypoint /bin/true "$image_id")
docker export --output "$work_dir/filesystem.tar" "$container_id"
python3 "$script_dir/packaged_inventory.py" extract --archive "$work_dir/filesystem.tar" --root "$work_dir/root"
python3 "$script_dir/packaged_inventory.py" generate \
    --root "$work_dir/root" --image-inspect "$output/image-inspect.json" \
    --target "$target" --revision "$revision" --output "$output"
python3 "$script_dir/packaged_inventory.py" verify \
    --root "$work_dir/root" --image-inspect "$output/image-inspect.json" \
    --inventory "$output/inventory.json" --revision "$revision"
