# Public website

The website publishes the maintained `docs-book/` manuals with a static homepage,
shared design tokens and local Pagefind search. The PHP builder writes a complete
artifact to `var/website/`, including `.vercel/output/` for Vercel. Serving the site
requires no application server, account, database or worker deployment.

## Build and inspect locally

Run these commands from the repository root with PHP 8.5 or newer, DOM, mbstring,
tokenizer, Composer 2.11.1, Node.js 24 and Corepack available:

```bash
composer --working-dir=scripts install --no-dev --no-interaction --no-scripts --no-plugins
(cd ui/site && corepack yarn install --immutable)
(cd ui/site && corepack yarn playwright install --with-deps chromium)
make website-build
make website-preview
```

Open `http://localhost:4177`. Stop the preview with Ctrl-C. The preview serves the
built files; rebuild after editing a manual, template or asset. A failed build
keeps the previous complete artifact. Build output is disposable; do not keep
credentials or other personal files under `var/website/`.

Manual text belongs in `docs-book/`. Homepage text and layout belong in
`ui/site/templates/`; site styles and reviewed screenshots belong in
`ui/site/assets/`. The build copies shared token CSS, SVG branding and licensed
fonts, then builds search from the rendered manual pages. Change token inputs in
`ui/design-tokens/` and regenerate them with its existing build command rather than
editing generated CSS.

The bAAC page at `/baac/` reads the recorded CSV and JSON results under
`packages/baander-aac/bench/results/`. Its charts and tables share the same
validated records: 55 quality configurations, 33 throughput observations and four
separate lossless references. The build checks the quality summaries against
their 550 selected per-item observations. It does not run codecs or download a
corpus. Capability and methodology text belongs in `ui/site/templates/baac.php`;
review it against the linked package documentation when the codec changes.
bAAC source links identify the latest commit affecting the codec package in the
checkout, so website-only commits do not break those links in a local preview.
Measurement commits and dates identify the recorded experiments separately.

PHP examples use PHP's native tokenizer; other declared languages use Shiki.
Mermaid diagrams are rendered during the build, sanitized and packaged as light
and dark SVG images with embedded Inter fonts. Code and diagrams remain readable
without JavaScript. The build uses the configured `tooling.budgets.website`
deadline, which defaults to 600 seconds; set it in `dev.local.json` when needed.

The music browsing and playback screenshot was captured from an isolated
application preview on 2026-10-31, after the user requested library scans. The user
reviewed and approved that image. Additional captures from the same date show the
graphic equalizer, parametric controls, audio processing, A/B comparison and device
profiles, and playlists. The playlist capture uses a demonstration playlist with
eight real tracks, created only in the isolated preview. Dark and light variants
were recaptured from matching paused views: each pair retains the same items,
playback position, controls and scroll position. The processing slide captures
the complete processing panel. Normal website builds reuse these checked-in
images and do not need a running application.

Run the rendering fixtures and dedicated site browser suite after building:

```bash
(cd ui/site && node --test scripts/*.test.mjs)
(cd ui/site && corepack yarn test:browser)
```

The suite starts its own disposable static server. It uses a locally routed
`website.baander.app` origin and does not require the application preview. Real
application screenshot capture uses the separate worktree preview described in
the [development environment guide](../../docs-book/part-2-developer-guide/development-environment.md).

## CI artifact

The `Public website` Forgejo workflow checks token drift, builds the site and runs
the site browser suite in the repository's PHP 8.5 runner image. It retains
`website.tar.gz` and its SHA-256 checksum for 14 days under an artifact named
`public-website-<commit>`. The archive includes the hidden `.vercel/output/`
directory. Existing backend CI owns the tooling PHPUnit and PHP quality gates.

After downloading an artifact, place both files under `var/` in a checkout of the
same commit. Verify the checksum before extracting it into an empty
`var/website/` directory:

```bash
sha256sum --check var/website.tar.gz.sha256
mkdir -p var/website
tar -xzf var/website.tar.gz -C var/website
```

Keep the extraction directory empty before this procedure so files from an older
build cannot survive. Install the site's locked Yarn dependencies to use its
pinned Vercel CLI; the artifact itself does not need another build.

## Publish to Vercel

This procedure needs a Vercel account with access to the intended project and
control of `baander.app` DNS. Project creation, account login, domain connection
and live deployment are user-operated steps; CI only retains the artifact.

Publish an artifact built from a commit available in the public GitHub repository.
Manual source links point to that exact commit; they can return 404 in a local
preview built from an unpushed branch. The general Source links open the repository
home page.

1. Build locally or extract the verified CI artifact. Log in with the pinned CLI,
   then link the artifact directory to the intended Vercel project:

   ```bash
   (cd ui/site && corepack yarn vercel login)
   (cd ui/site && corepack yarn vercel link --cwd ../../var/website)
   ```

   Inspect the selected team and project before completing the prompts. Linking
   writes local project metadata under `var/website/.vercel/`. Rebuilds replace
   the artifact directory, so repeat linking after a rebuild or extraction.

2. Upload the existing build as a preview:

   ```bash
   (cd ui/site && corepack yarn vercel deploy --cwd ../../var/website --prebuilt)
   ```

   Inspect the returned preview URL. Check the homepage, a direct nested manual
   URL, a section fragment, a real search result and an unknown URL returning HTTP
   404. Inspect light and dark presentation and narrow-screen navigation. Links
   stay on the preview origin; canonical metadata names `https://baander.app`.

3. Connect `baander.app` to the selected project in Vercel's domain settings and
   apply the DNS records Vercel supplies. After the preview checks pass and the
   intended project is confirmed, publish the same artifact:

   ```bash
   (cd ui/site && corepack yarn vercel deploy --cwd ../../var/website --prebuilt --prod)
   ```

   Repeat the URL, search and 404 checks over `https://baander.app` and confirm
   valid HTTPS. Record the deployment URL and checks in the delivery checkpoint.

Do not commit Vercel credentials or project metadata. This handoff procedure has
not been exercised against a Vercel project; successful local and CI builds do
not establish that the public domain is live.
