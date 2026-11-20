# Public website

The website publishes the maintained `docs-book/` manuals with a static homepage,
shared design tokens and local Pagefind search. Its reference pages publish four
repository documents as they stand: `STRATEGY.md` at `/strategy/`, `CONCEPTS.md`
at `/concepts/`, `THIRD_PARTY.md` at `/licensing/` and `deploy/preset/README.md`
at `/deploy/`. The PHP builder writes a complete
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
features overview, each feature page, the Bånder Audio Core page, the reference
pages and every manual page at its `https://baander.app` URL, and never the 404
page. Each `<lastmod>` is the date of the newest commit that touched the page's
sources:

- **Manual page:** its Markdown file.
- **Reference page:** its source document, its data file in `ui/site/reference/`
  and `ui/site/templates/reference.php`; for the deploy preset page, the whole
  `deploy/preset/` directory, whose example files fill its comparison table.
- **Homepage and Bånder Audio Core page:** the template and the data it renders.
- **Feature page:** its data file, the feature templates, its pictures and the
  manual pages it borrows blocks from.

Uncommitted edits do not move these dates, and a page whose sources have no commit
yet is listed without one, so publish a build made from a commit.

Every page also carries its search and link-preview metadata, built by
`SearchMetadata` in the website tooling:

- **Title:** the page name, then its chapter or manual, then Bånder, for example
  `app:user:enable — CLI Commands — Bånder`. The middle part is dropped when the
  title would exceed 60 characters.
- **Description:** the page's opening paragraphs before its first subsection, as
  plain text cut at a word boundary within 155 characters. To change a page's
  search snippet, edit its opening paragraph.
- **Breadcrumbs:** the visible breadcrumb names the manual and, for a page in a
  fold-out chapter, that chapter.
- **Structured data:** the same trail appears as schema.org `BreadcrumbList` data,
  next to a `TechArticle` carrying the page's last commit date. The homepage
  describes the site and the software, including the ASCII spelling Baander.
- **Link previews:** Open Graph and Twitter tags point to `assets/social-card.png`.
  The card repeats the homepage headline and hero description, written into
  `scripts/social-card.mjs`, beside the homepage's hero picture, `library`. After
  changing the branding, that picture or that copy, rebuild and regenerate the card with
  `(cd ui/site && node scripts/social-card.mjs)`.

Manual text belongs in `docs-book/`. Homepage text and layout belong in
`ui/site/templates/`, and the text of the feature pages in `ui/site/features/`
(see [Feature pages](#feature-pages)). A reference page's body is its repository
document; the page's title, search description and the short opening shown under
its heading belong in its data file in `ui/site/reference/`. The top menu's titles
and one-line descriptions belong in `ui/site/menu.php`, except the feature pages,
which bring their own name and summary; the footer lists the same entries. Site
styles and the pictures of the application belong in `ui/site/assets/`. The build
copies shared token CSS, SVG branding and licensed fonts, then builds search from
the rendered manual and reference pages, labelling each result with its page.

A link in a manual or reference document to a manual page or to one of the four
published documents opens that page on the site, and the build fails when its
`#fragment` names no heading there. A link to any other repository path opens the
file in the source repository. For each rendered page it
also copies the Geist and Noto slices that the page's text reaches first along the
font stack, beyond the shared Latin faces, and links their rules from the page's
head. Search results get one such stylesheet for all indexed text, which the
search script links when it first shows results. Every page preloads the Latin
Geist face, and a page whose text reaches the mono stack preloads the Latin Geist
Mono face too. The published theme places fallback faces after each Geist family:
Arial or Liberation Sans for Geist, with a bold face for the heavier weights and a
Roboto pair behind them for Android, and Courier New or Liberation Mono for Geist
Mono, each scaled to Geist's advance width and line box, so text painted before the
fonts arrive keeps its width and line count. Change token inputs in `ui/design-tokens/`
and regenerate them with its existing build command rather than editing generated
CSS.

The Bånder Audio Core page at `/bac/` reads the recorded CSV and JSON results under
`packages/baander-audio-core/bench/results/`. Its charts and tables share the same
validated records: 55 quality configurations, 33 throughput observations and the
22 rows of the lossless run. The build checks the quality summaries against
their 550 selected per-item observations. It does not run codecs or download a
corpus.

The lossless table shows Bånder Audio Core at its default level 5 and at level 8 beside `flac`
at the same levels, FFmpeg's FLAC encoder at levels 5 and 8, and the FFmpeg and
Apple ALAC encoders. The build accepts exactly the rows the benchmark harness
writes: Bånder Audio Core and `flac` at levels 0 to 8 and the four other encoders, each once.
It refuses an unknown, missing or repeated row and a figure that is not positive,
with a message that names the row. Only the refalac row may lack speed figures,
because the harness does not time it. The run's date,
processor, compiler and reference versions come from the same file, and the
package version comes from `packages/baander-audio-core/package.json`. The build also
accepts the list of four references that `lossless.json` held before the harness
measured Bånder Audio Core; that list has no Bånder Audio Core rows and no run record. The tooling tests
read a made-up run in the harness's shape,
`packages/baander-dev-website/tests/Fixtures/aac-lossless-result.json`.

Capability and methodology text belongs in `ui/site/templates/bac.php`;
review it against the linked package documentation when the codec changes.
Bånder Audio Core source links identify the latest commit affecting the codec package in the
checkout, so website-only commits do not break those links in a local preview.
Measurement commits and dates identify the recorded experiments separately.

PHP examples use PHP's native tokenizer; other declared languages use Shiki.
Mermaid diagrams are rendered during the build, sanitized and packaged as light
and dark SVG images that embed the Latin Geist face. Code and diagrams remain
readable without JavaScript. The build uses the configured `tooling.budgets.website`
deadline, which defaults to 600 seconds; set it in `dev.local.json` when needed.

The pictures of the application are checked in under
`ui/site/assets/screenshots/`, so a website build does not need a running
application. [Pictures](#pictures) describes how they are taken.

Run the rendering fixtures and dedicated site browser suite after building:

```bash
(cd ui/site && node --test scripts/*.test.mjs)
(cd ui/site && corepack yarn test:browser)
```

The suite starts its own disposable static server. It uses a locally routed
`website.baander.app` origin and does not require the application preview. Only
taking pictures needs a preview; see [Pictures](#pictures).

## Feature pages

`/features/` is an overview, and each feature has a page below it: an opening, a
picture, a tour of two to four sections, a short guide, the feature's limits and
links into the manual. The templates hold the layout and the fixed headings; the
text of each page is data:

- `ui/site/features/index.php` lists the pages in order under their groups, and
  holds the overview's opening and its "More in Bånder" entries.
- `ui/site/features/<slug>.php` holds one page, published at `/features/<slug>/`.

`FeaturePages` in the website tooling states the fields of both files. To add a
page:

1. Copy an existing page file to `ui/site/features/<slug>.php` and rewrite it. The
   composed title, `<name> — Features — Bånder`, may be at most 60 characters long,
   and the description at most 155.
2. Add the slug to a group's `pages` in `index.php`. That order is the order of the
   overview and of each page's links to its neighbours.
3. Give each figure a picture or a block of the manual:
   - A picture is named as the capture script names it, with alternative text and
     a caption: `'picture' => 'player-queue', 'alt' => '…', 'caption' => '…'`.
   - A manual block names a manual file and a text that occurs in exactly one
     code block or diagram of it:
     `'manual' => 'part-2-developer-guide/…/x.md', 'contains' => '…'`. The page
     shows the block as the manual renders it, so it changes with the manual.
4. Run `make website-build`. The build reports every problem in the data files at
   once, each under the file that has it.
5. Run the browser suite. The specs read the list of feature pages from the built
   sitemap, so a new page is tested without a change to a spec.

Three rules apply to the text:

- **Every claim has a source.** A statement about what Bånder does must be stated
  by `README.md`, the operator guide or the developer guide. Correct the document
  first when it is wrong. The commit that adds or changes a page carries the list
  of its claims with the sentence and line that supports each.
- **Limits are on the page.** What the feature does not do goes under `limits`,
  and a feature that is not available yet appears only under `planned`.
- **Registers.** The [house style](../../.agents/skills/prose-fix/references/project-house-style.md)
  names the register of each part: the name, headline and lead use the Jobs
  register, and the tour sections and guide steps the Chen register.

`stability.spec.ts` paints every page in the fallback faces and in Geist. It fails
when a block of text takes a different number of lines, when a line of the first
screen breaks at a different word, or when a one-line link such as a `text-action`
differs in width by 2.5 % or more. A sentence that just fills its line, or a short
link label in the medium weight, can do that. Reword the text; do not change the
CSS.

## Pictures

`ui/site/scripts/capture/capture.mjs` takes every picture of the application on
the site. It signs in to a preview that holds a fictional sample library, does
what each feature page's guide tells a visitor to do, and takes each picture twice
from one state, in the dark and in the light theme. The pairs go to
`ui/site/assets/screenshots/` as `<name>.webp` and `<name>-light.webp`.

`ui/site/features/captures.json` records each picture: the scene that took it, the
newest commit of the application's source, the date and the SHA-256 of both files.
The build refuses a feature page that shows a picture without a record, or a file
that is not the recorded one, so a picture cannot be edited by hand or retaken in
one theme only.

The script needs ffmpeg with its `libwebp` encoder, Playwright's Chromium and
Firefox, and a worktree preview. It checks ffmpeg before it opens a browser. To
retake the pictures from an empty preview:

1. Generate the sample library: 12 artists, 15 albums and 92 tracks with tags and
   covers, none of them real.

   ```bash
   node ui/site/scripts/capture/sample-library.mjs var/site-capture/library
   ```

2. Make it the preview's only library. Write `dev.local.json` in the worktree's
   root with the absolute path of that directory:

   ```json
   {
       "libraries": [
           { "slug": "music", "name": "Music", "type": "music", "path": "/absolute/path/to/var/site-capture/library" }
       ]
   }
   ```

3. Start the preview, switch off the two lookups at outside services, and scan the
   library. Both settings are off by default, but a preview keeps what was set in it
   before, and with either one on the scan would send the sample names to
   MusicBrainz or LRCLIB:

   ```bash
   make preview-up
   make exec cmd="php bin/console app:settings:set metadata.auto_sync false"
   make exec cmd="php bin/console app:settings:set lyrics.auto_fetch false"
   make exec cmd="php bin/console app:library:scan music"
   ```

4. Delete the two development accounts a preview starts with. The script creates
   three sample accounts of its own and refuses a preview that holds any other:

   ```bash
   make exec cmd="php bin/console app:user:delete admin@baander.test --force"
   make exec cmd="php bin/console app:user:delete user@baander.test --force"
   ```

5. Take the pictures:

   ```bash
   (cd ui/site && corepack yarn playwright install chromium firefox)
   node ui/site/scripts/capture/capture.mjs --base-url "$(make preview-url)"
   ```

   `--scene <name>` runs one scene. `--out <directory>` writes a trial run and its
   record into that directory and leaves the site's files alone.

6. Look at every pair in `var/site-capture/contact-sheet.html`, then run
   `make website-build` and the browser suite.

Before the first picture the script checks the preview and stops when anything
differs:

- The catalog holds exactly the sample library's artists and albums, and the
  sample library is the only library.
- The accounts are exactly the three sample accounts.
- `metadata.auto_sync` and `lyrics.auto_fetch` are off, so that no sample name is
  sent to a metadata or lyrics provider. The scenes open no screen that asks one.
- No scan and no background job is unfinished.

The script resets and deletes nothing. It keeps the sample accounts' passwords in
`var/site-capture/accounts.json`, which is untracked; do not commit it. A scene
that needs state, such as a playlist or a device profile, looks for it by name
before it creates it, so a second run photographs the same state.

A window picture shows a 1136 × 710 window at device scale 2. The site's full
column is 1136 px wide, so text in the picture is never scaled up. A crop shows
one part of the window, and a page shows it at no more than half its pixel width.
The files are lossless WebP, compared pixel for pixel with what the browser drew;
lossy WebP blurs small interface text.

The playback destinations scene signs one account in to Chromium and Firefox and
expects the application to list them as "Chrome on Linux" and "Firefox on Linux".
On another operating system, change those two names in
`ui/site/scripts/capture/scenes/destinations.mjs`. After retaking `library`,
regenerate the link-preview card.

## CI artifact

The `Public website` workflow, which both forges run, checks token drift, builds the site and runs
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

   Previews sit behind Vercel's deployment protection, so a plain request is
   redirected to the login page. Fetch them through the logged-in CLI with
   `(cd ui/site && corepack yarn vercel curl <url> --scope martin-juuls-projects)`,
   passing curl options after `--`.

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
