#!/usr/bin/env bash
# Sync ui/rn and ui/shared source to a native Windows path.
# Creates the layout: <dst>/rn/ + <dst>/shared/ so "link:../shared" resolves.
#
# Usage: ./sync-to-win.sh [windows-path]
# Default: /mnt/d/baander-rn

set -euo pipefail

UI_DIR="$(cd "$(dirname "$0")/.." && pwd)"
DST="${1:-/mnt/d/baander-rn}"

mkdir -p "$DST"

for DIR in rn shared; do
  echo "Syncing $DIR/..."
  rsync -a --delete \
    --exclude='node_modules' \
    --exclude='.cache' \
    --exclude='build' \
    --exclude='dist' \
    --exclude='.gradle' \
    --exclude='android/.gradle' \
    --exclude='android/app/build' \
    --exclude='ios/Pods' \
    --exclude='ios/build' \
    --exclude='windows/build' \
    --exclude='.expo' \
    --exclude='.DS_Store' \
    --exclude='*.log' \
    "$UI_DIR/$DIR/" "$DST/$DIR/"
done

echo "Synced to $DST"
