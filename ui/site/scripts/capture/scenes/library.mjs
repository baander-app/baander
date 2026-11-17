// The pictures of the music library page, taken as the listener. The steps follow the page: the
// home page with what was played recently, the Albums page, one album's details, the Songs page
// with a genre chosen and the search box. Every choice the pictures show is made here, and the
// listening history the home page needs is added only when it is not there yet, so a later run
// photographs the same state.
import { albumCard, closeSidePanel, openFromSidebar, pausePlayback, playSong, seekTo } from '../application.mjs';
import { buildManifest } from '../library-manifest.mjs';

// The songs the home page's "Recently Played" row begins with, the newest first: one from each of
// six albums, so the row shows six covers. The window shows five cards and part of the sixth.
const RECENT = [
  { album: 'Paper Satellites', title: 'Orbiting Home' },
  { album: 'Slow Weather', title: 'Second Sunday' },
  { album: 'Neon Cartography', title: 'Grid North' },
  { album: 'Overgrown', title: 'Fallow Year' },
  { album: 'Sunday Engine', title: 'Marigold Hour' },
  { album: 'Midnight Transit', title: 'Platform Glow' },
];
// Where the newest of them is left paused, in seconds, so the player bar shows a position.
const POSITION = 72;
// The first two rows of the Albums page as it opens: sorted by name, five covers in a row.
const FIRST_TWO_ROWS = buildManifest().albums.map(album => album.title).toSorted().slice(0, 10);
// An album whose four tracks and "Open" button fit in the panel without scrolling.
const OPENED_ALBUM = 'Winter Letters';
const GENRE = 'Folk';
// The last of that genre's songs, by title, that the list shows whole above the player bar.
const LAST_WHOLE_SONG = 'Kestrel Over the Low Field';
// A few letters that match an artist, an album and two songs, which the box lists without scrolling.
const SEARCH = 'stat';

const row = (page, title) => page.locator('main section').filter({ has: page.getByRole('heading', { name: title, exact: true }) });
const column = (page, title) => page.getByRole('listbox', { name: title, exact: true });
const panel = page => page.getByRole('complementary').filter({ has: page.getByRole('tab', { name: 'Details', exact: true }) });
const searchBox = page => page.getByRole('dialog').filter({ has: page.getByPlaceholder('Search albums, artists, songs...') });

const isPlayRecord = response => new URL(response.url()).pathname === '/api/activity/play' && response.request().method() === 'POST';
const isHistoryRead = response => new URL(response.url()).pathname === '/api/activity/history' && response.request().method() === 'GET';

// Plays a song until the application has recorded the play, then pauses it. A play counts as
// soon as the song starts; how long it runs makes no difference.
async function listenTo(page, song) {
  const recorded = page.waitForResponse(isPlayRecord);
  await playSong(page, song);
  if (!(await recorded).ok()) throw new Error(`The application did not record that ${song.title} was played.`);
  await pausePlayback(page);
}

/**
 * Plays a song, opens the home page and returns the titles of the listening history, newest
 * first, from the application's first answer about it after it recorded the play. The home page
 * may show an older answer until then, so the row itself does not say what the history holds.
 */
async function historyAfterListeningTo(page, song) {
  let recorded = false;
  let answer = null;
  const follow = (response) => {
    if (isPlayRecord(response)) recorded = response.ok();
    else if (recorded && isHistoryRead(response)) answer = response;
  };
  page.on('response', follow);
  try {
    await playSong(page, song);
    await pausePlayback(page);
    await openFromSidebar(page, 'Home', '/music');
    for (let waited = 0; !answer; waited += 100) {
      if (waited >= 30000) {
        throw new Error(recorded ? 'The application did not read the listening history again after a play.' : `The application did not record that ${song.title} was played.`);
      }
      await page.waitForTimeout(100);
    }
    return (await answer.json()).data.map(entry => entry.songTitle);
  } finally {
    page.off('response', follow);
  }
}

export default {
  name: 'library',
  account: 'maja@baander.app',
  steps: [
    {
      // The row lists the ten songs played last, newest first, one card for each song. The newest
      // one is played in any case, which puts it in the player bar; the others are played before
      // it only when the history does not already hold them behind it.
      title: 'Listen to the newest of the recent songs, and to the others unless they are already behind it',
      run: async ({ page }) => {
        const history = await historyAfterListeningTo(page, RECENT[0]);
        if (RECENT.every((song, place) => history[place] === song.title)) return;
        for (const song of [...RECENT.slice(1).toReversed(), RECENT[0]]) await listenTo(page, song);
      },
    },
    {
      title: 'Leave it paused part-way',
      run: ({ page }) => seekTo(page, POSITION),
    },
    {
      title: 'Go home',
      run: async ({ page }) => {
        await openFromSidebar(page, 'Home', '/music');
        await closeSidePanel(page);
        const played = row(page, 'Recently Played').getByRole('button');
        for (const [place, song] of RECENT.entries()) {
          await played.nth(place).getByText(song.title, { exact: true }).waitFor();
          await played.nth(place).getByRole('img', { name: song.album, exact: true }).waitFor();
        }
        await row(page, 'Recently Added').getByRole('img').nth(RECENT.length - 1).waitFor();
      },
    },
    {
      picture: 'library',
      kind: 'window',
    },
    {
      title: 'Open Albums',
      run: async ({ page }) => {
        await openFromSidebar(page, 'Albums', '/music/albums');
        await page.getByRole('heading', { name: 'Albums', exact: true, level: 1 }).waitFor();
        await closeSidePanel(page);
        for (const album of FIRST_TWO_ROWS) await albumCard(page, album).waitFor();
      },
    },
    {
      // The heading with the sort menu, the row of genres and the first two rows of covers.
      picture: 'library-albums',
      kind: 'crop',
      region: ({ page }) => [
        page.getByRole('heading', { name: 'Albums', exact: true, level: 1 }).locator('xpath=..'),
        albumCard(page, FIRST_TWO_ROWS.at(-1)),
      ],
    },
    {
      title: 'Look inside an album',
      run: async ({ page }) => {
        await albumCard(page, OPENED_ALBUM).click();
        await panel(page).getByRole('img', { name: OPENED_ALBUM, exact: true }).waitFor();
        await panel(page).getByRole('button', { name: 'Open', exact: true }).waitFor();
        // The panel opened where the cover lay, so the pointer rests on one of its tracks and
        // marks it. It is moved off here, well before the picture, so the mark has faded.
        await page.mouse.move(0, 0);
      },
    },
    {
      // The album's details below the panel's tabs, from the cover down to "Open".
      picture: 'library-album',
      kind: 'crop',
      margin: 10,
      region: ({ page }) => [
        panel(page).getByRole('img', { name: OPENED_ALBUM, exact: true }),
        panel(page).getByRole('button', { name: 'Open', exact: true }),
      ],
    },
    {
      title: 'Narrow the songs down to a genre',
      run: async ({ page }) => {
        await openFromSidebar(page, 'Songs', '/music/songs');
        await closeSidePanel(page);
        await column(page, 'Genre').getByRole('option', { name: GENRE, exact: true }).click();
        await page.locator('main').getByText(LAST_WHOLE_SONG, { exact: true }).waitFor();
        // The Album column keeps every album; its covers are drawn one by one.
        await column(page, 'Album').locator('img').nth(7).waitFor();
      },
    },
    {
      // The three columns and the songs the list shows whole.
      picture: 'library-songs',
      kind: 'crop',
      region: ({ page }) => [
        column(page, 'Genre').locator('xpath=..'),
        column(page, 'Album').locator('xpath=..'),
        page.locator('main [style*="translateY"]').filter({ hasText: LAST_WHOLE_SONG }),
      ],
    },
    {
      title: 'Search for a name',
      run: async ({ page }) => {
        // The box asks for artists, albums and songs separately, a moment after the last letter.
        const answers = Promise.all(['/api/artists/', '/api/albums/', '/api/songs/'].map(path => page.waitForResponse((response) => {
          const url = new URL(response.url());
          return url.pathname === path && url.searchParams.get('q') === SEARCH;
        })));
        await page.keyboard.press('Control+k');
        await searchBox(page).getByPlaceholder('Search albums, artists, songs...').waitFor();
        await page.keyboard.type(SEARCH);
        await answers;
        for (const heading of ['Artists', 'Albums', 'Songs']) {
          await searchBox(page).getByText(heading, { exact: true }).waitFor();
        }
        // Which result the box marks depends on which answer came first; Home marks the first one.
        await page.keyboard.press('Home');
        await searchBox(page).getByRole('option').first().and(searchBox(page).locator('[aria-selected="true"]')).waitFor();
      },
    },
    {
      // The box alone. The page it dims and blurs is left out: a capture of part of the window
      // draws that blur in some places and not in others.
      picture: 'library-search',
      kind: 'crop',
      overlay: 'dialog',
      region: ({ page }) => [searchBox(page)],
      // Moving the text cursor a letter back and forth restarts its blinking, so both themes
      // catch it in the same phase.
      before: async ({ page }) => {
        await page.keyboard.press('ArrowLeft');
        await page.keyboard.press('ArrowRight');
      },
    },
    {
      title: 'Close the search box',
      run: async ({ page }) => {
        await page.keyboard.press('Escape');
        await searchBox(page).waitFor({ state: 'hidden' });
      },
    },
  ],
};
