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

Each build also writes `sitemap.xml` and `robots.txt` at the site root, so the
sitemap follows every change without a separate step. It lists the homepage, the
bAAC page and every manual page at its `https://baander.app` URL, and never the
404 page. Each `<lastmod>` is the date of the newest commit that touched the
page's sources: the Markdown file for a manual page, and the template plus the
data it renders for the homepage and the bAAC page. Uncommitted edits do not move
these dates, and a page whose sources have no commit yet is listed without one, so
publish a build made from a commit.

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

The playback destinations screenshot was captured from an isolated application
preview on 2026-11-04. A disposable live-updates fixture run supplied one user and
twelve silent tracks; the run was cleaned up after capture. Four real browser
sessions signed in as that user under the device names Studio MacBook, Living
room, Kitchen iPad and iPhone. The captured session played a track, transferred it
to Living room through the destination menu, then sought and paused it remotely,
so the menu shows the real state after a transfer. Only the display metadata is
staged: the capturing browser replaced the fixture's track, artist, album and
genre names with fictional ones and served generated cover art. The light
variant uses the same paused view with the application theme switched. Both use
a 1136 × 710 viewport at device scale 2, which matches the full-width homepage
column, so the image appears at its captured size with no downscaled text. AirPlay,
Chromecast and DLNA are not shown because they are not available yet; the
homepage marks them as planned. The user reviewed and approved both images.

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

The production project is `martin-juuls-projects/baander`. Its Vercel hostname is
`https://baander.vercel.app`; `baander.app` is attached to the same project.

Publish an artifact built from a commit available in the public GitHub repository.
Manual source links point to that exact commit; they can return 404 in a local
preview built from an unpushed branch. The general Source links open the repository
home page.

1. Build locally or extract the verified CI artifact. Log in with the pinned CLI,
   then link the artifact directory to the intended Vercel project:

   ```bash
   (cd ui/site && corepack yarn vercel login)
   (cd ui/site && corepack yarn vercel link --cwd ../../var/website --scope martin-juuls-projects --project baander --yes)
   ```

   Confirm the team and project in the command before running it. Linking
   writes local project metadata under `var/website/.vercel/`. Rebuilds replace
   the artifact directory, so repeat linking after a rebuild or extraction.
   Linking can also create `var/website/.env.local` with an authentication token;
   keep that file out of shared archives. The prebuilt deployment uploads the
   generated build output.

2. Upload the existing build as a preview:

   ```bash
   (cd ui/site && corepack yarn vercel deploy --cwd ../../var/website --prebuilt)
   ```

   Inspect the returned preview URL. Check the homepage, a direct nested manual
   URL, a section fragment, a real search result and an unknown URL returning HTTP
   404. Inspect light and dark presentation and narrow-screen navigation. Links
   stay on the preview origin; canonical metadata names `https://baander.app`.
   Vercel assigns a project's first deployment to production even without
   `--prod`; subsequent deployments use preview unless production is requested.

3. Connect `baander.app` to the selected project in Vercel's domain settings and
   apply the DNS records Vercel supplies. After the preview checks pass and the
   intended project is confirmed, publish the same artifact:

   ```bash
   (cd ui/site && corepack yarn vercel deploy --cwd ../../var/website --prebuilt --prod)
   ```

   Repeat the URL, search and 404 checks over `https://baander.app` and confirm
   valid HTTPS. Record the deployment URL and checks in the delivery checkpoint.

Do not commit Vercel credentials or project metadata. The prebuilt publication
flow was exercised on 2026-10-31 with revision
`c89470b054e4996a952a11c8a73bb1e48cdcf39a`. Both `https://baander.app` and the
Vercel hostname passed live page, redirect, search, fragment, theme, mobile and
404 checks with valid HTTPS. The delivery checkpoint is recorded in `ROADMAP.md`.

When moving a domain to Vercel nameservers, check for an old DNSSEC DS record at
the registrar. During the initial publication, `baander.app` had an old DS record
but no matching key in its Vercel DNS zone. Validating resolvers returned
`SERVFAIL` until the owner removed that record in Spaceship's Advanced DNS
settings. `vercel domains verify baander.app --scope martin-juuls-projects`
then confirmed the domain configuration; certificate issuance followed separately.
