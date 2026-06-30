# Profiling with Reli

[Reli](https://github.com/reliforp/reli-prof) is a sampling profiler for PHP that reads process state from outside via ptrace — no code changes needed in the target.

All profiling commands go through `bin/reli` on the host. See the `/reli` skill for the full command reference and debugging scenario guides.

## Quick Start

```bash
# Start the profiler sidecar
make prof-up

# List running processes
bin/reli ps

# Quick trace
bin/reli trace -P swoole -d 10

# Full capture (trace + flamegraph + memory)
bin/reli capture -P swoole -d 30

# Stop when done
make prof-down
```

## Common Workflows

### Generate a flamegraph

```bash
make prof-up
bin/reli flamegraph -P swoole -d 30
# Open .reli/latest/flamegraph.svg in browser
```

### Memory analysis

```bash
make prof-up
bin/reli memory -P swoole       # snapshot + report
bin/reli report                  # re-run report on latest
```

### Detect a memory leak

```bash
make prof-up
bin/reli leak-detect -P swoole -d 60
# Takes two snapshots 60s apart and compares them
```

### Inspect a running variable

```bash
make prof-up
bin/reli peek 'global::$counter' -p <pid>
```

### Interactive exploration

```bash
make prof-up
bin/reli capture -P swoole -d 10
bin/reli explore                  # opens TUI (rbt or rmem)
```

## Output

All captures are stored under `.reli/` with timestamped directories:

```
.reli/
├── latest → 2026-05-10T14-30-00_capture/
├── 2026-05-10T14-30-00_capture/
│   ├── manifest.json
│   ├── trace.rbt
│   ├── flamegraph.svg
│   ├── snapshot.rmem
│   └── report.txt
```

Clean up with `bin/reli clean` (keeps latest) or `bin/reli clean --all`.

## Makefile Targets

| Target | Description |
|--------|-------------|
| `make prof-up` | Start profiler sidecar |
| `make prof-down` | Stop profiler sidecar |
| `make prof-top` | Live top view |
| `make prof-trace PID=1234` | Trace a specific PID |
| `make prof-memory PID=1234` | Memory snapshot |
| `make prof-flamegraph PID=1234` | Trace + flamegraph |

---

## Viewing and Interpreting Results

### Flamegraphs

Flamegraphs show where wall time is spent. Each bar is a function call. Width = how many samples it appeared in (proportional to time). Bars are stacked by call depth — the bottom is the entry point, the top is the leaf function.

**Opening:**
```bash
# Flamegraph is a standard SVG — open in any browser
open .reli/latest/flamegraph.svg      # macOS
xdg-open .reli/latest/flamegraph.svg  # Linux
```

**Reading a flamegraph:**

1. **Wide bars at the top** = hot functions. A bar that spans most of the width with nothing above it means the target is spending most of its time directly inside that function.
2. **Tall stacks** = deep call chains. Look for towers — a wide bar at the base with a narrow tower on top means a specific code path is hot.
3. **Color** is random (by design) — it exists only to visually distinguish adjacent bars. Color has no semantic meaning.
4. **Click** any bar to zoom in on that subtree. The bar expands to full width and you see its children in proportion.
5. **Search** (Ctrl+F or click the search icon) highlights matching functions across the entire graph — useful for finding all calls to a specific method.

**What to look for:**

| Pattern | Meaning | Action |
|---------|---------|--------|
| A wide bar with nothing above it | Most time in a single function | Check if that function can be optimized or cached |
| A wide bar near the bottom (e.g. `HttpKernel::handle`) with many narrow children | Time is distributed across many calls | Zoom into the sub-stacks to find the heaviest children |
| Thin bars spread across the full width at the same depth | Many different code paths | Search for specific class/method names to isolate |
| Large `PDO::query` or `Doctrine\DBAL` bars | Database-bound | Check the query, add indexes, reduce result sets |
| Large `sleep`/`usleep`/`Swoole\Coroutine::sleep` bars | Waiting time, not CPU | Not a problem unless unexpected — trace the caller |
| `stream_*` / `fread` / `curl_exec` | I/O-bound | Consider async, caching, or batching |

**Tips:**
- The y-axis is stack depth, not time. Samples are sorted alphabetically within each level (not chronologically).
- If the flamegraph looks flat (everything at the same narrow width), the workload may be too diverse. Narrow your capture window to a specific request.
- Use `bin/reli trace -d 60` for thorough captures, `bin/reli trace -d 5` for quick checks.

### Trace Analysis (text reports)

If you prefer terminal output over SVGs, use `rbt:analyze` to get text reports from `.rbt` trace files:

```bash
# Basic report — self-time and total-time top 20
docker exec -i baander-profiler ./reli rbt:analyze < .reli/latest/trace.rbt

# Top 10 only, short paths
docker exec -i baander-profiler ./reli rbt:analyze --top=10 --path=short < .reli/latest/trace.rbt

# Who calls a specific method?
docker exec -i baander-profiler ./reli rbt:analyze --callers='AlbumRepository::find' < .reli/latest/trace.rbt

# What does a method spend its time on?
docker exec -i baander-profiler ./reli rbt:analyze --callees='AlbumRepository::find' < .reli/latest/trace.rbt

# Hide framework noise — only show App\\ code
docker exec -i baander-profiler ./reli rbt:analyze --hide='^Symfony\\|^Doctrine\\' < .reli/latest/trace.rbt

# Only count samples touching a specific class
docker exec -i baander-profiler ./reli rbt:analyze --match='TranscodeService' < .reli/latest/trace.rbt
```

**Reading the output:**
- **self-time** = time spent directly in that function (excluding callees). High self-time = the function itself is the bottleneck.
- **total-time** = time in that function plus everything it calls. High total-time with low self-time = one of its children is slow.
- **count** = number of samples where this frame appeared. At default 10ms sampling, 100 samples ≈ 1 second.

### Memory Reports

`bin/reli memory` automatically generates a `report.txt`. Read it top-down:

```bash
cat .reli/latest/report.txt
```

**Report structure:**

1. **Overview** — total heap size, stack, compiler arena. Gives you the scale of the problem.
2. **Findings** — prioritized list with severity (`[HIGH]`, `[MEDIUM]`, `[LOW]`):
   - `dominant_class` — a single class dominates memory. Check if instance count scales with input.
   - `bottleneck_path` — the heaviest allocation chain (root → … → leaf). This is your primary target.
   - `property_scaling` — per-instance properties that add up. Look for properties that could be shared or deduplicated.
   - `cycle_cluster` — circular references preventing GC. The report tells you which back-reference to break.
   - `dedup_candidate` — identical strings/objects copied many times. Candidate for interning or sharing.
3. **Type Breakdown** — table of PHP internal types by count and total memory.

**Common patterns:**

| Finding | Typical cause | Fix |
|---------|--------------|-----|
| `dominant_class` with unbounded count | Loading all rows from DB | Paginate, use cursors, stream |
| `cycle_cluster` | Bidirectional relations (Entity → Collection → Entity) | Break the cycle with `unset` or `WeakMap` |
| `dedup_candidate` on strings | Same config/enum value stored per instance | Share as class constant or static |
| `bottleneck_path` through a cache array | Cache growing without eviction | Add size limits or TTL |

### Leak Detection

`bin/reli leak-detect` takes two snapshots and compares them:

```bash
bin/reli leak-detect -P swoole -d 60
# Output: .reli/latest/compare.txt
```

The comparison shows which types grew between the two snapshots. Look for:
- Classes with positive count delta (new instances not being freed)
- Classes with positive memory delta (existing instances growing)
- This narrows down which component is leaking before you dig into the full memory graph.

### Interactive TUI Explorer

`bin/reli explore` opens either `rbt:explore` (for traces) or `rmem:explore` (for memory), depending on what's in the capture directory.

```bash
bin/reli explore              # latest capture (auto-detects type)
bin/reli explore .reli/<dir>  # specific capture
```

#### Trace explorer (`rbt:explore`)

| Key | Action |
|-----|--------|
| `↑`/`↓` | Move selection |
| `Enter` | Drill into selected frame |
| `Backspace` | Go back |
| `Tab` | Switch between views (panes, flame, tree) |
| `/` | Filter current list |
| `F` | Global search |
| `q` | Quit |

**Views:**
- **Panes** — callers on the left, selected frame in the center, callees on the right. Good for understanding call relationships.
- **Flame** — interactive flame chart. Scroll horizontally through the trace, click to zoom.
- **Tree** — hierarchical call tree. Expand/collapse subtrees to see where time goes.

#### Memory explorer (`rmem:explore`)

| Key | View | What it shows |
|-----|------|---------------|
| `t` | Roots | Top-level heap branches |
| `s` | Top retained | Nodes ranked by retained size |
| `c` | Class ranking | PHP classes by total memory |
| `y` | Type ranking | Node types by total memory |
| `x` | Cycles | Reference cycles (SCCs) |
| `i` | Subtree info | Breakdown of a node's children |

| Key | Action |
|-----|--------|
| `Enter` | Open sandwich view (parents + detail + children) |
| `Backspace` | Go back |
| `Tab` | Switch pane |
| `/` | Filter list |
| `F` | Global search |
| `f` | Toggle follow mode (broadcast cursor to browser/MCP) |
| `o` | Toggle sidebar (node detail + path to root) |
| `q` | Quit |

**Follow mode** (`f`) broadcasts your cursor position to a browser visualization or an MCP client (AI agent). Useful for walking someone through a memory graph.

### Memory Visualization (HTML)

For a visual overview of the heap, generate a standalone HTML file:

```bash
docker exec -i baander-profiler ./reli rmem:viz /var/www/html/.reli/latest/snapshot.rmem
# Writes .reli/latest/snapshot.rmem.viz.html
```

Open the HTML file in a browser. Views available:
- **Circle Pack** — nested circles showing retained size hierarchy
- **Treemap** — rectangular areas proportional to memory usage
- **Sunburst** — radial view of the allocation tree
- **3D Force** — force-directed graph of object references

These are particularly useful for spotting one class dominating memory — it visually overwhelms everything else.

---

## Tips

- **Duration**: Use `-d 5` for quick checks, `-d 30` for thorough profiling, `-d 60+` for leak detection.
- **Binary traces** (`.rbt`) are ~370x smaller than text — safe for long captures without filling disk.
- **`bin/reli capture`** gives you everything in one shot (trace + flamegraph + memory). Use it when you're not sure what you're looking for.
- **Flamegraph SVGs** open in any browser and are interactive — click to zoom, search to highlight.
- **`-S` (stop-process)**: Not exposed by default. Makes traces more accurate but pauses the target briefly each sample. Use for microbenchmarks, not production debugging.
- **`--with-native-trace`**: Shows C-level stack frames (extension internals). Useful for extension-level debugging (Swoole coroutines, libxml, etc.). Not exposed by default — run `docker exec -it baander-profiler ./reli inspector:trace --with-native-trace -p <pid>` if needed.
- **Cache**: reli caches PHP binary analysis under `~/.cache/reli/` inside the profiler container. Clear with `docker exec baander-profiler ./reli cache:clear` if you rebuild the PHP binary.
