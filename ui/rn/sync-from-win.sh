#!/usr/bin/env bash
# Sync changes from the Windows native path back to WSL source tree.
# Syncs both rn/ and shared/ back.
#
# Fixes permissions: NTFS mounts show everything as 777.
# We force files to 644 and dirs to 755, then re-apply +x on known scripts.
#
# Usage: ./sync-from-win.sh [windows-path]
# Default: /mnt/d/baander-rn

set -euo pipefail

UI_DIR="$(cd "$(dirname "$0")/.." && pwd)"
SRC="${1:-/mnt/d/baander-rn}"

if [ ! -d "$SRC" ]; then
  echo "Source not found: $SRC"
  exit 1
fi

for DIR in rn shared; do
  if [ -d "$SRC/$DIR" ]; then
    echo "Syncing $DIR/..."
    rsync -a \
      --no-perms \
      --chmod=F644,D755 \
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
      "$SRC/$DIR/" "$UI_DIR/$DIR/"
  fi
done

# Re-apply execute bit on scripts
chmod +x "$UI_DIR/rn/sync-to-win.sh" "$UI_DIR/rn/sync-from-win.sh" 2>/dev/null || true
# Also fix any .sh files that came from Windows
find "$UI_DIR/rn" "$UI_DIR/shared" -name '*.sh' -exec chmod +x {} + 2>/dev/null || true

echo "Synced from $SRC to $UI_DIR"
