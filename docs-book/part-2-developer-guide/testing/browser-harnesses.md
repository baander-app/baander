# Authentication and Typography Browser Harnesses

## Browser authentication transport

`yarn --cwd ui/web test:browser-auth` runs `ui/web/tests/e2e/browser-auth/`
against a scripted server on the loopback; it runs as a blocking step in the
frontend workflow. The Chromium project runs every scenario except the ones
tagged `@webkit`, with the named hosts `web.baander.app`, `api.baander.app` and
`foreign.baander.app` resolved to the loopback through a browser flag. The WebKit
project runs only the tagged scenarios, which check what WebKit's storage gives
back; WebKit has no such flag, so that project uses `localhost` and `127.0.0.1`
as two secure origins and skips the foreign host. These loopback names are the
one accepted exception to the `baander.app` rule in `AGENTS.md`. On a host
without a WebKit build, run the suite in the Playwright image with the
repository mounted and `node node_modules/@playwright/test/cli.js test -c
tests/e2e/browser-auth/playwright.config.ts` from `ui/web`.

The scripted server's refresh state machine (`refresh-state.ts`) answers refresh
tokens the way `/api/auth/refresh` does on PostgreSQL and is pinned to
`tests/Integration/RefreshTokenGraceTest.php`; it also answers logout, and an
outage mode makes refresh and logout answer `503` with `Retry-After` until the
test ends it. The suite checks that the service worker signs native image and
media requests with the page's DPoP credentials and sends none to a foreign
origin, that an expired token refreshes once and retries, and that a refresh
failure is bounded. The modes exercise these recovery paths:

- A nonce challenge receives one re-signed proof.
- A `503` with `Retry-After` keeps the session and retries after the delay.
- A lost refresh response is re-presented inside the grace window.
- Two tabs share one real credential store. The second tab adopts the pair the
  first rotated instead of presenting a consumed token.
- A service worker stopped through CDP pulls its credentials back from the page
  before signing the next request.

Pending-logout scenarios use the real auth store and check each delivery path:

- An offline logout ends the session at once and is sent exactly once when the
  browser comes back online.
- A logout during a server outage is kept and delivered on the next trigger once
  the outage ends.
- A reopened page whose entry's access token expired refreshes first, then logs
  out with the new token.
- An entry whose chain was revoked elsewhere is dropped after the logout `401`
  and the refresh refusal.
- Two pages of one origin send one logout for one entry.
- The stored key of a pending logout reads back after a reload and still signs a
  proof.

Mock proofs qualify the browser transport; they do not validate the server's DPoP
cryptography.

## Web typography

Run `yarn --cwd ui/web test:typography` for the typography harness at
`https://typography.baander.app:5188`, served with a temporary certificate and
mapped to loopback in Chromium, Firefox and WebKit. It renders the design-language
test strings in every type role, real song and column-browser rows, badges and
labels. It checks that Thai, Burmese, Hangul and CJK text has no letter-spacing,
that script line-heights and the Burmese size apply, that glyph ink stays inside
fixed rows, that the element's language picks the Han glyph family, and that every
character renders from a bundled Geist or Noto face. A Latin-only page checks that
no Thai, Burmese, Korean or CJK font file is requested. The frontend workflow runs
this gate. WebKit needs its Playwright system libraries; on hosts without them, run
the harness in the `mcr.microsoft.com/playwright` image. Port 5188 must be free.

`ui/web/tests/typography/census.test.ts` runs with the unit suite. It fails on
literal font sizes, light weights, literal tracking, families other than the two
stacks, and the 11px dense size outside the allowlisted admin tables, badges and
chips.
