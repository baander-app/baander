# Developer Setup

Set up a local Bånder development environment with isolated worktree previews, `.baander.test` DNS resolution, a trusted local certificate authority, and repeatable dependency checks.

| Guide | Scope |
|-------|-------|
| [Quick Start](quick-start.md) | Shortest path to local DNS, a trusted CA, and your first isolated worktree preview |
| [Development Environment](development-environment.md) | Host prerequisites, Makefile workflow, worktree previews, `dev.local.json` settings, media mounts, diagnostics, and runtime qualification |
| [PHP Dependency Maintenance](php-dependencies.md) | Composer projects and lockfiles, repeatable dependency checks, upstream blockers, and native pins |
| [Development Compose Stack](compose-stack.md) | The repository's `docker-compose.yml`: what it runs, why it is not an installation, and how to start it by hand |

The tracked `.env.example` is the shared default template. A local `.env` is
ignored by Git and excluded from image contexts, source snapshots and
qualification archives. Builds create the image's runtime `.env` from the
example, so local values do not become image defaults. Private runtime settings
still use the environment and worker configuration described in the guides.

A fresh checkout or linked worktree needs a local `.env` before its preview starts,
because the preview's application container loads it from the checkout; copy
`.env.example` first. Copy it the same way before application Composer hooks or
host PHP commands that need it. Keep an existing local
`.env` intact; copying the template is not an upgrade step. Preview workers use
their admitted runtime configuration rather than values captured from that file.
