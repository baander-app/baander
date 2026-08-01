# app:generate-docs

Generate the documentation site from the source code and `docs-book/`. It scans bounded contexts, converts markdown pages, renders per-context HTML, and writes a CSS file and index page to the output directory.

## Quick start

```bash
make exec cmd="php bin/console app:generate-docs"
```

Write to a custom output directory:

```bash
make exec cmd="php bin/console app:generate-docs --output-dir public/docs"
```

## Options

| Option | Default | Description |
|--------|---------|-------------|
| `--output-dir`, `-o` | `docs/html` | Output directory for generated docs |
| `--phpdoc-dir` | `.phpdoc/build` | Path to phpDocumentor build output |
| `--source-dir`, `-s` | `src` | Path to source code |
| `--docs-book-dir` | `docs-book` | Path to docs-book markdown |

## Details

The command first cleans the output directory (recursively deleting its contents) and recreates it with `css/` and `contexts/` subdirectories. It then:

1. Scans the source directory for bounded contexts, routes, and domain models.
2. Converts markdown pages from the docs-book directory (skipped with a warning if the directory is missing).
3. Renders per-context documentation pages and writes the CSS.
4. Writes the index page summarizing all contexts.

If the source directory does not exist, the command fails immediately. If the phpDocumentor output directory is missing, it warns that phpDocumentor links will not work but continues.

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Documentation generated successfully |
| 1 | Source directory does not exist |

## Tips

- Run phpDocumentor first (`--phpdoc-dir` defaults to `.phpdoc/build`) if you want source-level cross-links.
- The output directory is wiped on every run — don't store anything else there.
- Defaults assume the command runs from the project root.
