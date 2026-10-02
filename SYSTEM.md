# Baander — Pi Quick Reference

Pi loads this quick reference. Read [AGENTS.md](AGENTS.md) for project instructions
and scoped coding rules; maintained skills live in `.agents/skills/`.

## Make commands
| Command | Action |
|---------|--------|
| `make start` / `make stop` | Docker env up/down |
| `make ssh` | Shell into app container |
| `make composer-install` | Install PHP deps |
| `./vendor/bin/phpunit` | Run tests (inside container) |
| `./vendor/bin/paratest --processes auto --tmp-dir var` | Parallel tests |

## Forgejo
- Connection: configured API base and repository; inspect the current Git remote.
- Token: supplied `FORGEJO_TOKEN` environment variable; do not source shell startup files.
- CI: Forgejo Actions (`.forgejo/workflows/`)
- Skill: [forgejo](.agents/skills/forgejo/SKILL.md)
