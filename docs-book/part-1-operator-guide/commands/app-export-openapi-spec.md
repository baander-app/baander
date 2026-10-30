# app:export-openapi-spec

Export Baander's OpenAPI specification, or check that an existing export matches the current application. The repository tracks `openapi.json` as the input for web API client generation.

## Quick start

Export as JSON (default):

```bash
make exec cmd="php bin/console app:export-openapi-spec --output=/tmp/baander-openapi.json"
make exec cmd="cat /tmp/baander-openapi.json" > openapi.json
```

Export as YAML to a custom path:

```bash
make exec cmd="php bin/console app:export-openapi-spec --format yaml --output /tmp/baander-openapi.yaml"
make exec cmd="cat /tmp/baander-openapi.yaml" > docs/api.yaml
```

Check the tracked JSON without changing it:

```bash
make exec cmd="php bin/console app:export-openapi-spec --check"
```

## Options

| Option | Default | Description |
|--------|---------|-------------|
| `--output`, `-o` | `openapi.json` | File path to write the spec to |
| `--format`, `-f` | `json` | Output format: `json` or `yaml` |
| `--check` | off | Compare the existing output file with a fresh export; do not write files |

## Details

The preview mounts application source read-only. The examples export to a writable
temporary directory inside the container, then redirect the file to the host.

The command renders Nelmio ApiDoc's `default` area from route and OpenAPI attributes. The export covers application `/api` routes and excludes the generated documentation endpoint `/api/doc.json`. Health probes, `/metrics`, WebSocket paths and `/.well-known` endpoints are outside it. The output file is written relative to the working directory inside the container (usually `/var/www/html`).

The web [Orval](https://orval.dev/) configuration reads the tracked `openapi.json`. After exporting a changed contract, run `yarn generate` in `ui/web` and include both artifacts in the change. `yarn generate:check` regenerates the web client and fails if tracked generated files differ.

`--check` compares the existing file byte for byte with the selected format's fresh export. A missing, unreadable or different file fails the check. It does not create or replace the output file.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Specification exported, or the existing export matches in check mode |
| 1 | Invalid format, generation or writing failed, or check mode found a missing, unreadable or different export |

## Tips

- Keep the tracked `openapi.json` and generated web client in sync with API changes.
- Run this after adding or modifying API endpoints to keep your spec in sync.
- The YAML format is more readable for human review; JSON is better for tooling.
