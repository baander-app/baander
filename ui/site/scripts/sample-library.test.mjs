import assert from 'node:assert/strict';
import test from 'node:test';
import { buildManifest } from './capture/library-manifest.mjs';

const manifest = buildManifest();
const tracks = manifest.albums.flatMap(album => album.tracks.map(track => ({ album, track })));
const genres = ['Electronic', 'Folk', 'Indie', 'Jazz', 'Rock', 'Soul'];
const unique = values => new Set(values).size === values.length;
const folded = names => names.map(name => name.toLowerCase());

test('no artist, album or track path appears twice, and every track has a length and a genre', () => {
  // Two spellings of one name would show as two artists.
  assert.ok(unique(folded(manifest.artists)));
  // The application keeps one album per title in a library, whoever the artist is.
  assert.ok(unique(folded(manifest.albums.map(album => album.title))));
  assert.ok(unique(tracks.flatMap(({ track }) => [track.path, ...(track.lyrics ? [track.lyrics.path] : [])])));
  for (const { album, track } of tracks) {
    assert.ok(Number.isInteger(track.duration) && track.duration > 0, track.path);
    assert.ok(genres.includes(album.genre), track.path);
  }
});

test('every lyrics file is timestamped lines in order, inside its track', () => {
  const withLyrics = tracks.filter(({ track }) => track.lyrics);
  assert.ok(withLyrics.length > 0);
  for (const { track } of withLyrics) {
    const lines = track.lyrics.text.trimEnd().split('\n');
    const times = lines.map((line) => {
      const [, minutes, seconds, text] = line.match(/^\[(\d{2}):(\d{2}\.\d{2})\](.+)$/) ?? [];
      assert.ok(text, `${track.lyrics.path}: ${line}`);
      return Number(minutes) * 60 + Number(seconds);
    });
    assert.deepEqual(times, times.toSorted((a, b) => a - b), track.lyrics.path);
    assert.ok(unique(times), track.lyrics.path);
    assert.ok(times.at(-1) < track.duration, track.lyrics.path);
  }
});

test('building the manifest twice gives the same library', () => {
  assert.deepEqual(buildManifest(), manifest);
});

test('the names kept from the playback destinations picture are present', () => {
  assert.deepEqual(manifest.genres, genres);
  for (const artist of ['Fern & Fable', 'Ivy Marsh', 'Juno Vale', 'Kestrel & Pine', 'Lumen District', 'Mira Solberg', 'Northbound Static', 'Ola Brandt Trio', 'Sable Avenue']) {
    assert.ok(manifest.artists.includes(artist), artist);
  }
  const albums = [
    ['Mira Solberg', 'Glass Harbour'],
    ['Northbound Static', 'Golden Static'],
    ['Northbound Static', 'Low Tide Radio'],
    ['Juno Vale', 'Late Bloom'],
    ['Lumen District', 'Midnight Transit'],
    ['Sable Avenue', 'Neon Cartography'],
    ['Fern & Fable', 'Overgrown'],
    ['The Quiet Almanac', 'Paper Satellites'],
  ];
  for (const [artist, title] of albums) {
    assert.ok(manifest.albums.some(album => album.artist === artist && album.title === title), `${artist}: ${title}`);
  }
  const songs = [
    ['Northbound Static', 'Signal Fires'],
    ['Northbound Static', 'Undertow'],
    ['Mira Solberg', 'Glass Harbour'],
    ['Mira Solberg', 'Northern Lights, Briefly'],
    ['The Quiet Almanac', 'Paper Satellites'],
    ['The Quiet Almanac', 'Orbiting Home'],
    ['Juno Vale', 'Late Bloom'],
    ['Juno Vale', 'Honey & Ash'],
  ];
  for (const [artist, title] of songs) {
    assert.ok(tracks.some(({ album, track }) => album.artist === artist && track.title === title), `${artist}: ${title}`);
  }
});
