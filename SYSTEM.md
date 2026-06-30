# Baander — Pi Quick Reference

This file is auto-loaded by pi. CLAUDE.md contains the full rules; this is the cheat sheet.

## Make commands
| Command | Action |
|---------|--------|
| `make start` / `make stop` | Docker env up/down |
| `make ssh` | Shell into app container |
| `make composer-install` | Install PHP deps |
| `./vendor/bin/phpunit` | Run tests (inside container) |
| `./vendor/bin/paratest --processes auto --tmp-dir var` | Parallel tests |

## Forgejo
- API: `http://192.168.50.151:3000/api/v1/repos/martin/baander`
- Token: `FORGEJO_TOKEN` env var (from `~/.zshrc`)
- CI: Forgejo Actions (`.forgejo/workflows/`)
- Skill: `/forgejo`
