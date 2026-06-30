# Reli Prof Tooling — Requirements

## Problem

Every `reli-prof` invocation requires `docker compose --profile profiling exec profiler ./reli ...` — ~60 characters of boilerplate before the actual command. PID discovery is manual (`docker top baander-app` then visually scan). Multi-step workflows (trace → convert → flamegraph, memory dump → analyze → report) have no automation. The `rmem:mcp` AI-assisted memory analysis server is untapped.

## Solution

A 5-layer tooling stack that turns reli from a raw CLI into an integrated debugging workbench.

---

## Layer 1: `bin/reli` — Shell Wrapper

A bash script (`bin/reli`, chmod +x) that eliminates docker compose boilerplate and manages the full capture lifecycle.

### Subcommands

| Command | What it does |
|---------|-------------|
| `reli up` | Start profiler sidecar (`docker compose --profile profiling up profiler -d`) |
| `reli down` | Stop profiler sidecar |
| `reli ps` | List Baander PHP processes with PIDs and role labels (Swoole master, worker, task-worker, messenger consumer) |
| `reli top [-P pattern]` | Real-time aggregated trace view |
| `reli trace [-p PID] [-P pattern] [-d 30s]` | Capture trace to `.reli/<ts>_trace/` |
| `reli flamegraph [-p PID] [-P pattern] [-d 30s]` | Trace + convert to SVG in one step |
| `reli memory [-p PID] [-P pattern]` | Memory dump + analyze to `.reli/<ts>_memory/` |
| `reli leak-detect [-p PID] [-P pattern] [-d 30s]` | Two memory snapshots 30s apart + compare |
| `reli capture [-p PID] [-P pattern] [-d 30s]` | Trace + flamegraph + memory all at once |
| `reli peek <var-expr> [-p PID]` | Inspect a PHP variable in a running process |
| `reli report [<capture-dir>]` | Run memory report on latest or specified capture |
| `reli explore [<capture-dir>]` | Open `rbt:explore` or `rmem:explore` TUI |
| `reli ls` | List all captures in `.reli/` |
| `reli clean [--all]` | Remove captures. `--all` wipes `.reli/` |

### Behavior

- **Sidecar check**: Errors if profiler container not running: `"Profiler not running. Run: make prof-up"`
- **PID discovery**: If `-p` and `-P` both omitted, runs `reli ps` and prompts for selection. If `-P` given, picks first matching PID.
- **Duration default**: 30 seconds (3,000 samples at 10ms interval). Overridable with `-d <seconds>`.
- **Output management**: All captures go to `.reli/YYYY-MM-DDTHH-MM-SS_<type>/`. Each gets a `manifest.json`.
- **Latest symlink**: `.reli/latest` → most recent capture directory.

### `manifest.json` schema

```json
{
  "type": "trace|memory|capture|leak-detect",
  "timestamp": "2026-05-10T14:30:00+02:00",
  "pid": 12345,
  "pattern": "swoole",
  "duration_seconds": 30,
  "files": {
    "trace": "trace.rbt",
    "flamegraph": "flamegraph.svg",
    "snapshot": "snapshot.rmem",
    "report": "report.txt"
  }
}
```

---

## Layer 2: Makefile Targets

| Target | Command |
|--------|---------|
| `make prof-up` | `bin/reli up` |
| `make prof-down` | `bin/reli down` |
| `make prof-top` | `bin/reli top` |
| `make prof-trace PID=<pid>` | `bin/reli trace -p <pid>` |
| `make prof-memory PID=<pid>` | `bin/reli memory -p <pid>` |
| `make prof-flamegraph PID=<pid>` | `bin/reli flamegraph -p <pid>` |

---

## Layer 3: `/reli` Skill (`.claude/skills/reli/`)

Project-scoped skill at `.claude/skills/reli/SKILL.md`.

### Capabilities

- Documents all `bin/reli` subcommands with examples
- Knows Baander's process architecture: Swoole master (`swoole`), HTTP workers, task workers, messenger consumers (`messenger:consume`)
- Provides scenario-based debugging guides
- Drives captures via `bash` calls to `bin/reli`
- Reads `manifest.json` and capture output (report.txt, folded stacks) for analysis
- Invokes MCP tools for memory analysis when available

### Debugging Scenarios

1. **Slow endpoint**: `reli trace` during request → `reli flamegraph` → identify hot path
2. **Memory leak**: `reli leak-detect` → compare report → identify growing object types
3. **Stuck worker**: `reli ps` → `reli trace` on stuck PID → see where it's blocked
4. **Messenger backup**: `reli capture -P messenger` → flamegraph + memory to find bottleneck

### Auto-trigger

Triggers when user mentions: profiling, performance, slow, memory leak, stuck, hung, trace, flamegraph, debug worker, OOM.

---

## Layer 4: MCP Integration

### `bin/reli-mcp-bridge`

A host-side script that:
1. Checks profiler container is running
2. Finds latest `.rmem` under `.reli/latest/` (or accepts path as `$1`)
3. Runs `docker exec -i baander-profiler ./reli rmem:mcp /var/www/html/<path-to-snapshot>`
4. Bridges stdio between pi and the container's MCP server

### Pi MCP configuration

Configured as a stdio MCP server in pi settings. pi spawns the bridge on demand when memory analysis tools are needed.

### Available via MCP

- Query memory graph nodes and edges
- Find reference cycles
- Classify objects by type and retained size
- Suggest fix strategies for detected waste patterns

---

## Layer 5: `.reli/` Directory Convention

```
.reli/
├── latest → 2026-05-10T14-30-00_capture/
├── 2026-05-10T14-30-00_capture/
│   ├── manifest.json
│   ├── trace.rbt
│   ├── flamegraph.svg
│   ├── snapshot.rmem
│   └── report.txt
├── 2026-05-10T15-00-00_trace/
│   ├── manifest.json
│   └── trace.rbt
└── 2026-05-10T15-05-00_memory/
    ├── manifest.json
    ├── snapshot.rmem
    └── report.txt
```

- `.gitignore` entry: `.reli/`
- `manifest.json` enables scripts and skills to reason about captures without reading binary files

---

## Files to Create/Modify

| File | Action |
|------|--------|
| `bin/reli` | Create — shell wrapper |
| `bin/reli-mcp-bridge` | Create — MCP bridge script |
| `.claude/skills/reli/SKILL.md` | Create — pi/Claude skill |
| `Makefile` | Modify — add `prof-*` targets |
| `.gitignore` | Modify — add `.reli/` |
| `dev-docs/profiling.md` | Update — rewrite to point to `bin/reli` |

---

## Success Criteria

- `reli capture -P swoole` works as a single command from the host
- `make prof-top` shows a live view in one step
- pi skill can drive a full debug cycle: capture → analyze → report
- MCP tools available for AI-assisted memory analysis in pi sessions
- All output organized under `.reli/` with structured metadata
- No more than 2 seconds from thought to running profiler command
