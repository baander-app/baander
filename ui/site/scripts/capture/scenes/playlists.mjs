// The pictures of the playlists page, taken as the listener and, for the shared side, as the
// second listener. The steps follow the page's guide: create a playlist, add songs to it from
// albums through a song's menu, play it from its own page, and share it by the other person's
// public id, which that person reads in the Share dialog of a playlist of their own. With
// "Let collaborators edit" on, the second listener adds a song as well.
//
// Every playlist is looked for by name before it is created, the collaborator is added and the
// switch pressed only when the Share dialog does not show them, and a song is added only when the
// playlist lacks it, so a later run photographs the same state. The web application can neither
// remove a song from a playlist nor move one, so the scene stops when the playlist holds anything
// but the beginning of the songs listed here.
import { albumCard, closeSidePanel, openFromSidebar, pausePlayback, playHere, seekTo, songInPlayer } from '../application.mjs';

const OTHER_LISTENER = 'jonas@baander.app';
const PLAYLIST = { name: 'Evening listening', description: 'A few favourites for the end of the day.' };
// Two more playlists of the listener, so the dialog that adds a song has a list to choose from.
const OTHER_PLAYLISTS = [{ name: 'Slow Sundays' }, { name: 'On the road' }];
// The second listener's own playlist, whose Share dialog shows his public id.
const OWN_PLAYLIST = { name: 'Morning run' };
// The songs the listener adds, in the order the playlist keeps them, and the one the second
// listener adds after them.
const SONGS = [
  { album: 'Glass Harbour', title: 'Keeper of the Small Light' },
  { album: 'Slow Weather', title: 'Porch Light Promise' },
  { album: 'Field Notes for Winter', title: 'Lamp in the Sheepfold' },
  { album: 'Stairwell Ballads', title: 'Ballad for a Borrowed Piano' },
  { album: 'Ferry Hours', title: 'Last Boat Lullaby' },
  { album: 'Paper Satellites', title: 'Almanac for Small Hours' },
  { album: 'Winter Letters', title: "Letter I Didn't Send" },
  { album: 'Slow Weather', title: 'Leave the Kettle On' },
];
const COLLABORATORS_SONG = { album: 'Brushes on Slate', title: 'Quarter Past Rain' };
// The song whose menu leads to the dialog that adds a song. A picture of part of the window shows
// sharp what lies behind the dialog's rounded corners; on this album's page, with the panel
// beside it closed, that is empty background, because the third title in its list is a short one.
const DIALOG_SONG = SONGS[3];
const titlesOf = songs => songs.map(song => song.title);
// Where the playlist's first song is left paused, in seconds, so the player bar shows a position.
const POSITION = 52;

// The second listener's public id, as his own Share dialog shows it: read in one step, pasted in a later one.
let othersPublicId = null;

const playerBar = page => page.getByLabel('Playback', { exact: true });
const panel = page => page.getByRole('complementary').filter({ has: page.getByRole('tab', { name: 'Details', exact: true }) });
// The playlists on the Playlists page, and one of them by its name.
const listed = page => page.locator('main a[href^="/music/playlists/"]');
const playlist = (page, name) => listed(page).filter({ has: page.getByText(name, { exact: true }) });
// The titles of a playlist's songs on its own page, in the order it shows them.
const songTitles = page => page.locator('main ul > li > div > p:first-child').allInnerTexts();
const dialog = (page, title) => page.getByRole('dialog').filter({ has: page.getByRole('heading', { name: title, exact: true }) });
const editSwitch = page => dialog(page, 'Share playlist').getByRole('switch', { name: 'Let collaborators edit', exact: true });
const collaborators = page => dialog(page, 'Share playlist').getByRole('list', { name: 'Collaborators', exact: true }).locator('code');

// The application's answer to a change of a playlist. A change it refused would be missing from
// the pictures and from every later run.
function answerTo(page, method, path) {
  return page.waitForResponse(response => path.test(new URL(response.url()).pathname) && response.request().method() === method);
}

async function requireStored(answer, what) {
  if (!(await answer).ok()) throw new Error(`The application did not store ${what}.`);
}

function requireSongs(held, wanted) {
  if (held.join('\n') !== titlesOf(wanted).join('\n')) {
    throw new Error(`The playlist ${PLAYLIST.name} holds "${held.join('", "')}" and not "${titlesOf(wanted).join('", "')}".`);
  }
}

/** Opens the Playlists page and waits until it shows the account's playlists, or says that there are none. */
async function openPlaylists(page) {
  await openFromSidebar(page, 'Playlists', '/music/playlists');
  await listed(page).first().or(page.getByText('No playlists yet', { exact: true })).waitFor();
}

/**
 * Creates a playlist on the Playlists page unless one of that name is listed. The form's "Smart
 * playlist" switch is left off. Once the application has stored a new playlist, the page shows
 * placeholders where its list was and keeps the form open; leaving the page then and opening it
 * again lists the playlist, so that is what the scene does when the playlist does not show.
 */
async function createUnlessListed(page, { name, description }) {
  if (await playlist(page, name).count() === 0) {
    await page.getByRole('button', { name: 'Create playlist', exact: true }).click();
    await page.getByPlaceholder('Playlist name').fill(name);
    if (description) await page.getByPlaceholder('Description (optional)').fill(description);
    const created = answerTo(page, 'POST', /^\/api\/playlists\/$/);
    await page.getByRole('button', { name: 'Create', exact: true }).click();
    await requireStored(created, `the playlist ${name}`);
    await playlist(page, name).or(page.locator('main [data-slot="skeleton"]').first()).waitFor();
    if (await playlist(page, name).count() === 0) {
      await openFromSidebar(page, 'Home', '/music');
      await openPlaylists(page);
      await playlist(page, name).waitFor();
    }
  }
  const found = await playlist(page, name).count();
  if (found !== 1) throw new Error(`The Playlists page lists ${found} playlists named ${name}.`);
}

/** Opens a playlist's own page from the Playlists page and waits until it shows the playlist. */
async function openPlaylist(page, name) {
  await openPlaylists(page);
  await playlist(page, name).click();
  await page.waitForURL(url => /^\/music\/playlists\/[^/]+$/.test(url.pathname));
  await page.getByRole('heading', { name, exact: true, level: 1 }).waitFor();
}

/** Opens a playlist's page and its Share dialog, and waits until the dialog shows how the playlist is shared. */
async function openShare(page, name) {
  await openPlaylist(page, name);
  await page.getByRole('button', { name: 'Share', exact: true }).click();
  await editSwitch(page).waitFor();
}

async function closeDialog(page) {
  await page.keyboard.press('Escape');
  await page.getByRole('dialog').waitFor({ state: 'hidden' });
}

/** Opens an album's page: a press on its cover on the Albums page shows it in the panel, whose "Open" leads there. */
async function openAlbum(page, title) {
  await openFromSidebar(page, 'Albums', '/music/albums');
  await page.getByRole('heading', { name: 'Albums', exact: true, level: 1 }).waitFor();
  await albumCard(page, title).click();
  await panel(page).getByRole('img', { name: title, exact: true }).waitFor();
  await panel(page).getByRole('button', { name: 'Open', exact: true }).click();
  await page.waitForURL(url => /^\/music\/albums\/[^/]+$/.test(url.pathname));
}

/** On an album's page, right-clicks a song and points to "Add to Playlist", which lists the account's playlists. */
async function pointToAddToPlaylist(page, title) {
  await page.locator('main').getByText(title, { exact: true }).click({ button: 'right' });
  await page.getByRole('menuitem', { name: 'Add to Playlist', exact: true }).hover();
}

/** Adds a song to a playlist from its album's page and waits until the application has stored it. */
async function addSong(page, { album, title }, name) {
  await openAlbum(page, album);
  await pointToAddToPlaylist(page, title);
  const added = answerTo(page, 'POST', /^\/api\/playlists\/[^/]+\/songs$/);
  await page.getByRole('menuitem', { name, exact: true }).click();
  await requireStored(added, `${title} in the playlist ${name}`);
  await page.getByRole('menu').first().waitFor({ state: 'hidden' });
}

export default {
  name: 'playlists',
  account: 'maja@baander.app',
  steps: [
    {
      title: 'Create the playlists',
      run: async ({ page }) => {
        await openPlaylists(page);
        for (const wanted of [PLAYLIST, ...OTHER_PLAYLISTS]) await createUnlessListed(page, wanted);
      },
    },
    {
      title: 'Add the songs the playlist lacks',
      run: async ({ page }) => {
        await openPlaylist(page, PLAYLIST.name);
        const held = await songTitles(page);
        // A playlist that is complete ends with the second listener's song.
        requireSongs(held, [...SONGS, COLLABORATORS_SONG].slice(0, held.length));
        for (const song of SONGS.slice(held.length)) await addSong(page, song, PLAYLIST.name);
      },
    },
    {
      title: 'The second listener creates a playlist of his own and reads his public id in its Share dialog',
      account: OTHER_LISTENER,
      run: async ({ page }) => {
        await openPlaylists(page);
        await createUnlessListed(page, OWN_PLAYLIST);
        await openShare(page, OWN_PLAYLIST.name);
        othersPublicId = await dialog(page, 'Share playlist').locator('section').filter({ hasText: 'Your public id' }).locator('code').innerText();
        await closeDialog(page);
      },
    },
    {
      // After each change the dialog is closed and opened again, which reads what the application stored.
      title: 'Share the playlist with him and let collaborators edit',
      run: async ({ page }) => {
        await openShare(page, PLAYLIST.name);
        if (!(await collaborators(page).allInnerTexts()).includes(othersPublicId)) {
          await dialog(page, 'Share playlist').getByPlaceholder('Paste a public id').fill(othersPublicId);
          const added = answerTo(page, 'PUT', /^\/api\/playlists\/[^/]+\/collaborators\/[^/]+$/);
          await dialog(page, 'Share playlist').getByRole('button', { name: 'Add', exact: true }).click();
          await requireStored(added, 'the collaborator');
          await closeDialog(page);
          await openShare(page, PLAYLIST.name);
        }
        if (await editSwitch(page).getAttribute('aria-checked') !== 'true') {
          const updated = answerTo(page, 'PATCH', /^\/api\/playlists\/[^/]+$/);
          await editSwitch(page).click();
          await requireStored(updated, 'that collaborators may edit the playlist');
        }
        await closeDialog(page);
      },
    },
    {
      title: 'The second listener opens the shared playlist and adds a song unless it is there',
      account: OTHER_LISTENER,
      run: async ({ page }) => {
        await openPlaylist(page, PLAYLIST.name);
        // A collaborator's page of the playlist has "Leave playlist" where the owner's has "Share".
        await page.getByRole('button', { name: 'Leave playlist', exact: true }).waitFor();
        const held = await songTitles(page);
        if (held.length === SONGS.length) {
          requireSongs(held, SONGS);
          await addSong(page, COLLABORATORS_SONG, PLAYLIST.name);
        } else {
          requireSongs(held, [...SONGS, COLLABORATORS_SONG]);
        }
      },
    },
    {
      title: 'Play it and pause the first song part-way',
      run: async ({ page }) => {
        await openPlaylist(page, PLAYLIST.name);
        await page.locator('main ul > li').nth(SONGS.length).waitFor();
        requireSongs(await songTitles(page), [...SONGS, COLLABORATORS_SONG]);
        await playHere(page);
        await page.getByRole('button', { name: 'Play all', exact: true }).click();
        await songInPlayer(page, SONGS[0].title);
        await playerBar(page).getByRole('button', { name: 'Pause', exact: true }).waitFor();
        await pausePlayback(page);
        await seekTo(page, POSITION);
        await closeSidePanel(page);
      },
    },
    {
      // The playlist's page: its name and description, the number of songs and their total time,
      // "Play all" and "Share", and the songs in order as far as the window shows them.
      picture: 'playlists-window',
      kind: 'window',
    },
    {
      title: 'Choose a playlist for a song in a dialog',
      run: async ({ page }) => {
        await openAlbum(page, DIALOG_SONG.album);
        await closeSidePanel(page);
        await pointToAddToPlaylist(page, DIALOG_SONG.title);
        await page.getByRole('menuitem', { name: 'Choose Playlist…', exact: true }).click();
        for (const { name } of [PLAYLIST, ...OTHER_PLAYLISTS]) {
          await dialog(page, 'Add to Playlist').getByRole('button', { name, exact: true }).waitFor();
        }
        // The dialog opens with the text cursor blinking in its search field. A press on the
        // dialog's own description, which does nothing, takes the cursor out, and the pointer is
        // moved off the list here, well before the picture, so no playlist stays marked.
        await dialog(page, 'Add to Playlist').getByText('Choose an existing playlist or create a new one.', { exact: true }).click();
        await page.mouse.move(0, 0);
      },
    },
    {
      // The dialog alone: the search field, the listener's playlists and the field that names a new one.
      picture: 'playlists-add',
      kind: 'crop',
      overlay: 'dialog',
      region: ({ page }) => [dialog(page, 'Add to Playlist')],
    },
    {
      title: 'Close it without adding and open the Share dialog',
      run: async ({ page }) => {
        await closeDialog(page);
        await openShare(page, PLAYLIST.name);
        await collaborators(page).getByText(othersPublicId, { exact: true }).waitFor();
        await editSwitch(page).and(page.locator('[aria-checked="true"]')).waitFor();
        await page.mouse.move(0, 0);
      },
    },
    {
      // The dialog alone: the switch, the collaborator, the field for a public id and the owner's own id.
      picture: 'playlists-share',
      kind: 'crop',
      overlay: 'dialog',
      region: ({ page }) => [dialog(page, 'Share playlist')],
    },
    {
      title: 'Close the Share dialog',
      run: ({ page }) => closeDialog(page),
    },
    {
      title: 'The second listener opens his Playlists page',
      account: OTHER_LISTENER,
      run: async ({ page }) => {
        await openPlaylists(page);
        await playlist(page, PLAYLIST.name).getByText('Shared with you', { exact: true }).waitFor();
        await playlist(page, OWN_PLAYLIST.name).waitFor();
        // The page is as narrow as a picture beside text only while the panel beside it is open.
        await page.getByRole('button', { name: 'Close context panel', exact: true }).waitFor();
      },
    },
    {
      // The heading with its "+" and the list, in which the shared playlist carries its label.
      picture: 'playlists-shared',
      kind: 'crop',
      account: OTHER_LISTENER,
      margin: 8,
      region: ({ page }) => [
        page.getByRole('heading', { name: 'Playlists', exact: true, level: 1 }),
        page.getByRole('button', { name: 'Create playlist', exact: true }),
        playlist(page, PLAYLIST.name),
        playlist(page, OWN_PLAYLIST.name),
      ],
    },
  ],
};
