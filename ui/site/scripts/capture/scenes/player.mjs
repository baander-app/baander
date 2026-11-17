// The pictures of the player page, taken as the listener. The steps follow the page's guide:
// start an album, look at the queue, open an album's menu where "Play Next" is, and open an
// artist's page where "Shuffle" is. The album, the song it is paused on and the position are
// stated here, and nothing is added to the queue, so a later run photographs the same state.
import {
  albumCard, closeMenu, closeSidePanel, openAlbums, openFromSidebar, pausePlayback, playHere, playerBar, seekTo, songInPlayer,
} from '../application.mjs';
import { LISTENER } from '../checks.mjs';
import { buildManifest } from '../library-manifest.mjs';

// An album of six songs, which is as many as the Queue tab shows without scrolling.
const ALBUM = { title: 'Golden Static', songs: ['Signal Fires', 'Golden Static', 'Dial Tone Summer', 'Antenna Hill', 'Shortwave Hearts', 'Frequency of Leaving'] };
// The song the album is paused on, and where in it, in seconds.
const CURRENT = 2;
const POSITION = 70;
// The Albums page as it opens: sorted by name, five covers in a row.
const GRID = buildManifest().albums.map(album => album.title).toSorted();
// The album whose menu is opened, the first of the grid, and the last album of the three columns
// and two rows the picture of the menu shows.
const MENU_ALBUM = GRID[0];
const MENU_PICTURE_ENDS_WITH = GRID[7];
const MENU_ENTRIES = ['Play All', 'Shuffle All', 'Play Next', 'Play Last'];
const ARTIST = { name: 'Northbound Static', albums: ['Golden Static', 'Low Tide Radio'] };

const panel = page => page.getByRole('complementary').filter({ has: page.getByRole('tab', { name: 'Queue', exact: true }) });
const queued = (page, title) => panel(page).locator('[aria-roledescription="sortable"]').filter({ hasText: title });

export default {
  name: 'player',
  account: LISTENER,
  steps: [
    {
      title: 'Start an album',
      run: async ({ page }) => {
        await openAlbums(page);
        await playHere(page);
        // A press on a cover shows the album in the panel. When the panel was closed that narrows
        // the grid, and the second press of a double press would land beside the cover, so the
        // double press comes once the panel is open.
        await albumCard(page, ALBUM.title).click();
        await panel(page).waitFor();
        await albumCard(page, ALBUM.title).dblclick();
        await songInPlayer(page, ALBUM.songs[0]);
        await playerBar(page).getByRole('button', { name: 'Pause', exact: true }).waitFor();
      },
    },
    {
      title: 'Skip to a later song and pause it part-way',
      run: async ({ page }) => {
        for (const title of ALBUM.songs.slice(1, CURRENT + 1)) {
          await playerBar(page).getByRole('button', { name: 'Next', exact: true }).click();
          await songInPlayer(page, title);
        }
        await pausePlayback(page);
        await seekTo(page, POSITION);
      },
    },
    {
      // The panel shows a song's details each time another song starts; the queue is the first tab.
      title: 'Look at the queue',
      run: async ({ page }) => {
        const tab = panel(page).getByRole('tab', { name: 'Queue', exact: true });
        // The key brings a closed panel back; the press on the tab after it leaves no keyboard focus mark.
        if (!await tab.isVisible()) await page.keyboard.press('q');
        await tab.click();
        await panel(page).getByText(`${ALBUM.songs.length} tracks`, { exact: true }).waitFor();
        for (const title of ALBUM.songs) await queued(page, title).waitFor();
        for (const album of GRID) await albumCard(page, album).waitFor();
      },
    },
    {
      picture: 'player',
      kind: 'window',
    },
    {
      // The count with "Clear all" and the six songs; the current one has the play mark and no handle.
      picture: 'player-queue',
      kind: 'crop',
      margin: 10,
      region: ({ page }) => [
        panel(page).getByText(`${ALBUM.songs.length} tracks`, { exact: true }).locator('xpath=..'),
        queued(page, ALBUM.songs.at(-1)),
      ],
    },
    {
      title: "Open an album's menu",
      run: async ({ page }) => {
        await closeSidePanel(page);
        // The menu opens at the pointer: low on the right of the cover it leaves the album's
        // title readable and lies over the next cover.
        const cover = albumCard(page, MENU_ALBUM).locator('img');
        const { width, height } = await cover.boundingBox();
        await cover.click({ button: 'right', position: { x: width * 0.9, y: height * 0.75 } });
        for (const entry of MENU_ENTRIES) await page.getByRole('menuitem', { name: entry, exact: true }).waitFor();
      },
    },
    {
      // Three columns and two rows of the grid with the menu over them.
      picture: 'player-next',
      kind: 'crop',
      overlay: 'menu',
      region: ({ page }) => [albumCard(page, MENU_ALBUM), albumCard(page, MENU_PICTURE_ENDS_WITH), page.getByRole('menu')],
    },
    {
      title: "Close the menu and open an artist's page",
      run: async ({ page }) => {
        await closeMenu(page);
        await openFromSidebar(page, 'Artists', '/music/artists');
        await page.getByRole('heading', { name: 'Artists', exact: true, level: 1 }).waitFor();
        // A press on an artist shows the artist in the panel, whose "Open" leads to the page.
        await page.locator('main').getByRole('button', { name: ARTIST.name, exact: true }).click();
        await panel(page).getByRole('button', { name: 'Open', exact: true }).click();
        await page.waitForURL(url => /^\/music\/artists\/[^/]+$/.test(url.pathname));
        // The page shows its artist in the panel again as it opens, so the panel is closed once the page is drawn.
        await page.getByRole('heading', { name: ARTIST.name, exact: true, level: 1 }).waitFor();
        for (const album of ARTIST.albums) await albumCard(page, album).waitFor();
        await closeSidePanel(page);
      },
    },
    {
      // The artist's name with "Play" and "Shuffle", and the covers of the artist's albums.
      picture: 'player-shuffle',
      kind: 'crop',
      region: ({ page }) => [
        page.getByRole('heading', { name: ARTIST.name, exact: true, level: 1 }).locator('xpath=..'),
        ...ARTIST.albums.map(album => albumCard(page, album)),
      ],
    },
  ],
};
