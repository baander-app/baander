// The parts of the picture capture that need no browser and no running application: what the
// preview must hold before a picture is taken, how each difference is worded, how a single picture
// is refused, and what the capture record and the contact sheet contain.
import { createHash } from 'node:crypto';

/**
 * A picture the driver will not take, with the reason as a lowercase phrase. The run records it as
 * not taken and goes on with the next picture; any other error ends the run.
 */
export class NotReady extends Error {}

// The slug of the only library the preview may have, the generated sample library.
export const SAMPLE_LIBRARY = 'music';

// The addresses of the sample accounts, by the part each has in the scenes: the listener, the
// second listener, whom a playlist is shared with, and the administrator.
export const LISTENER = 'maja@baander.app';
export const SECOND_LISTENER = 'jonas@baander.app';
export const ADMINISTRATOR = 'signe@baander.app';

// The only accounts the preview may have. A listener is granted the sample library; an
// administrator sees every library without a grant.
export const SAMPLE_ACCOUNTS = [
  { email: LISTENER, name: 'Maja Lind', roles: ['user'], library: SAMPLE_LIBRARY },
  { email: SECOND_LISTENER, name: 'Jonas Lind', roles: ['user'], library: SAMPLE_LIBRARY },
  { email: ADMINISTRATOR, name: 'Signe Holm', roles: ['admin', 'super-admin'] },
];

// Server settings that would send sample names to a metadata or lyrics provider when on.
export const SETTINGS_KEPT_OFF = ['metadata.auto_sync', 'lyrics.auto_fetch'];

const albumLabel = (title, artists) => `"${title}" by ${artists.join(', ')}`;

/**
 * How the application's catalog differs from the sample library, one sentence per difference.
 *
 * `catalog` is what the application listed: `artists` and `albums` each hold the `names` or
 * `entries` it returned and the `total` it reported.
 */
export function catalogProblems(manifest, catalog) {
  const problems = [];
  for (const [kind, listed] of [['artists', catalog.artists.names], ['albums', catalog.albums.entries]]) {
    if (catalog[kind].total > listed.length) {
      problems.push(`The catalog holds ${catalog[kind].total} ${kind} and the application listed only ${listed.length} of them.`);
    }
  }

  const sampleArtists = new Set(manifest.artists);
  const listedArtists = new Set(catalog.artists.names);
  for (const name of listedArtists.difference(sampleArtists)) {
    problems.push(`The catalog holds an artist the sample library does not: ${name}.`);
  }
  for (const name of sampleArtists.difference(listedArtists)) {
    problems.push(`The catalog lacks the sample artist ${name}.`);
  }

  const sampleAlbums = new Set(manifest.albums.map(album => albumLabel(album.title, [album.artist])));
  const listedAlbums = new Set(catalog.albums.entries.map(album => albumLabel(album.title, album.artists)));
  for (const album of listedAlbums.difference(sampleAlbums)) {
    problems.push(`The catalog holds an album the sample library does not: ${album}.`);
  }
  for (const album of sampleAlbums.difference(listedAlbums)) {
    problems.push(`The catalog lacks the sample album ${album}.`);
  }
  return problems;
}

/** How the application's accounts, as `app:user:list --json` prints them, differ from the sample accounts. */
export function accountProblems(users) {
  const problems = [];
  for (const user of users) {
    const sample = SAMPLE_ACCOUNTS.find(account => account.email === user.email);
    if (!sample) {
      problems.push(`An account that is not a sample account exists: ${user.email} (${user.name}).`);
    } else if (sample.name !== user.name) {
      problems.push(`The account ${user.email} is named "${user.name}", not "${sample.name}".`);
    }
  }
  for (const sample of SAMPLE_ACCOUNTS) {
    if (!users.some(user => user.email === sample.email)) {
      problems.push(`The sample account ${sample.email} is missing.`);
    }
  }
  return problems;
}

/** How the libraries, as `app:library:list --json` prints them, differ from the sample library alone. */
export function libraryProblems(libraries) {
  const problems = libraries
    .filter(library => library.slug !== SAMPLE_LIBRARY)
    .map(library => `A library other than the sample library exists: "${library.name}" (${library.slug}).`);
  if (!libraries.some(library => library.slug === SAMPLE_LIBRARY)) {
    problems.push(`The sample library "${SAMPLE_LIBRARY}" is missing.`);
  }
  return problems;
}

/** The value in the table `app:settings:get` prints, as the JSON literal it shows, or null. */
export function settingValue(output) {
  return output.match(/^\s*Value\s+(.+?)\s*$/m)?.[1] ?? null;
}

/** The settings among `values`, keyed by setting, that are not off. */
export function settingProblems(values) {
  return SETTINGS_KEPT_OFF
    .filter(key => values[key] !== 'false')
    .map(key => `The setting ${key} is ${values[key] ?? 'unreadable'}; a capture needs it off.`);
}

/** The scans and background jobs that have not finished, from `app:monitor:status --json` and the library list. */
export function pendingWorkProblems(status, libraries) {
  const problems = ['queued', 'running']
    .filter(state => (status.counts[state] ?? 0) > 0)
    .map(state => `${status.counts[state]} background job(s) are ${state}.`);
  for (const library of libraries.filter(({ scanStatus }) => scanStatus === 'scanning')) {
    problems.push(`The library "${library.name}" is being scanned.`);
  }
  return problems;
}

// The address the player bar asks for the cover of the album that is playing. The application
// answers 403 there for every account and draws a music-note placeholder in the bar, so the
// placeholder is what it shows today and this one answer does not stop a picture.
const PLAYER_BAR_COVER = /^\/api\/albums\/[^/]+\/cover$/;

/**
 * The cover requests that stop a picture of the whole window, each as its address and outcome.
 * `failed` maps the address of every cover whose latest answer was an error to that outcome.
 */
export function coverFailures(failed) {
  return [...failed]
    .filter(([address, outcome]) => !(PLAYER_BAR_COVER.test(address) && outcome === 'answered 403'))
    .map(([address, outcome]) => `${address} ${outcome}`);
}

/** The record of one picture: which scene took it, of which application commit, when, and the hash of both files. */
export function recordEntry({ scene, commit, date, dark, light }) {
  const sha256 = bytes => createHash('sha256').update(bytes).digest('hex');
  return { scene, commit, date, sha256: { dark: sha256(dark), light: sha256(light) } };
}

/** The records with the new entries put in, ordered by picture name; records of other pictures are kept. */
export function mergeRecords(existing, entries) {
  const merged = { ...existing, ...entries };
  return Object.fromEntries(Object.keys(merged).toSorted().map(name => [name, merged[name]]));
}

const escapeHtml = text => String(text).replace(/[&<>"]/g, character => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[character]);

/**
 * A page that shows every pair for review, dark beside light, at the size a visitor sees.
 *
 * Each pair has `name`, `scene`, `kind`, the pixel `width` and `height`, and the paths of the
 * `dark` and `light` files relative to the page.
 */
export function contactSheet(pairs) {
  const figure = (pair, theme) => `<figure><img src="${escapeHtml(pair[theme])}" width="${pair.width / 2}" height="${pair.height / 2}" alt="${escapeHtml(pair.name)}, ${theme}"><figcaption>${theme}</figcaption></figure>`;
  const sections = pairs.map(pair => `<section>
  <h2>${escapeHtml(pair.name)}</h2>
  <p>${escapeHtml(pair.scene)} scene · ${pair.kind} · ${pair.width} × ${pair.height} px, shown at ${pair.width / 2} × ${pair.height / 2}</p>
  <div class="pair">${figure(pair, 'dark')}${figure(pair, 'light')}</div>
</section>`);
  return `<!doctype html>
<html lang="en">
<meta charset="utf-8">
<title>Captured pictures</title>
<style>
  body { margin: 2rem; background: #808080; color: #fff; font: 14px/1.4 system-ui, sans-serif; }
  section { margin-bottom: 3rem; }
  h2 { margin: 0; font-size: 1.25rem; }
  p { margin: 0.25rem 0 0.75rem; }
  .pair { display: flex; flex-wrap: wrap; gap: 1rem; align-items: flex-start; }
  figure { margin: 0; }
  img { display: block; }
</style>
<h1>Captured pictures</h1>
${sections.join('\n')}
</html>
`;
}
