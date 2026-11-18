// The pictures of the playback destinations page, taken as the listener in Chromium while a
// Firefox signed in to the same account stands open. The steps follow the page's guide: play a
// song, open the destination menu, pick the other browser, pause it from the first one and bring
// playback back. The menu names every browser as that browser registered itself, so the names in
// the pictures are the ones Chromium and Firefox give by themselves; the second Chromium in the
// list is the window the capture chooses the theme in. The song and the position it is paused on
// are stated here, and the scene ends as it began, with playback in the first browser, paused.
import { closeMenu, closeSidePanel, keepStoredSettings, pausePlayback, playSong, playerBar, seekTo } from '../application.mjs';
import { LISTENER } from '../checks.mjs';

// The first song in the list of an album with four songs. The list ends above the place where
// the menu opens, so no line of it runs behind the menu's upper edge, and the line that marks
// the song lies above the taller menu the application shows after the move.
const SONG = { album: 'Winter Letters', title: 'Ink Freezes First' };
// Where the song is left paused after the move, in seconds.
const POSITION = 80;
// The names the account's other two windows register under: the capture's theme window and Firefox.
const THEME_WINDOW = 'Chrome on Linux';
const OTHER_BROWSER = 'Firefox on Linux';
const RETURN = 'Return playback to this browser';
// What the menu lists while this browser plays, and after the move.
const BEFORE_THE_MOVE = ['This browser — Active', THEME_WINDOW, OTHER_BROWSER];
const AFTER_THE_MOVE = ['This browser', THEME_WINDOW, `${OTHER_BROWSER} — Active`, RETURN];

// The screen icon beside the volume. It is found in the page itself: while its menu is open the
// application hides the page behind the menu from the accessibility tree.
const destinationButton = page => page.locator('[aria-label="Playback destination"]');

/**
 * Opens the destination menu and waits until it lists exactly these entries in this order. A
 * browser that went away without leaving the application stays listed for about half a minute
 * and the menu drops it by itself, so the wait is longer than that.
 */
async function openMenu(page, entries) {
  await destinationButton(page).click();
  const listed = page.getByRole('menu').getByRole('menuitem');
  await listed.first().waitFor();
  await page.waitForFunction(
    wanted => [...document.querySelectorAll('[role="menu"] [role="menuitem"]')].map(entry => entry.textContent).join('\n') === wanted,
    entries.join('\n'),
    { timeout: 45000 },
  ).catch(async () => {
    throw new Error(`The playback destination menu lists "${(await listed.allInnerTexts()).join('", "')}" and not "${entries.join('", "')}".`);
  });
}

// Two browsers of one account may each ask which version of the settings to keep once a song plays.
async function answerSettingsQuestions(...windows) {
  for (const window of windows) await keepStoredSettings(window, 1000);
}

// The open menu and what lies under it: the right end of the player bar, from the song's length
// to the screen icon.
const menuOverBarEnd = page => [
  page.getByRole('menu'),
  page.locator('[aria-label="Playback"] [data-slot="slider"]:has([aria-label="Seek"]) + span'),
  page.locator('[aria-label="Playback"] [aria-label="Mute"]'),
  destinationButton(page),
];

export default {
  name: 'destinations',
  account: LISTENER,
  otherBrowsers: ['firefox'],
  steps: [
    {
      title: 'Play a song',
      run: async ({ page, others }) => {
        await playSong(page, SONG);
        // The double press that started the song left a word of its title selected. A press on
        // the heading of the song list, which does nothing, lets go of the selection.
        await page.locator('main').getByText('Songs', { exact: true }).click();
        await answerSettingsQuestions(page, others.firefox);
        await closeSidePanel(page);
        await pausePlayback(page);
      },
    },
    {
      title: 'Open the destination menu',
      run: ({ page }) => openMenu(page, BEFORE_THE_MOVE),
    },
    {
      picture: 'destinations-menu',
      kind: 'crop',
      overlay: 'menu',
      margin: 3,
      region: ({ page }) => menuOverBarEnd(page),
    },
    {
      title: 'Pick the other browser while the song plays',
      run: async ({ page, others }) => {
        await closeMenu(page);
        await playerBar(page).getByRole('button', { name: 'Play', exact: true }).click();
        await playerBar(page).getByRole('button', { name: 'Pause', exact: true }).waitFor();
        await openMenu(page, BEFORE_THE_MOVE);
        await page.getByRole('menuitem', { name: OTHER_BROWSER, exact: true }).click();
        // The move is over when the screen icon names the other browser as the one that plays.
        await destinationButton(page).and(page.getByTitle(`Playing on ${OTHER_BROWSER}`, { exact: true })).waitFor();
        await answerSettingsQuestions(page, others.firefox);
      },
    },
    {
      title: 'Pause it from the first browser, part-way',
      run: async ({ page, others }) => {
        await pausePlayback(page);
        // The other browser follows: its own player bar offers Play again.
        await playerBar(others.firefox).getByRole('button', { name: 'Play', exact: true }).waitFor();
        await seekTo(page, POSITION);
        await closeSidePanel(page);
      },
    },
    {
      title: 'Open the menu again',
      run: ({ page }) => openMenu(page, AFTER_THE_MOVE),
    },
    {
      picture: 'destinations-window',
      kind: 'window',
      overlay: 'menu',
    },
    {
      picture: 'destinations-return',
      kind: 'crop',
      overlay: 'menu',
      margin: 3,
      region: ({ page }) => menuOverBarEnd(page),
    },
    {
      title: 'Bring playback back',
      run: async ({ page }) => {
        await page.getByRole('menuitem', { name: RETURN, exact: true }).click();
        await page.getByRole('menu').waitFor({ state: 'hidden' });
        await openMenu(page, BEFORE_THE_MOVE);
        await closeMenu(page);
      },
    },
  ],
};
