# app:export-openapi-spec

Export Baander's OpenAPI specification to a file. Useful for generating API client SDKs or sharing the API definition externally.

## Quick start

Export as JSON (default):

```bash
make exec cmd="php bin/console app:export-openapi-spec"
```

Export as YAML to a custom path:

```bash
make exec cmd="php bin/console app:export-openapi-spec --format yaml --output docs/api.yaml"
```

## Options

| Option | Default | Description |
|--------|---------|-------------|
| `--output`, `-o` | `openapi.json` | File path to write the spec to |
| `--format`, `-f` | `json` | Output format: `json` or `yaml` |

## Details

The command collects all routes, controllers, and OpenAPI attributes from the codebase and renders the full specification. The output file is written relative to the working directory inside the container (usually `/var/www/html`).

If you're using [orval](https://orval.dev/) or similar tools to generate API clients, point them at the exported JSON file.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Spec exported successfully |
| 1 | Invalid format or generation failed |

## Tips

- Export to `openapi.json` and commit it to the repo to track API changes over time.
- Run this after adding or modifying API endpoints to keep your spec in sync.
- The YAML format is more readable for human review; JSON is better for tooling.
