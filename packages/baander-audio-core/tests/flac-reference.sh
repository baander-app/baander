#!/usr/bin/env bash
# Build the reference FLAC tools the lossless suites and the benchmark
# compare against: xiph/flac at the commit of its 1.4.3 tag, built with
# CMake in Release, into $1 (default ../bench/work/ext, where the other
# reference codecs live).  Prints the path of the `flac` binary on
# standard output and nothing else there; `metaflac` comes from the same
# build, at ../metaflac/metaflac relative to it.
#
# The tools are oracles only: the suites run the binaries and compare
# their output.  Nothing of the checkout is compiled into the package.
#
# Needs: git, cmake and a C toolchain.
set -euo pipefail
dest=$(realpath -m "${1:-$(dirname "$0")/../bench/work/ext}")
commit=28e4f0528c76b296c561e922ba67d43751990599 # tag 1.4.3
version=1.4.3
src="$dest/flac"
build="$src/build"
flac="$build/src/flac/flac"
metaflac="$build/src/metaflac/metaflac"

reports_version() {
  [ -x "$1" ] && [ "$("$1" --version 2>/dev/null)" = "$2 $version" ]
}

if ! reports_version "$flac" flac || ! reports_version "$metaflac" metaflac ||
  [ "$(git -C "$src" rev-parse HEAD 2>/dev/null)" != "$commit" ]; then
  mkdir -p "$src"
  {
    if [ ! -d "$src/.git" ]; then
      git init -q "$src"
      git -C "$src" remote add origin https://github.com/xiph/flac
    fi
    # The tag comes along so that the build reports the release version
    # and not a commit id; the commit it points at is checked below.
    git -C "$src" fetch -q --depth 1 origin "refs/tags/$version:refs/tags/$version"
    git -C "$src" checkout -q --detach "refs/tags/$version"
    if [ "$(git -C "$src" rev-parse HEAD)" != "$commit" ]; then
      echo "flac-reference.sh: tag $version is not at $commit" >&2
      exit 1
    fi
    rm -rf "$build"
    cmake -S "$src" -B "$build" -DCMAKE_BUILD_TYPE=Release \
      -DBUILD_SHARED_LIBS=OFF -DBUILD_PROGRAMS=ON -DBUILD_CXXLIBS=OFF \
      -DBUILD_EXAMPLES=OFF -DBUILD_TESTING=OFF -DBUILD_DOCS=OFF \
      -DINSTALL_MANPAGES=OFF -DWITH_OGG=OFF
    cmake --build "$build" --parallel "$(nproc)" --target flacapp metaflac
  } >&2
fi

if ! reports_version "$flac" flac || ! reports_version "$metaflac" metaflac; then
  echo "flac-reference.sh: $flac does not report version $version" >&2
  exit 1
fi
echo "$flac"
