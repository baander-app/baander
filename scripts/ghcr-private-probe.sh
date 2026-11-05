#!/bin/sh
# Fails when anyone can pull an image without credentials.
#
# Usage: sh scripts/ghcr-private-probe.sh <registry>/<owner>/<name>
#
# Fetches the image's `latest` manifest with an empty Docker configuration,
# so no stored login takes part. A registry that serves the manifest makes
# the image publicly pullable. A refusal (unauthorized, denied or unknown
# manifest) confirms that the image stays private. Any other error, such as
# a network failure, also fails, because privacy cannot be confirmed.
set -eu

image="${1:?usage: sh scripts/ghcr-private-probe.sh <registry>/<owner>/<name>}"
reference="$image:latest"

config="$(mktemp -d)"
trap 'rm -rf "$config"' EXIT

if output="$(DOCKER_CONFIG="$config" docker manifest inspect "$reference" 2>&1)"; then
    echo "::error::$reference is publicly pullable. Make the package private at once." >&2
    exit 1
fi

case "$output" in
    *"permission denied"*)
        ;;
    *denied*|*unauthorized*|*"manifest unknown"*|*"no such manifest"*|*"name unknown"*)
        echo "$reference is not pullable without credentials."
        exit 0
        ;;
esac

echo "::error::Cannot confirm that $reference is private." >&2
printf '%s\n' "$output" >&2
exit 1
