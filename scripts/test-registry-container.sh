#!/usr/bin/env bash
# SPDX-License-Identifier: Apache-2.0
set -euo pipefail
cd "$(dirname "$0")/.."

for command in docker python3 openssl timeout; do
    command -v "$command" >/dev/null
done
docker buildx version >/dev/null

run_id="baander-registry-qualification-$(date +%s)-$$"
api_image="$run_id:api"
database_image="$run_id:database"
cleanup() {
    docker image rm "$api_image" "$database_image" >/dev/null 2>&1 || true
}
trap cleanup EXIT

docker buildx build --platform linux/amd64 --load \
    -f relay/docker/Dockerfile -t "$api_image" relay
docker buildx build --platform linux/amd64 --load \
    -f relay/docker/rqlite.Dockerfile -t "$database_image" relay
export PYTHONDONTWRITEBYTECODE=1
timeout --signal=INT --kill-after=30s 300s python3 relay/tests/run_container_contract.py \
    --api-image "$api_image" --rqlite-image "$database_image"
echo 'Registry production container qualification passed.'
