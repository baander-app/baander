# Developer Setup

Set up a local Bånder development environment with isolated worktree previews, `.baander.test` DNS resolution, a trusted local certificate authority, and repeatable dependency checks.

| Guide | Scope |
|-------|-------|
| [Quick Start](quick-start.md) | Shortest path to local DNS, a trusted CA, and your first isolated worktree preview |
| [Development Environment](development-environment.md) | Host prerequisites, Makefile workflow, worktree previews, `dev.local.json` settings, media mounts, diagnostics, and runtime qualification |
| [PHP Dependency Maintenance](php-dependencies.md) | Composer projects and lockfiles, repeatable dependency checks, upstream blockers, and native pins |

The tracked `.env.example` is the shared default template. A local `.env` is
ignored by Git and excluded from image contexts, source snapshots and
qualification archives. Builds create the image's runtime `.env` from the
example, so local values do not become image defaults. Private runtime settings
still use the environment and worker configuration described in the guides.

If a fresh checkout needs a local `.env` for application Composer hooks or host
PHP commands, copy `.env.example` before running them. Keep an existing local
`.env` intact; copying the template is not an upgrade step. Preview workers use
their admitted runtime configuration rather than values captured from that file.
