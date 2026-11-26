#!/bin/bash
# Fills the build script's packages directory from the source manifest. An archive must
# match its SHA-256; a Git source is fetched at its commit and archived here, so its bytes
# do not depend on a forge's archive generator.
set -euo pipefail

manifest=$1
mkdir -p "$2"
packages=$(realpath "$2")

while read -r file pin source layout; do
    case $file in '' | '#'*) continue ;; esac
    case $pin in
        sha256:*)
            curl -fsSL --retry 3 -o "$packages/$file" "$source"
            echo "${pin#sha256:}  $packages/$file" | sha256sum -c -
            ;;
        git:*)
            commit=${pin#git:}
            repository=$(mktemp -d)
            git -c init.defaultBranch=source init -q "$repository"
            git -C "$repository" fetch -q --depth 1 "$source" "$commit"
            test "$(git -C "$repository" rev-parse FETCH_HEAD)" = "$commit"
            prefix=${file%.tar.gz}/
            if [ "$layout" = flat ]; then
                prefix=
            fi
            git -C "$repository" archive --format=tar.gz --prefix="$prefix" -o "$packages/$file" FETCH_HEAD
            rm -rf "$repository"
            echo "$packages/$file: commit $commit"
            ;;
        *)
            echo "$file is pinned by neither a SHA-256 nor a commit." >&2
            exit 1
            ;;
    esac
done < "$manifest"
