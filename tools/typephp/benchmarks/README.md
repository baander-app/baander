# TypePHP runtime performance

The TypePHP build did not improve the median time of these three workloads on the
measured host. Console discovery took 3.19 times as long as ordinary PHP. Operator
startup took 4.2% longer, and the sustained application loop took 6.6% longer.
These are CLI and application-code measurements; they do not measure HTTP or worker
throughput.

The [raw measurements](results/2026-11-20/measurements.json) contain every sample,
output check, effective setting and derived summary. The
[run manifest](results/2026-11-20/manifest.json) identifies the source, executables,
images, host and collection parameters. Both records belong to the run on
20 November 2026, from 15:39:16 to 15:40:38 UTC.

## Results

Each workload has 30 measured samples per runtime after three warmups per runtime.
Times are milliseconds. The ratio divides the TypePHP median by the ordinary PHP
median: a value above 1 means TypePHP took longer. The p95 is the 29th value in
each sorted set of 30 observations, using the nearest-rank definition.

| Workload | Ordinary PHP median / p95 | TypePHP median / p95 | TypePHP / ordinary |
| --- | ---: | ---: | ---: |
| Console discovery, fresh process | 353.78 / 363.89 ms | 1,128.84 / 1,135.05 ms | 3.191 |
| Operator help, fresh process | 44.70 / 46.64 ms | 46.58 / 47.58 ms | 1.042 |
| Sustained application loop, 100,000 iterations | 309.07 / 317.28 ms | 329.39 / 336.41 ms | 1.066 |

The observed ranges were 349.01–368.31 ms versus 1,123.08–1,136.48 ms for console
discovery, 42.98–46.71 ms versus 44.60–48.95 ms for operator help, and
303.69–326.72 ms versus 317.68–347.19 ms for the sustained loop. Each pair lists
ordinary PHP first. No measured sample failed or was excluded.

The following medians cover the entire child process, including startup and exit.
CPU time is user plus system time. Peak resident memory is reported by Linux
`wait4` for each child; MiB means 1,024 KiB. It includes the process's runtime and
mapped pages, and is not a measurement of PHP heap usage or steady worker memory.

| Workload | Ordinary PHP CPU | TypePHP CPU | Ordinary PHP peak RSS | TypePHP peak RSS |
| --- | ---: | ---: | ---: | ---: |
| Console discovery | 353.57 ms | 1,128.63 ms | 83.73 MiB | 112.82 MiB |
| Operator help | 44.55 ms | 46.46 ms | 53.54 MiB | 61.05 MiB |
| Sustained loop process | 366.14 ms | 377.40 ms | 54.52 MiB | 65.16 MiB |

The sustained process's total wall-time medians were 366.38 ms and 377.57 ms.
Its primary result in the first table uses an internal timer around the loop,
excluding interpreter startup, the initial Composer loader and process exit.

## Method

The [collector](measure.py) runs both executables inside one disposable,
network-disabled container. Ordinary PHP is copied from the matching CI image;
the packaged executable and native libraries come from the qualification image.
Both see the same read-only production source and dependency tree at
`/var/www/html`, with a private writable `var` directory. Container creation,
Docker commands and identity inspection are outside the measured intervals.

The application source matches commit
`7aeaccc30d86e15a8fb6de9bd46e2b94e317a2a1`. The runner checked 2,886 tracked input
files against the checkout and compared 13,325 source/dependency files through
the packaged runtime's file reader. Embedded paths return archived source bytes;
other paths remain physical files. All bytes matched. The production Composer
installation contains 157 packages and no development dependencies.

The packaged executable has SHA-256
`408ad77b210bb0a9bdf94acb0cc93ce01029aef043af50e81b8612a41be88b25`.
The manifest records both immutable image IDs, the ordinary executable hash,
lockfile and source-tree hashes, and the exact harness file hashes. The harness
was committed as `861ba672`; the pending documentation and site edits listed in
the manifest do not change the measured application inputs.
Review subsequently tightened the runner's preflight to reject untracked
application files. A [source recheck](results/2026-11-20/source-validation.json)
found none in the measured snapshot. The timing collector and workload are
unchanged; the manifest retains the hashes of the exact measured harness.

Both runtimes report PHP 8.5.12 ZTS, non-debug builds, and Swoole 6.2.4. The host
has an AMD Ryzen 7 5800X3D, 16 logical CPUs and Linux 7.3.4-1-default on x86_64.
Both use production mode, a 512 MiB memory limit, enabled CLI OPcache, disabled
JIT, disabled assertions and matching OPcache optimization settings. Debugging
and telemetry are disabled. The qualification-only autoloader is disabled, with
no automatic prepend. The collector checks equality of its selected effective
INI settings before timing; the full selection is in the raw measurements.

The workloads are:

- **Console discovery:** the application entry dispatches `console list --raw
  --no-interaction`. Each sample starts a process, boots the application and
  prints the command list.
- **Operator help:** the same entry dispatches `operator --help`, using the
  independent deployment-operator composition root.
- **Sustained application loop:** the [driver](workload.php) loads the canonical
  Composer autoloader, then repeatedly constructs and validates fixed `PublicId`
  and `Uuid` values, compares them, converts the UUID to binary and serializes
  the values to JSON. It hashes every encoded result. The collector checks the
  iteration count and digest against an independently constructed expected
  result. Application and dependency classes use the package's embedded code;
  the identical external driver supplies the loop and timer to both runtimes.

Each workload alternates which runtime runs first. Warmups precede the measured
samples; Symfony's generated cache and the host filesystem cache are warm.
Each fresh PHP process creates its own CLI OPcache state, with no persistent
OPcache file cache. These startup figures are not cold-machine boot times.
Startup output must match exactly, without normalization. A child failure,
unexpected stderr or incorrect result stops collection and prevents an accepted
comparison.

A [pilot run](results/2026-11-20/pilot-measurements.json.gz) established that
100,000 iterations produced loop samples lasting roughly 0.3 seconds. Its
[manifest](results/2026-11-20/pilot-manifest.json) records that harness metadata
and output storage were still being finalized. The tables use only the later
run on the committed harness, with the same iteration count. The pilot is
retained for audit and is not pooled with the final samples.

## Limits

This is one run on one shared development host, without CPU pinning or control
of other workloads, CPU frequency or filesystem caches. The manifest records
host load before and after collection. The ranges and p95 describe these
samples; they are not confidence intervals or guarantees for another machine.
The smaller operator and loop differences need repeated controlled runs before
being treated as stable performance differences.

The comparison includes the complete packaged runtime. Its extensions are
statically linked, while ordinary PHP loads shared extensions. Ordinary PHP has
Xdebug loaded with `XDEBUG_MODE=off`; TypePHP has the additional `msgpack` and
`typephp_baander` modules. The records contain each runtime's extension inventory
and versions. These differences prevent attributing the timings to bytecode
embedding alone.

TypePHP runs this application's embedded PHP bytecode through ZendVM. This
measurement does not show application code translated to native machine code.
It also does not cover HTTP requests, database queries, scanning, encoding,
worker concurrency, compilation time or distribution size. Console discovery
lists commands; it does not measure their execution. No application behavior was
optimized for the benchmark.

## Reproduce the comparison

Use Linux with Docker and Python 3. Prepare a production source tree and matching
ordinary and qualification images using the [packaging guide](../README.md).
Keep that exact source tree, including its production `vendor`, after building
the executable. The benchmark does not replace the current checkout's vendor
directory or reset a running instance. Images must already be available locally;
the recorded image IDs are local build identities, not published downloads.

From the repository root, set `TYPEPHP_SOURCE` to that production tree. For the
recorded artifacts, the collection command is:

```sh
python3 tools/typephp/benchmarks/run.py \
  --source "$TYPEPHP_SOURCE" \
  --ordinary-image sha256:78479bd47da48596ca757d4b41f663754240485c3a9f4e86450415fc4d2531d3 \
  --typephp-image sha256:e9ccdc03ba9c52c51ce730820f07261638a0101d024281959a3d6068a9d1b487 \
  --binary-sha256 408ad77b210bb0a9bdf94acb0cc93ce01029aef043af50e81b8612a41be88b25 \
  --source-commit 7aeaccc30d86e15a8fb6de9bd46e2b94e317a2a1 \
  --samples 30 --warmups 3 --iterations 100000 \
  --output var/typephp-performance-repeat
```

Use a new output directory for each run. For a rebuilt application, supply its
own matching images, binary hash and source commit; do not reuse these identities.
Retain the new observations before publishing a new comparison.

Recompute the stored summaries from the raw final samples:

```sh
python3 - <<'PY'
import json
import sys
from pathlib import Path
sys.path.insert(0, 'tools/typephp/benchmarks')
from measure import summarize
result = json.loads(Path('tools/typephp/benchmarks/results/2026-11-20/measurements.json').read_text())
assert summarize(result['records']) == result['summary']
print(json.dumps(result['summary'], indent=2))
PY
```

The harness's focused failure, checksum and summary checks run with
`python3 -m unittest discover -s tools/typephp/benchmarks -p 'test_*.py'`.
