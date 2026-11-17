import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import test from 'node:test';
import { awaitedLater } from './capture/application.mjs';
import {
  SAMPLE_ACCOUNTS, accountProblems, catalogProblems, contactSheet, coverFailures, libraryProblems, mergeRecords,
  pendingWorkProblems, recordEntry, settingProblems, settingValue,
} from './capture/checks.mjs';
import { buildManifest } from './capture/library-manifest.mjs';

const manifest = buildManifest();
// The catalog as the application lists a preview that holds exactly the sample library.
const sampleCatalog = () => ({
  artists: { total: manifest.artists.length, names: [...manifest.artists] },
  albums: { total: manifest.albums.length, entries: manifest.albums.map(album => ({ title: album.title, artists: [album.artist] })) },
});
const sampleUsers = () => SAMPLE_ACCOUNTS.map(({ email, name }) => ({ email, name }));
const sampleLibrary = { name: 'Music', slug: 'music', scanStatus: 'completed' };

test('a catalog that is the sample library has nothing to report', () => {
  assert.deepEqual(catalogProblems(manifest, sampleCatalog()), []);
});

test('an album and an artist the generator did not make are named', () => {
  const catalog = sampleCatalog();
  catalog.artists.names.push('Harbour Lights Ensemble');
  catalog.artists.total += 1;
  catalog.albums.entries.push({ title: 'Tide Tables', artists: ['Harbour Lights Ensemble'] });
  catalog.albums.total += 1;

  assert.deepEqual(catalogProblems(manifest, catalog), [
    'The catalog holds an artist the sample library does not: Harbour Lights Ensemble.',
    'The catalog holds an album the sample library does not: "Tide Tables" by Harbour Lights Ensemble.',
  ]);
});

test('a sample artist and a sample album the catalog lacks are named', () => {
  const catalog = sampleCatalog();
  catalog.artists.names = catalog.artists.names.filter(name => name !== 'Ivy Marsh');
  catalog.artists.total -= 1;
  catalog.albums.entries = catalog.albums.entries.filter(album => album.title !== 'Slow Weather');
  catalog.albums.total -= 1;

  assert.deepEqual(catalogProblems(manifest, catalog), [
    'The catalog lacks the sample artist Ivy Marsh.',
    'The catalog lacks the sample album "Slow Weather" by Ivy Marsh.',
  ]);
});

test('a sample album under another artist is both unexpected and missing', () => {
  const catalog = sampleCatalog();
  catalog.albums.entries.find(album => album.title === 'Overgrown').artists = ['Ivy Marsh'];

  assert.deepEqual(catalogProblems(manifest, catalog), [
    'The catalog holds an album the sample library does not: "Overgrown" by Ivy Marsh.',
    'The catalog lacks the sample album "Overgrown" by Fern & Fable.',
  ]);
});

test('a catalog larger than the application listed is refused even when the listed part matches', () => {
  const catalog = sampleCatalog();
  catalog.albums.total = 240;

  assert.deepEqual(catalogProblems(manifest, catalog), [
    `The catalog holds 240 albums and the application listed only ${manifest.albums.length} of them.`,
  ]);
});

test('the three sample accounts alone pass, and an unexpected, a missing and a renamed account are named', () => {
  assert.deepEqual(accountProblems(sampleUsers()), []);

  const users = [
    { email: 'admin@baander.test', name: 'Admin User' },
    { email: 'maja@baander.app', name: 'Maja L.' },
    { email: 'jonas@baander.app', name: 'Jonas Lind' },
  ];
  assert.deepEqual(accountProblems(users), [
    'An account that is not a sample account exists: admin@baander.test (Admin User).',
    'The account maja@baander.app is named "Maja L.", not "Maja Lind".',
    'The sample account signe@baander.app is missing.',
  ]);
});

test('only the sample library may exist', () => {
  assert.deepEqual(libraryProblems([sampleLibrary]), []);
  assert.deepEqual(libraryProblems([sampleLibrary, { name: 'Films', slug: 'films' }]), [
    'A library other than the sample library exists: "Films" (films).',
  ]);
  assert.deepEqual(libraryProblems([]), ['The sample library "music" is missing.']);
});

test('the value is read from the table the settings command prints', () => {
  const table = [
    ' ---------- ------------------- ',
    '  Key        lyrics.auto_fetch  ',
    '  Value      true               ',
    '  Default    false              ',
    '  Stored     true               ',
    ' ---------- ------------------- ',
  ].join('\n');

  assert.equal(settingValue(table), 'true');
  assert.equal(settingValue('No such setting.'), null);
});

test('a setting that is on or cannot be read is named', () => {
  assert.deepEqual(settingProblems({ 'metadata.auto_sync': 'false', 'lyrics.auto_fetch': 'false' }), []);
  assert.deepEqual(settingProblems({ 'metadata.auto_sync': null, 'lyrics.auto_fetch': 'true' }), [
    'The setting metadata.auto_sync is unreadable; a capture needs it off.',
    'The setting lyrics.auto_fetch is true; a capture needs it off.',
  ]);
});

test('queued and running jobs and a library being scanned are named; finished and failed jobs are not', () => {
  assert.deepEqual(pendingWorkProblems({ counts: { finished: 43, failed: 2 }, running: [] }, [sampleLibrary]), []);
  assert.deepEqual(
    pendingWorkProblems({ counts: { finished: 43, queued: 3, running: 1 }, running: [] }, [{ ...sampleLibrary, scanStatus: 'scanning' }]),
    ['3 background job(s) are queued.', '1 background job(s) are running.', 'The library "Music" is being scanned.'],
  );
});

test('the player bar cover the application answers 403 for does not stop a window picture; any other failed cover does', () => {
  assert.deepEqual(coverFailures(new Map([['/api/albums/uSJWFDUEzFnbd1qQoCKKv/cover', 'answered 403']])), []);
  assert.deepEqual(coverFailures(new Map([
    ['/api/albums/uSJWFDUEzFnbd1qQoCKKv/cover', 'answered 403'],
    ['/api/albums/W43snHW4ji2pTf_WxwGTA/cover', 'answered 500'],
    ['/api/images/H9s_BAlfJfDLaI5I_HygT/file', 'answered 403'],
    ['/api/artists/OiZ9LzCLTavQDBY5BPI2E/cover', 'failed: net::ERR_CONNECTION_RESET'],
  ])), [
    '/api/albums/W43snHW4ji2pTf_WxwGTA/cover answered 500',
    '/api/images/H9s_BAlfJfDLaI5I_HygT/file answered 403',
    '/api/artists/OiZ9LzCLTavQDBY5BPI2E/cover failed: net::ERR_CONNECTION_RESET',
  ]);
});

test('a promise that is awaited later stays the same promise, is not reported while nothing awaits it and still rejects when awaited', async () => {
  const reported = [];
  const report = reason => reported.push(reason);
  process.on('unhandledRejection', report);
  try {
    const refusal = new Error('The window closed.');
    const promise = Promise.reject(refusal);

    assert.equal(awaitedLater(promise), promise);
    // Node reports a rejection that nothing handles once the turn it happened in is over.
    await new Promise(turn => setImmediate(turn));
    assert.deepEqual(reported, []);
    await assert.rejects(promise, refusal);
  } finally {
    process.off('unhandledRejection', report);
  }
});

test('a record carries the scene, the commit, the date and the hash of both files', () => {
  const dark = Buffer.from('dark picture');
  const light = Buffer.from('light picture');
  const sha256 = bytes => createHash('sha256').update(bytes).digest('hex');

  assert.deepEqual(recordEntry({ scene: 'equalizer', commit: 'c7c79881d', date: '2026-11-17', dark, light }), {
    scene: 'equalizer',
    commit: 'c7c79881d',
    date: '2026-11-17',
    sha256: { dark: sha256(dark), light: sha256(light) },
  });
});

test('new records replace their pictures, keep the others and are ordered by name', () => {
  const earlier = { music: { scene: 'library', note: 'kept by hand' }, 'equalizer-window': { scene: 'equalizer', date: '2026-11-01' } };
  const merged = mergeRecords(earlier, { 'equalizer-window': { scene: 'equalizer', date: '2026-11-17' }, destinations: { scene: 'playback' } });

  assert.deepEqual(Object.keys(merged), ['destinations', 'equalizer-window', 'music']);
  assert.equal(merged['equalizer-window'].date, '2026-11-17');
  assert.deepEqual(merged.music, earlier.music);
});

test('the contact sheet shows each pair with its name and size, at half its pixel size', () => {
  const sheet = contactSheet([
    { name: 'equalizer-curve', scene: 'equalizer', kind: 'crop', width: 1728, height: 714, dark: 'out/equalizer-curve.webp', light: 'out/equalizer-curve-light.webp' },
  ]);

  assert.match(sheet, /<h2>equalizer-curve<\/h2>/);
  assert.match(sheet, /equalizer scene · crop · 1728 × 714 px, shown at 864 × 357/);
  assert.match(sheet, /<img src="out\/equalizer-curve\.webp" width="864" height="357" alt="equalizer-curve, dark">/);
  assert.match(sheet, /<img src="out\/equalizer-curve-light\.webp" width="864" height="357" alt="equalizer-curve, light">/);
});
