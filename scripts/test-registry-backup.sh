#!/usr/bin/env bash
# SPDX-License-Identifier: Apache-2.0
set -euo pipefail
cd "$(dirname "$0")/.."

for command in docker timeout python3; do
    command -v "$command" >/dev/null
done
docker buildx version >/dev/null

run_id="baander-registry-backup-$(date +%s)-$$"
backup_image="$run_id:backup"
api_image="$run_id:api"
database_image="$run_id:database"
contract_image="$run_id:contract"
container="$run_id-contract"
work="$(mktemp -d /tmp/baander-registry-backup.XXXXXXXX)"
inventory_output="${REGISTRY_INVENTORY_OUTPUT:-var/test-runs/registry-inventory/$run_id}"
cleanup() {
    docker rm -f "$container" >/dev/null 2>&1 || true
    docker image rm "$contract_image" "$backup_image" "$api_image" "$database_image" >/dev/null 2>&1 || true
    rm -rf "$work"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

docker buildx build --platform linux/amd64 --load \
    -f relay/docker/backup.Dockerfile -t "$backup_image" relay
docker buildx build --platform linux/amd64 --load \
    -f relay/docker/Dockerfile -t "$api_image" relay
docker buildx build --platform linux/amd64 --load \
    -f relay/docker/rqlite.Dockerfile -t "$database_image" relay
export PYTHONDONTWRITEBYTECODE=1
python3 relay/tools/verify_packaged_image.py --image "$backup_image" --kind backup --output "$inventory_output"
python3 relay/tools/verify_packaged_image.py --image "$api_image" --kind api --output "$inventory_output"
python3 relay/tools/verify_packaged_image.py --image "$database_image" --kind rqlite --output "$inventory_output"

# Copy through the build context so this works with a remote Docker daemon too.
mkdir "$work/tests" "$work/backup"
cp relay/tests/*.py "$work/tests/"
cp relay/backup/registry_backup.py "$work/backup/"
cat > "$work/Dockerfile" <<'DOCKERFILE'
ARG BACKUP_IMAGE=baander-registry-backup:qualification
ARG API_IMAGE=baander-registry:backup-contract
ARG DATABASE_IMAGE=baander-registry-rqlite:qualification
FROM ${API_IMAGE} AS api
FROM ${DATABASE_IMAGE} AS database
FROM ${BACKUP_IMAGE}
USER root
RUN apt-get update && apt-get install -y --no-install-recommends libstdc++6 \
    && rm -rf /var/lib/apt/lists/*
COPY --from=api /usr/local/bin/baander-registry /usr/local/bin/baander-registry
COPY --from=database /usr/local/bin/rqlited /usr/local/bin/rqlited
COPY tests /qualification/tests
COPY backup /qualification/backup
ENV PYTHONDONTWRITEBYTECODE=1
USER 10001:10001
WORKDIR /qualification
ENTRYPOINT ["python3"]
DOCKERFILE
docker buildx build --platform linux/amd64 --load \
    --build-arg "BACKUP_IMAGE=$backup_image" --build-arg "API_IMAGE=$api_image" \
    --build-arg "DATABASE_IMAGE=$database_image" -t "$contract_image" "$work"

# One bounded container runs the core fault contracts, encrypted recovery and the
# unchanged CLI restore scenario. A timed-out container is removed by the trap.
timeout --signal=INT --kill-after=15s 300s docker run --name "$container" \
    --network none --read-only --tmpfs /tmp:rw,exec,nosuid,nodev,size=128m,mode=1777 \
    --entrypoint /bin/sh "$contract_image" -ec '
        python3 tests/test_backup.py
        python3 tests/run_backup_contract.py --rqlited /usr/local/bin/rqlited --server /usr/local/bin/baander-registry
        python3 tests/run_restore_contract.py --rqlited /usr/local/bin/rqlited --server /usr/local/bin/baander-registry
    '
echo 'Registry encrypted backup and fresh-storage recovery qualification passed.'
