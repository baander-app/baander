---
title: Rebuild the local CI image and run WebKit harnesses in the Playwright image
date: "2026-11-04"
category: developer-experience
module: Local verification gates and browser harnesses
problem_type: developer_experience
component: development_workflow
severity: medium
applies_when:
  - The local CI image baander-ci-prewarm:local is missing when running PHP gates or database-backed suites
  - "A self-contained Playwright harness under ui/web/tests/e2e must run in WebKit on a host that lacks WebKit's system libraries"
  - "make preview-up fails with No such image: sha256:..."
tags: [ci-image, playwright, webkit, docker, preview, local-gates, corepack]
---

# Rebuild the local CI image and run WebKit harnesses in the Playwright image

## Context

During the typography work three local gates were unavailable on the development host: the CI image used for PHP gates and database-backed suites was gone, WebKit could not launch on the host, and the worktree preview refused to start.

## Guidance

**CI image.** When `docker images` lists no `baander-ci-prewarm:local`, rebuild it from the current tree with the same builder the CI workflow uses:

```bash
php scripts/build-application.php --target ci --revision "$(git rev-parse HEAD)" --tag baander-ci-prewarm:local
```

Then run the gates with the read-only mount recipe and suite scripts (`BAANDER_TEST_IMAGE=baander-ci-prewarm:local bash scripts/test-functional-container.sh <paths>`). The functional script needs this image; without it, database-backed tests cannot run on the host.

**WebKit harness runs.** Host WebKit can fail to launch for missing system libraries (libicu74, libxml2.so.2, libflite, libjxl here). Run the harness in the Playwright image that matches the repo's `@playwright/test` version, with the repo mounted at the same path. The image's global Yarn is 1.x and the repo pins Yarn 4 through `packageManager`, so the harness's `yarn vite` web-server command fails; a shim that runs Vite directly avoids Corepack:

```bash
docker run --rm --ipc=host -v "$PWD:$PWD" -w "$PWD/ui/web" mcr.microsoft.com/playwright:v1.64.1-noble bash -c \
  'mkdir -p /tmp/shim && printf "#!/bin/sh\n[ \"\$1\" = vite ] && shift && exec node $PWD/node_modules/vite/bin/vite.js \"\$@\"\nexit 1\n" > /tmp/shim/yarn && chmod +x /tmp/shim/yarn && PATH=/tmp/shim:$PATH node node_modules/@playwright/test/cli.js test -c tests/e2e/typography/playwright.config.ts'
```

The container writes `ui/web/test-results/` as root; return it to your user afterwards (`docker run --rm -v "$PWD/ui/web/test-results:/r" <image> chown -R 1000:1000 /r`).

**Preview that cannot start.** `make preview-up` fails with `No such image: sha256:…` when the preview's saved release recorded a PostgreSQL or Redis image by digest that is no longer on the host; `make preview-status` then shows `phase: preparation_failed`. Existing data stores keep their recorded image identity only while the daemon still holds it (since 2026-11-10): when it is gone, preparation builds or pulls the role's image again and the preview starts on its retained data, provided the instance had been stopped with `make preview-down` first. The usual cause is a `docker system prune -a`, which also removes the previous release's other images. A preview that was still running when its release was pruned stops at a named refusal instead: its database still registers a worker boot that only the lost operator image could retire. A preview whose database applied migrations that were later rewritten stops at "An applied migration changed". Both of those need a data reset or a manual repair. A reset is not implied by lifecycle commands; get explicit approval before resetting preview data, and meanwhile rely on the self-contained harnesses, which need no preview.

## Applicability

Check `docker images` before planning a verification run: image loss explains all three failures, and only the preview cannot be repaired without a decision from the data's owner.
