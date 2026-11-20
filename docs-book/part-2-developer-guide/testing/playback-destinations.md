# Playback destination qualification

Use these browser specs to check close reports, destination override and local recovery across tabs. The [two-command lifecycle drill](playback-lifecycle.md) uses a single page.

## Playback Destination Specs

The close report and "Play here anyway" have Playwright specs under
`ui/web/tests/e2e/playback-destinations/`. `close-report.spec.ts` and
`override.spec.ts` drive two browser contexts of one account against the
worktree preview. Each context has its own storage, so it shares no tab identity,
lock or local recovery record with another one, and only the session connects
them. The close report and the
override are proved here rather than in the two-command checkpoint, which has one
page and no way to let a registration lapse.

```bash
make preview-up
E2E_BASE_URL="$(make preview-url)" yarn --cwd ui/web e2e:headless \
  tests/e2e/playback-destinations/close-report.spec.ts \
  tests/e2e/playback-destinations/override.spec.ts \
  tests/e2e/playback-destinations/local-recovery.spec.ts
```

Each test creates test-owned accounts and media through the managed fixture
executor of the [live-update capacity scenario](live-updates.md), plays the
fixture song `Live song 00000` on one of those accounts, and cleans its fixture up
afterwards. The helper `session-tabs.ts` opens a named context, signs it in,
starts the song and records every session `POST` of the context with its
answer; each test attaches that record as `session-exchanges`, with its receipt.

Two contexts of one account would otherwise conflict over a preference. Every
tab that shows the session's track selects that track in its context panel, and
the selection writes the account's layout preference. The second context's write
then conflicts with the first, and the conflict dialog is modal: it hides the
destination control and the player from the rest of the test. The helper
therefore answers each preference write of its context itself, with the version
the write expected, so the server stores no preference version for a later
write to conflict with. Preference reads still reach the server; preference
synchronization has its own tests.

`close-report.spec.ts` has three cases:

- A playing tab leaves its page by navigating away, which dispatches `pagehide`.
  Within 8 seconds, far inside the 30-second registration lapse, the other tab's
  snapshot must show no runner, the session paused on the same media past its
  first second, and the closed tab's destination unavailable. The other tab's Play
  must then start at once, with no refusal, no failed request and no request to
  `/api/session/override` or `/api/session/recover-local`.
- A playing tab is closed with `page.close()`. Whether the engine dispatched
  `pagehide` and the report released the session within the same 8 seconds is
  recorded as `pagehideReportedTheClose`, not assumed. With a report, the other
  tab plays at once; without one, the case is the crash case, and the other tab
  must offer an enabled "Play here anyway" once the registration has lapsed.
- A context opened after the playing tab left must receive registration answers
  that all show no runner and the session paused on the same media, and its Play
  must start at once.

`override.spec.ts` has two cases:

- A playing tab stops reporting presence, as a killed browser would, by aborting
  its `/api/session/observe` requests while its page keeps playing. Once its
  registration has lapsed, the other tab's song selection is refused by the tab
  itself, without a control request. The menu must show the status line and an
  enabled "Play here anyway"; the override must answer 200 with the outcome
  `overridden` and close the menu, and the refused track must start without
  another press. The overridden tab's audio element must be paused within ten
  seconds and stay paused while the other tab plays on.
- A playing tab is taken offline with `setOffline`. Once its registration has
  lapsed, the other tab selects the song, is refused, and overrides the offline
  tab; the refused song then plays. The only assertion about the offline tab
  is that its element is paused once it is back online, while the session still
  names the other tab. The receipt records whether the offline tab's socket
  closed, how long its element kept playing and which rule silenced it.

`local-recovery.spec.ts` keeps checking local recovery after a reload, a tab
close and renderer destruction, that a copied tab takes a tab identity of its
own, and the renewal after a presence lapse.

The configuration defines no projects, so these specs run in Chromium, and
Chromium is the engine that binds them. They are written engine-neutral; a
Firefox run with `--browser=firefox` is attempted and its result recorded, and no
gate depends on it. None of them proves that a closed or reloaded page's element
is silent, because nothing of the page is left to inspect, and none drives a
socket replaced without a close; the coordinator's unit tests and the PostgreSQL
integration tests prove the succession. Every run of `e2e:headless` rewrites the
tracked `ui/web/playwright-report/index.html`, so restore that file before you
commit unless the change is intended.

