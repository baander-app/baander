---
title: A Swoole table sized to its key count cannot hold all its keys
date: 2026-10-09
category: runtime-errors
module: Shared Swoole infrastructure (diagnostics span buffer)
problem_type: runtime_error
component: infrastructure
symptoms:
  - "app:server:spans and the diagnostics page showed gaps in the recent-span list"
  - "Swoole\\Table::set() returned false for about a quarter of the ring's slot keys, and the caller ignored it"
  - "A span longer than its string column was cut short, and the next getRecentSpans() threw JsonException"
root_cause: wrong_api
resolution_type: code_fix
severity: medium
framework_version: swoole 6.2.1
tags: [swoole, swoole-table, shared-memory, conflict-proportion, span-buffer, diagnostics]
---

# A Swoole table sized to its key count cannot hold all its keys

## Problem

`new Swoole\Table($size)` does not reserve a row for each of `$size` keys. With the default conflict proportion (0.2), a table runs out of rows well before it holds `$size` keys, and `set()` then returns `false` without throwing. The diagnostics span ring in `SpanBridge` was created as `new Table(MAX_SPANS + 1)` for exactly 501 fixed keys, so about 120 of its 500 slots could never be written.

## Symptoms

- Gaps in `app:server:spans` and on the diagnostics page. Every HTTP request feeds the ring, so the missing slots showed up all the time.
- Reproduced on Swoole 6.2.1 with the real key set (`__meta` plus `span_0`..`span_499`): the table rounded up to size 512, stored 379 rows, and refused 122 `set()` calls.
- A second, separate failure in the same buffer: a span whose JSON was longer than the 16 KB string column was stored cut short. Swoole only logged a warning and `set()` returned `true`. The next `getRecentSpans()` call then failed for the whole read with `JsonException: Control character error`.

## What Didn't Work

The original code made three assumptions that hid the problem:

- That one extra row for the metadata key (`MAX_SPANS + 1`) was enough. The row count was never the limit; the default overflow pool was.
- That Swoole's power-of-two rounding gives headroom. 501 rounds up to 512, only 2% more rows than keys.
- That a missing row means an empty slot. `getRecentSpans()` skips missing rows with `continue`, so lost writes looked like gaps, not errors.

## Solution

Changed in commit 495bc07e ("fix(shared): span buffer keeps every slot; control socket checks its owner"):

```php
// src/Shared/Infrastructure/OpenTelemetry/SpanBridge.php:50
// Before
$table = new Table(self::MAX_SPANS + 1);
// After: an overflow pool as large as the table, so every key gets a row
$table = new Table(self::MAX_SPANS + 1, 1.0);
```

`addSpan()` also changed:

- It encodes the span first and skips it when `strlen($data) > MAX_SPAN_BYTES` (`SpanBridge.php:72`), so Swoole never truncates a value.
- It checks the result of `set()` (`SpanBridge.php:81`). On failure it deletes the slot, so the span from the previous lap does not show as the newest, and it logs a warning once per process.

Measured costs for this table (500 slots, 16 KB string column):

| Conflict proportion | Rows stored | Failed sets | Shared memory |
|---|---|---|---|
| default (0.2) | 379 | 122 | 1.68 MB |
| 0.5 | 501 | 0 | 4.2 MB |
| 1.0 | 501 | 0 | 8.45 MB |

The fix uses 1.0, not 0.5. With 1.0 the overflow pool can hold every key whatever the hash distribution, so the result does not depend on the Swoole build's hash function. 0.5 happens to work for these keys today.

Regression tests are in `tests/Unit/Shared/Infrastructure/OpenTelemetry/SpanBridgeTest.php`: a full ring keeps the newest spans in order, and an oversized span is dropped and logged once.

## Why This Works

Swoole rounds the requested size up to a power of two and uses it as the number of hash buckets. A key whose bucket is already taken goes to an overflow pool of `size * conflict_proportion` rows. With the default 0.2, that pool runs out before every bucket fills. Measured on Swoole 6.2.1 with random 24-character keys:

- `new Table(1024)`, `new Table(4096)` and `new Table(8192)` refused their first key after about 70% of the rounded size (68-72% across runs).
- Filling each table with `size` keys stored about 83% of them.

A proportion of 1.0 makes the overflow pool as large as the bucket array, so no key set can exhaust it.

String columns have a fixed width. Swoole truncates a longer value instead of refusing it, so a structured value (JSON, serialized PHP) has to be length-checked before `set()`.

## Prevention

- Treat `new Table(N)` as "fewer than N keys". For a table that must hold a known key set, either pass a conflict proportion of 1.0 or size the table at about 1.5x the peak key count. Use 1.0 when memory is small or when the key set is fixed and every key must fit.
- Check the return value of `set()` and `incr()`. Both return `false` when no row is free, and neither throws. Decide what a refused write means (drop and log, fall back, or fail) and write that down next to the call.
- Length-check values for every `TYPE_STRING` column before writing them.
- When a reader skips missing rows, make sure a failed write is visible somewhere else (a log line, a counter), or the loss stays silent.
- A test for a table that must hold a fixed key set should write every key and assert they all read back. That is the test that caught this.

Other `Swoole\Table` uses in `src/`, as of this writing (`grep -rn "new Table(" src/`):

| Table | Size | Keys | Headroom / failure handling |
|---|---|---|---|
| `src/QoL/Infrastructure/Swoole/CpuGpuSampler.php:47` | 2 | 1 | Plenty. |
| `src/QoL/Infrastructure/Swoole/AlgorithmProfileTable.php:50` | 2 | 1 | Plenty. |
| `src/Shared/Infrastructure/Swoole/ProcessPool/CpuProcessPool.php:122` (health) | `workerCount + 1` | `pool` plus one per worker | Same shape as the span ring, default proportion. Fine at the configured 6 workers (`config/services.yaml:1038`). Measured: a pool of 45 to 63 workers would lose keys. `publishHealth()` throws when `set()` fails, so the failure would be loud. |
| `src/Shared/Infrastructure/Swoole/ProcessPool/CpuProcessPool.php:135` (results) | 8192 | one per in-flight job | Plenty. Results are also written to files, which are the source of truth. |
| `src/Shared/Infrastructure/Swoole/ProcessPool/CpuProcessPool.php:142` (limits) | 64 | one per limit key | Plenty for the few keys in use. If `incr()` ever failed it would return `false`, and `false > $limit` lets the dispatch through without a limit (`CpuProcessPool.php:347`). |
| `src/Shared/Infrastructure/Swoole/ReconnectionTokenService.php:25` | 4096 | one per token, 5-minute TTL | With random keys like these, the first refusal came at about 70% of 4096. `generate()` ignores the result of `set()`, so a refused token is handed out and later fails to reconnect. |
| `src/Shared/Infrastructure/Swoole/WebSocketConnectionRegistry.php:34-44` | 1024 connections, 8192 room memberships | one per connection / membership | Expect the first refusal below 1024 connections (about 70% of the size with random keys). `set()` results are not checked. |
| `src/Transcode/Infrastructure/Swoole/SegmentAvailabilityTable.php:61` | 16384 by default | one per ready segment | Sized with headroom (see its docblock). It checks `set()`, warns, and falls back to polling the file system. |

## Related Issues

- None in `docs/solutions/` yet.
