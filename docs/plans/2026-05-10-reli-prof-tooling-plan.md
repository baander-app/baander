# Plan: Reli Prof Tooling

## Problem summary

Every `reli-prof` invocation requires ~60 chars of docker compose boilerplate. PID discovery is manual. Multi-step workflows (trace→convert→view, memory dump→analyze→report) have no automation. The `rmem:mcp` AI-assisted memory analysis is untapped.

Requirements: [brainstorm artifact](../brainstorms/2026-05-10-reli-prof-tooling-requirements.md)

## Relevant learnings

No prior solutions found in `docs/solutions/` or global `~/.pi/agent/docs/solutions/`.

## Scope boundaries

**In scope:**
- `bin/reli` shell wrapper with 14 subcommands
- `bin/reli-mcp-bridge` for MCP stdio bridge
- `.claude/skills/reli/SKILL.md` project-scoped skill
- Makefile `prof-*` targets
- `.gitignore` update for `.reli/`
- `dev-docs/profiling.md` rewrite
- Cleanup of old profiling gitignore entries (replaced by `.reli/`)

**Out of scope:**
- Changes to the app container, Swoole config, or PHP code
- Automated CI profiling or benchmarking
- Changes to `vendor/reliforp/reli-prof`
- Persistent MCP daemon (bridge is on-demand)

## Implementation units

### Unit 1: `bin/reli` shell wrapper — core framework

**Goal:** Create the `bin/reli` script with shared infrastructure: sidecar check, PID discovery, output directory management, manifest generation, docker exec helper, and the `up`/`down`/`ps`/`ls`/`clean` subcommands.

**Files:**
- Create: `bin/reli`

**Patterns to follow:**
- Existing Makefile patterns for docker compose invocations (the `COMPOSE_BUILD_ARGS` style)
- Existing `bin/` scripts use plain PHP or shell
- Follow the `./reli` binary's own subcommand pattern

**Test scenarios:**
- `reli up` starts profiler sidecar
- `reli down` stops profiler sidecar
- `reli ps` lists Baander PHP processes with role labels
- `reli ls` lists captures in `.reli/`
- `reli clean` removes captures; `reli clean --all` wipes `.reli/`
- `reli <any>` errors clearly when profiler not running
- Output directory created with correct timestamp format
- `manifest.json` generated with correct schema
- `.reli/latest` symlink updated after each capture

**Verification:**
```bash
bin/reli up
bin/reli ps
bin/reli ls
bin/reli down
```

**Dependencies:** None.

---

### Unit 2: `bin/reli` — trace and flamegraph subcommands

**Goal:** Add `trace`, `flamegraph`, and `top` subcommands that capture traces to `.reli/` with proper output management.

**Files:**
- Modify: `bin/reli`

**Patterns to follow:**
- reli's `inspector:trace` takes `-p`, `-P`, `-o`, `-s`, `-d`, `-f rbt` options
- reli's `converter:flamegraph` reads from stdin
- Output format: `.rbt` for traces (binary, compressed), `.svg` for flamegraphs
- The `-o` flag with `.rbt` extension auto-selects rbt format

**Test scenarios:**
- `reli trace -p <pid>` captures to `.reli/<ts>_trace/trace.rbt`
- `reli trace -P swoole` auto-discovers PID and captures
- `reli trace -d 60 -p <pid>` captures for 60s
- `reli flamegraph -p <pid>` captures trace + converts to SVG
- `reli top` shows live aggregated view (interactive, no file output)
- `reli top -P swoole` filters to swoole processes
- manifest.json includes correct type, duration, files
- `.reli/latest` symlink points to new capture

**Verification:**
```bash
bin/reli up
bin/reli trace -P swoole -d 5
bin/reli flamegraph -P swoole -d 5
bin/reli top -P swoole  # ctrl+c to stop
bin/reli ls
cat .reli/latest/manifest.json
```

**Dependencies:** Unit 1.

---

### Unit 3: `bin/reli` — memory and leak-detect subcommands

**Goal:** Add `memory`, `leak-detect`, and `report` subcommands for memory analysis workflows.

**Files:**
- Modify: `bin/reli`

**Patterns to follow:**
- `inspector:memory:dump -p <pid> -o <file>` creates `.rdump`
- `inspector:memory:analyze <rdump> -f rmem -o <rmem>` converts to `.rmem`
- `inspector:memory:report <rmem>` generates text report
- `inspector:memory:compare <before.rmem> <after.rmem>` compares snapshots
- Two-step dump→analyze is the recommended production workflow

**Test scenarios:**
- `reli memory -p <pid>` dumps + analyzes to `.reli/<ts>_memory/snapshot.rmem`
- `reli memory -p <pid>` also generates `report.txt` automatically
- `reli leak-detect -p <pid>` takes two snapshots 30s apart, compares
- `reli report` generates report from `.reli/latest/`
- `reli report .reli/<specific-dir>` generates report from specified capture
- manifest.json includes snapshot and report file references

**Verification:**
```bash
bin/reli up
bin/reli memory -P swoole
bin/reli report
bin/reli leak-detect -P swoole -d 10
bin/reli ls
cat .reli/latest/manifest.json
```

**Dependencies:** Unit 1.

---

### Unit 4: `bin/reli` — capture, peek, and explore subcommands

**Goal:** Add `capture` (trace + memory combo), `peek` (variable inspection), and `explore` (TUI) subcommands.

**Files:**
- Modify: `bin/reli`

**Patterns to follow:**
- `capture` runs trace and memory sequentially against same PID
- `inspector:peek-var -p <pid> --var='<expr>'` reads variables
- `rbt:explore <file>` and `rmem:explore <file>` open TUIs
- TUI commands need interactive terminal (`docker exec -it`)

**Test scenarios:**
- `reli capture -p <pid> -d 10` creates trace + flamegraph + memory in one dir
- `reli capture -P swoole` auto-discovers PID
- `reli peek 'global::$counter' -p <pid>` reads a variable
- `reli explore` opens TUI for latest capture (auto-detects trace vs memory)
- `reli explore .reli/<dir>` opens TUI for specified capture
- manifest.json lists all generated files

**Verification:**
```bash
bin/reli up
bin/reli capture -P swoole -d 5
bin/reli ls
cat .reli/latest/manifest.json
```

**Dependencies:** Units 2, 3.

---

### Unit 5: Makefile targets and `.gitignore`

**Goal:** Add Makefile shortcuts and update gitignore for the new `.reli/` convention.

**Files:**
- Modify: `Makefile`
- Modify: `.gitignore`

**Patterns to follow:**
- Existing Makefile pattern: `ifeq ($(INSIDE_DOCKER_CONTAINER), 0)` guard
- Existing `## comment` doc format for `make help`
- Consistent with other make targets (build, start, stop, etc.)

**Test scenarios:**
- `make prof-up` starts profiler sidecar
- `make prof-down` stops profiler sidecar
- `make prof-top` shows live view
- `make prof-trace PID=1234` captures trace
- `make prof-memory PID=1234` takes memory snapshot
- `make prof-flamegraph PID=1234` generates flamegraph
- `make help` lists new prof-* targets
- `.reli/` is gitignored
- Old profiling entries (`traces`, `*.rbt`, etc.) replaced by `.reli/`

**Verification:**
```bash
make prof-up
make prof-top  # ctrl+c
make prof-down
make help | grep prof
git status  # .reli/ not shown
```

**Dependencies:** Unit 1.

---

### Unit 6: `bin/reli-mcp-bridge`

**Goal:** Create the MCP bridge script that allows pi to use reli's `rmem:mcp` tools for AI-assisted memory analysis.

**Files:**
- Create: `bin/reli-mcp-bridge`

**Patterns to follow:**
- MCP stdio protocol: JSON-RPC over stdin/stdout
- `rmem:mcp --rmem=<path>` starts a stdio MCP server
- Must run inside profiler container where reli binary is available
- Path must be container-relative (`/var/www/html/.reli/...`)

**Test scenarios:**
- Bridge finds latest `.rmem` under `.reli/latest/`
- Bridge accepts explicit path as `$1`
- Bridge errors if no `.rmem` found
- Bridge errors if profiler container not running
- Bridge passes stdio through to `docker exec -i`
- Container path translation (host `.reli/...` → container `/var/www/html/.reli/...`)

**Verification:**
```bash
# After a memory capture:
bin/reli up
bin/reli memory -P swoole
echo '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2024-11-05","capabilities":{},"clientInfo":{"name":"test","version":"0.1"}}}' | bin/reli-mcp-bridge
```

**Dependencies:** Unit 3 (needs memory captures to exist).

---

### Unit 7: `.claude/skills/reli/SKILL.md` and `dev-docs/profiling.md`

**Goal:** Create the project-scoped pi/Claude skill and rewrite the profiling docs.

**Files:**
- Create: `.claude/skills/reli/SKILL.md`
- Modify: `dev-docs/profiling.md`

**Patterns to follow:**
- Existing skill format: YAML frontmatter (`name`, `description`), then markdown body
- See `.claude/skills/forgejo/SKILL.md` for structure reference
- Skill auto-triggers on keywords (profiling, debug, performance, memory, trace, flamegraph)
- `dev-docs/profiling.md` should point to `bin/reli` as primary interface

**Test scenarios:**
- Skill file has correct frontmatter and description
- Skill documents all `bin/reli` subcommands
- Skill includes scenario guides (slow endpoint, memory leak, stuck worker, messenger backup)
- Skill knows Baander's process architecture
- Skill references MCP bridge when available
- `dev-docs/profiling.md` rewritten to use `bin/reli` commands
- Old manual `docker compose` examples removed from docs

**Verification:**
```bash
cat .claude/skills/reli/SKILL.md
cat dev-docs/profiling.md
```

**Dependencies:** Units 1–6 (documents the complete tooling).

---

## Execution order

Units 1–4 are sequential (the shell wrapper is built incrementally). Units 5 and 6 can be done in parallel with the later wrapper units. Unit 7 is last.

```
Unit 1 (core framework)
├── Unit 2 (trace/flamegraph)
│   └── Unit 4 (capture/peek/explore) ← needs 2+3
├── Unit 3 (memory/leak-detect)
│   └── Unit 4 (capture/peek/explore)
├── Unit 5 (Makefile + gitignore) ← can start after Unit 1
└── Unit 6 (MCP bridge) ← needs Unit 3 for .rmem files
    └── Unit 7 (skill + docs) ← needs everything
```

Parallel-safe groups:
- **Group A:** Unit 1 (must go first)
- **Group B:** Units 2, 3, 5 (all depend only on Unit 1, independent of each other)
- **Group C:** Units 4, 6 (depend on Units 2/3)
- **Group D:** Unit 7 (depends on all)

## Verification strategy

**Targeted:** Each unit has its own verification commands above.

**Broader end-to-end:**
```bash
# Full cycle test
make prof-up
bin/reli ps
bin/reli capture -P swoole -d 5
bin/reli ls
bin/reli report
bin/reli explore  # ctrl+c to exit
cat .reli/latest/manifest.json
make prof-down
```
