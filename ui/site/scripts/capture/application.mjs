// What a capture does in the application's interface, written against the labels a visitor
// sees: opening a window, signing in and answering the question about which settings to keep,
// moving between pages, reading the catalog, starting, pausing and seeking playback, scrolling,
// setting sliders and choosing a theme. Nothing here intercepts a request, injects a style or
// script that changes what the application shows, or edits the page; the page is only read and
// operated.

// The size every picture is taken at: the website's content column, at twice the pixel density.
export const WINDOW = { width: 1136, height: 710 };
export const SCALE = 2;

// The theme names of the two variants of a picture, as the application's appearance settings
// label them and as it marks them on the page.
export const THEMES = {
  dark: { label: 'Dark', mood: 'dark' },
  light: { label: 'Balanced', mood: 'balanced' },
};

const COVER_REQUEST = /^\/api\/(images\/|(albums|artists)\/[^/]+\/cover$)/;
const PREFERENCE_READS = ['/api/user/audio-preferences/', '/api/user/player-preferences/', '/api/user/layout-preferences/'];

// How many times one call answers the question about the settings before it gives up.
const SETTINGS_ANSWERS = 5;

const pathOf = message => new URL(message.url()).pathname;

/**
 * Attaches a handler that does nothing to a promise and returns the same promise, so awaiting it
 * still throws. It is for a promise made before the action that fulfils it, such as the wait for
 * an answer to a press. When that action throws, nothing awaits the promise any more; it rejects
 * as the browser closes, and without a handler Node reports that rejection instead of the
 * step's own error.
 */
export function awaitedLater(promise) {
  promise.catch(() => {});
  return promise;
}

/**
 * Opens an application window of the picture size in a browser context of its own.
 *
 * The returned `covers` follows the window's cover requests without touching them: `pending`
 * holds the ones in flight, and `failed` the ones whose latest answer was an error, by the
 * address the application asked for.
 */
export async function openWindow(browser, baseURL, deviceScaleFactor = SCALE) {
  // The preview's certificate is signed locally, which no browser trusts by itself.
  const context = await browser.newContext({ baseURL, ignoreHTTPSErrors: true, viewport: WINDOW, deviceScaleFactor });
  const page = await context.newPage();
  const covers = { pending: new Set(), failed: new Map() };
  // A redirected request is answered under another address; the cover is the one first asked for.
  const asked = (request) => {
    let first = request;
    while (first.redirectedFrom()) first = first.redirectedFrom();
    return first;
  };
  page.on('request', (request) => {
    if (COVER_REQUEST.test(pathOf(asked(request)))) covers.pending.add(asked(request));
  });
  page.on('requestfinished', async (request) => {
    // Nothing awaits this listener. A window that closes while the answer is being read has no
    // answer to give, and Node would report that failure instead of the error that ended the run.
    try {
      const first = asked(request);
      if (!covers.pending.has(first)) return;
      const response = await request.response();
      if (response.status() >= 300 && response.status() < 400) return;
      covers.pending.delete(first);
      if (response.ok()) covers.failed.delete(pathOf(first));
      else covers.failed.set(pathOf(first), `answered ${response.status()}`);
    } catch {
      // The cover stays pending, which stops a picture of a window that is still open.
    }
  });
  page.on('requestfailed', (request) => {
    const first = asked(request);
    if (!covers.pending.delete(first)) return;
    // The application abandons the request of a cover that left the screen.
    if (request.failure()?.errorText !== 'net::ERR_ABORTED') covers.failed.set(pathOf(first), `failed: ${request.failure()?.errorText}`);
  });
  return { page, covers };
}

/**
 * Signs in through the login page and waits until the application has read the account's stored
 * preferences. When it then asks which version of the settings to keep, the stored one is chosen.
 */
export async function signIn(page, { email, password }) {
  await page.goto('/login', { waitUntil: 'domcontentloaded' });
  await page.getByLabel('Email', { exact: true }).fill(email);
  await page.getByLabel('Password', { exact: true }).fill(password);
  // A refused sign-in is reported below; the reads it never made must not be reported as well.
  const preferencesRead = awaitedLater(Promise.all(PREFERENCE_READS.map(path => page.waitForResponse(
    response => pathOf(response) === path && response.request().method() === 'GET',
  ))));
  // The first attempt may be answered with a nonce to prove possession with; the application retries by itself.
  const answered = awaitedLater(page.waitForResponse(async (response) => {
    if (pathOf(response) !== '/api/auth/login' || response.request().method() !== 'POST') return false;
    return !(response.status() === 400 && response.headers()['dpop-nonce']);
  }));
  await page.getByRole('button', { name: 'Log in', exact: true }).click();
  const response = await answered;
  if (response.status() === 429) {
    throw new Error(`The application limits sign-in attempts and refused ${email}; it accepts the next one in ${response.headers()['retry-after'] ?? 'an unknown number of'} seconds.`);
  }
  if (!response.ok()) {
    throw new Error(`The application refused to sign ${email} in (HTTP ${response.status()}).`);
  }
  await page.waitForURL(url => url.pathname === '/music');
  // The heading is found in the page itself: the question about the settings can open before the
  // home page is drawn, and while it is open the page behind it has no headings to find by role.
  await page.locator('h1').filter({ hasText: /^Home$/ }).waitFor();
  await preferencesRead;
  await keepStoredSettings(page, 1500);
}

/**
 * Answers the application's question about which version of the settings to keep, when it asks
 * within `patience` milliseconds, by choosing the one the account has stored. A window asks after
 * signing in, and a second window of an account may ask when the first changes a setting or
 * starts a song. A question that is asked again after it was answered five times stops the run.
 */
export async function keepStoredSettings(page, patience) {
  const question = page.getByRole('dialog').filter({ hasText: 'Settings conflict' });
  for (let answers = 0; ; answers += 1) {
    try {
      await question.waitFor({ timeout: patience });
    } catch {
      return;
    }
    if (answers === SETTINGS_ANSWERS) {
      throw new Error(`The "Settings conflict" dialog kept reappearing: it was answered ${SETTINGS_ANSWERS} times and opened again.`);
    }
    await question.getByRole('button', { name: 'Use server version', exact: true }).click();
    await question.waitFor({ state: 'hidden' });
  }
}

/**
 * Leaves the application as a visitor who goes to another address does, which lets it report
 * that the window no longer plays. A window that just disappears keeps hold of the account's
 * playback for a while, and a run started soon after could not start a song.
 */
export async function leave(page) {
  await page.goto('about:blank');
}

/** Follows a link in the sidebar, as a visitor moves between pages; the session stays signed in. */
export async function openFromSidebar(page, name, path) {
  await page.locator('aside').first().getByRole('link', { name, exact: true }).click();
  await page.waitForURL(url => url.pathname === path);
}

/** Follows the sidebar's Albums link and waits until the Albums page shows its heading. */
export async function openAlbums(page) {
  await openFromSidebar(page, 'Albums', '/music/albums');
  await page.getByRole('heading', { name: 'Albums', exact: true, level: 1 }).waitFor();
}

/** A card on the page, by its title. */
export function card(page, title) {
  return page.locator('main [data-slot="card"]').filter({ has: page.locator('[data-slot="card-title"]').getByText(title, { exact: true }) });
}

/**
 * Lists the catalog as the Songs page does for its artist and album columns, by reading the two
 * answers the page itself receives when it opens.
 */
export async function readCatalog(page) {
  const listing = path => page.waitForResponse((response) => {
    const url = new URL(response.url());
    return url.pathname === path && url.searchParams.get('limit') === '100' && response.request().method() === 'GET';
  });
  const answers = awaitedLater(Promise.all([listing('/api/artists/'), listing('/api/albums/')]));
  await openFromSidebar(page, 'Songs', '/music/songs');
  const [artists, albums] = await Promise.all((await answers).map(answer => answer.json()));
  return {
    artists: { total: artists.meta.total, names: artists.data.map(artist => artist.name) },
    albums: {
      total: albums.meta.total,
      entries: albums.data.map(album => ({ title: album.title, artists: album.artists.map(artist => artist.name) })),
    },
  };
}

/**
 * The application's answer to its next request with this method whose path matches `path`: a read
 * that a step goes by, or a change that `requireStored` then checks. It is asked for before the
 * press that sends the request and awaited after it.
 */
export function answerTo(page, method, path) {
  return awaitedLater(page.waitForResponse(response => path.test(new URL(response.url()).pathname) && response.request().method() === method));
}

/**
 * Stops the run unless the answer says that the application stored `what`. A change it refused
 * shows in the window at most, so the pictures of every later run would lack it.
 */
export async function requireStored(answer, what) {
  if (!(await answer).ok()) throw new Error(`The application did not store ${what}.`);
}

// The player bar, by the label the application gives it.
export const playerBar = page => page.getByLabel('Playback', { exact: true });

/**
 * Starts a song from its beginning on the Songs page and waits until it plays.
 *
 * A browser that closed while it played keeps hold of the account's playback; the hold is ended
 * first with the application's own "Play here anyway".
 */
export async function playSong(page, { album, title }) {
  await openFromSidebar(page, 'Songs', '/music/songs');
  await playHere(page);
  await page.getByRole('listbox', { name: 'Album', exact: true }).getByRole('option', { name: album }).click();
  await page.locator('main').getByText(title, { exact: true }).dblclick();
  await playerBar(page).getByRole('button', { name: 'Pause', exact: true }).waitFor();
  await playerBar(page).getByRole('group', { name: 'Now playing', exact: true }).getByText(title, { exact: true }).waitFor();
}

/** Whether the player bar shows the song as the one playing now. */
export async function isPlaying(page, title) {
  const bar = playerBar(page);
  return await bar.getByRole('button', { name: 'Pause', exact: true }).isVisible()
    && await bar.getByRole('group', { name: 'Now playing', exact: true }).getByText(title, { exact: true }).isVisible();
}

/** Pauses playback with the player bar's button. */
export async function pausePlayback(page) {
  await playerBar(page).getByRole('button', { name: 'Pause', exact: true }).click();
  await playerBar(page).getByRole('button', { name: 'Play', exact: true }).waitFor();
}

/**
 * Ends the hold that a browser which closed while it played keeps on the account's playback, with
 * the application's own "Play here anyway", on the page that is open. `playSong` does this on the
 * Songs page before it starts its song; a scene that starts playback elsewhere calls it first.
 * Without a hold it does nothing.
 */
export async function playHere(page) {
  const destination = playerBar(page).getByRole('button', { name: 'Playback destination', exact: true });
  if (await destination.count() > 0 && await destination.getAttribute('title') === 'Previous playback is unconfirmed.') {
    await destination.click();
    await page.getByRole('menuitem', { name: 'Play here anyway', exact: true }).click();
    await page.getByRole('menu').waitFor({ state: 'hidden' });
  }
}

/**
 * Waits until the song is the one in the player bar, playing or paused. The bar's title carries
 * the song's name as its tooltip; the album in the line below it may have the same name.
 */
export async function songInPlayer(page, title) {
  await playerBar(page).getByRole('group', { name: 'Now playing', exact: true }).getByTitle(title, { exact: true }).waitFor();
}

/**
 * Moves the song in the player bar to a position, in seconds, with one press on the seek bar, and
 * waits until the bar is there. The seek bar's keys are not used: after them the application
 * keeps showing the chosen position whatever plays next.
 */
export async function seekTo(page, seconds) {
  const thumb = playerBar(page).getByRole('slider', { name: 'Seek', exact: true });
  const bar = playerBar(page).locator('[data-slot="slider"]').filter({ has: page.getByRole('slider', { name: 'Seek', exact: true }) });
  const { x, y, width, height } = await bar.boundingBox();
  const length = Number(await thumb.getAttribute('aria-valuemax'));
  await page.mouse.click(x + width * seconds / length, y + height / 2);
  await page.waitForFunction(
    ([slider, wanted]) => Math.round(Number(slider.getAttribute('aria-valuenow'))) === wanted,
    [await thumb.elementHandle(), seconds],
  );
}

/** Closes the panel beside the page when it is open. */
export async function closeSidePanel(page) {
  const close = page.getByRole('button', { name: 'Close context panel', exact: true });
  if (await close.isVisible()) await close.click();
  await close.waitFor({ state: 'hidden' });
}

/** The panel beside the page, found by its Details tab. */
export function detailsPanel(page) {
  return page.getByRole('complementary').filter({ has: page.getByRole('tab', { name: 'Details', exact: true }) });
}

/** Closes the open menu with the Escape key and waits until it is gone. */
export async function closeMenu(page) {
  await page.keyboard.press('Escape');
  await page.getByRole('menu').waitFor({ state: 'hidden' });
}

/** Moves the pointer to the corner of the window, so that no control is drawn hovered in the picture that follows. */
export async function restPointer(page) {
  await page.mouse.move(0, 0);
}

/**
 * An album's card in a grid of covers, by its cover, so the card is there once the cover is drawn.
 * It is found by the picture's alternative text in the page itself: while a menu is open the
 * application hides the page behind it from the accessibility tree.
 */
export function albumCard(page, title) {
  // The Albums page draws a card as a button, an artist's page as a cell of a grid.
  return page.locator('main :is(button, [role="gridcell"])').filter({ has: page.locator(`img[alt="${title}"]`) });
}

/** Scrolls the page back to its top with the mouse wheel. */
export async function scrollToTop(page) {
  const shown = await page.locator('main').boundingBox();
  await page.mouse.move(shown.x + shown.width / 2, shown.y + shown.height / 2);
  await page.mouse.wheel(0, -100000);
  await page.waitForTimeout(150);
}

/**
 * Scrolls the page so that the elements are in view together, centred in the area the window
 * shows. It starts from the top of the page each time, so the same elements always land on the
 * same spot.
 */
export async function bringIntoView(page, ...locators) {
  await scrollToTop(page);
  const shown = await page.locator('main').boundingBox();
  const boxes = await Promise.all(locators.map(locator => locator.boundingBox()));
  const top = Math.min(...boxes.map(box => box.y));
  const bottom = Math.max(...boxes.map(box => box.y + box.height));
  await page.mouse.wheel(0, Math.round(top - shown.y - Math.max(0, (shown.height - (bottom - top)) / 2)));
  await page.waitForTimeout(150);
}

/**
 * Sets a slider to a value with the arrow keys, whatever it showed before, and takes the keyboard
 * focus off it again by clicking the text `rest`, which must do nothing when clicked.
 */
export async function setSlider(thumb, value, rest) {
  await thumb.focus();
  for (let presses = 0; presses < 200; presses += 1) {
    const now = Number(await thumb.getAttribute('aria-valuenow'));
    if (now === value) {
      await rest.click();
      return;
    }
    await thumb.press(now < value ? 'ArrowUp' : 'ArrowDown');
  }
  throw new Error(`The slider ${await thumb.getAttribute('aria-label')} did not reach ${value}.`);
}

/**
 * Chooses a theme for the account in a second window, on the Settings page's appearance buttons,
 * and waits until the photographed window has taken it over through the application's live
 * updates. The photographed window is not touched, so its view stays as it was.
 */
export async function chooseTheme(appearance, page, theme) {
  const { label, mood } = THEMES[theme];
  if (await page.locator('html').getAttribute('data-theme') !== mood) {
    await keepStoredSettings(appearance, 1);
    await appearance.getByRole('button', { name: `Select ${label} theme`, exact: true }).click();
    await page.waitForFunction(wanted => document.documentElement.getAttribute('data-theme') === wanted, mood);
  }
  // Colours that fade rather than switch have finished well within this time.
  await page.waitForTimeout(600);
}
