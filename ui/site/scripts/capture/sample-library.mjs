// Writes the fictional music library that the website pictures are captured from:
//
//   node ui/site/scripts/capture/sample-library.mjs <output-directory>
//
// Each track is a FLAC file with tags and its album's cover embedded, laid out as
// Artist/Album/NN Title.flac, with an .lrc file beside every track that has lyrics. ffmpeg
// synthesizes the audio and Chromium draws the covers, so the script needs both. The output
// directory may be new, empty or hold an earlier run of this script, which is replaced; a
// directory that holds anything else is refused.
import { execFile } from 'node:child_process';
import { mkdir, mkdtemp, readdir, readFile, rm, stat, writeFile } from 'node:fs/promises';
import { availableParallelism, tmpdir } from 'node:os';
import { join, relative, resolve } from 'node:path';
import { promisify } from 'node:util';
import { chromium } from '@playwright/test';
import { buildManifest } from './library-manifest.mjs';

// Lists every file of a run, so that the next run recognises its own output.
const MARKER = '.sample-library.json';
const SAMPLE_RATE = 44100;
const SEMITONE = Math.log(2) / 12;
const ffmpeg = promisify(execFile);

// Beats per minute, lowest and highest, by genre.
const TEMPO = { Electronic: [116, 128], Folk: [84, 104], Indie: [100, 120], Jazz: [70, 92], Rock: [112, 136], Soul: [78, 98] };
// Four-chord progressions; each chord is its root in semitones from the key and its third.
const PROGRESSIONS = [
  [[0, 4], [-5, 4], [-3, 3], [5, 4]],
  [[-3, 3], [5, 4], [0, 4], [-5, 4]],
  [[0, 4], [-3, 3], [-7, 4], [-5, 4]],
  [[2, 3], [-5, 4], [0, 4], [-3, 3]],
  [[0, 3], [-4, 4], [3, 4], [-2, 4]],
  [[0, 4], [5, 4], [0, 4], [-5, 4]],
];
// The steady signal: one partial on each centre frequency of the equalizer's bands, quieter
// towards the top, as [hertz, level].
const PARTIALS = [[31.5, 0.14], [63, 0.13], [125, 0.12], [250, 0.11], [500, 0.09], [1000, 0.08], [2000, 0.07], [4000, 0.06], [8000, 0.05], [16000, 0.04]];

// A repeatable random source for one text: the same text always yields the same numbers.
function randomFor(text) {
  let state = 2166136261;
  for (const character of text) {
    state = Math.imul(state ^ character.codePointAt(0), 16777619);
  }
  return () => {
    state = (state + 0x6d2b79f5) | 0;
    let mixed = Math.imul(state ^ (state >>> 15), 1 | state);
    mixed = (mixed + Math.imul(mixed ^ (mixed >>> 7), 61 | mixed)) ^ mixed;
    return ((mixed ^ (mixed >>> 14)) >>> 0) / 4294967296;
  };
}

// The expression of a constant multi-tone signal for ffmpeg's aevalsrc.
const steadySignal = () => PARTIALS.map(([hertz, level]) => `${level}*sin(2*PI*${hertz}*t)`).join('+');

/**
 * The expression of a small tune for ffmpeg's aevalsrc: plucked eighth notes that walk the tones
 * of a four-chord progression over a bass note on every half bar. Tempo, key, progression and
 * pattern come from the track's path, so each track sounds different and a rerun sounds the same.
 */
function tune(track, genre) {
  const random = randomFor(track.path);
  const pick = (low, high) => low + Math.floor(random() * (high - low + 1));
  const [slowest, fastest] = TEMPO[genre];
  const step = 30 / pick(slowest, fastest);
  const key = 196 * Math.exp(SEMITONE * pick(0, 7));
  const chords = PROGRESSIONS[pick(0, PROGRESSIONS.length - 1)];
  const stride = [1, 3][pick(0, 1)];
  const drift = pick(0, 2);
  const overtone = pick(15, 45) / 100;
  // The value of the bar the progression is in, held in variable 2.
  const byBar = values => values.slice(0, -1).reduceRight((rest, value, bar) => `if(lt(ld(2),${bar + 1}),${value},${rest})`, values.at(-1));

  return [
    `st(0,floor(t/${step}))`, // the eighth note being played
    `st(1,t-ld(0)*${step})`, // the time since it started
    'st(2,mod(floor(ld(0)/8),4))',
    `st(3,${byBar(chords.map(([root]) => root))})`,
    `st(4,${byBar(chords.map(([, third]) => third))})`,
    `st(5,mod(ld(0)*${stride}+floor(ld(0)/8)*${drift},4))`, // which chord tone the note takes
    `st(6,${key}*exp(${SEMITONE}*(ld(3)+if(lt(ld(5),1),0,if(lt(ld(5),2),ld(4),if(lt(ld(5),3),7,12))))))`,
    `st(7,1-ld(1)/${step})`,
    `st(8,t-floor(t/${step * 4})*${step * 4})`, // the time since the bass note started
    `st(9,1-ld(8)/${step * 4})`,
    `min(1,min(t,${track.duration}-t)/2)*(`
      + `0.34*ld(7)*ld(7)*min(1,ld(1)*300)*(sin(2*PI*ld(6)*ld(1))+${overtone}*sin(4*PI*ld(6)*ld(1)))`
      + `+0.3*ld(9)*min(1,ld(8)*200)*sin(2*PI*${key / 4}*exp(${SEMITONE}*ld(3))*ld(8)))`,
  ].join(';');
}

async function filesIn(directory) {
  const entries = await readdir(directory, { recursive: true, withFileTypes: true }).catch((error) => {
    if (error.code === 'ENOENT') return [];
    throw error;
  });
  return entries.filter(entry => !entry.isDirectory()).map(entry => relative(directory, join(entry.parentPath, entry.name)));
}

// Removes the files of an earlier run, or stops when the directory holds anything else.
async function clearEarlierRun(output) {
  const present = await filesIn(output);
  const earlier = present.includes(MARKER) ? JSON.parse(await readFile(join(output, MARKER), 'utf8')).files : [];
  const foreign = present.filter(file => file !== MARKER && !earlier.includes(file));
  if (foreign.length > 0) {
    throw new Error(`Refusing to write into ${output}: it holds ${foreign.length} file(s) this script did not create, such as ${foreign[0]}.`);
  }
  await Promise.all(earlier.map(file => rm(join(output, file), { force: true })));
}

async function renderCovers(albums, directory) {
  const browser = await chromium.launch();
  try {
    const page = await browser.newPage({ viewport: { width: 1000, height: 1000 }, deviceScaleFactor: 1 });
    const covers = new Map();
    for (const [index, album] of albums.entries()) {
      const path = join(directory, `${index}.png`);
      await page.setContent(`<style>html,body{margin:0}svg{display:block}</style>${album.coverSvg}`);
      await page.screenshot({ path });
      covers.set(album, path);
    }
    return covers;
  } finally {
    await browser.close();
  }
}

async function writeTrack(output, album, track, coverPath) {
  const tags = {
    TITLE: track.title,
    ARTIST: album.artist,
    ALBUM: album.title,
    ALBUMARTIST: album.artist,
    TRACKNUMBER: track.number,
    DISCNUMBER: 1,
    DATE: album.year,
    GENRE: album.genre,
  };
  const signal = track.steady ? steadySignal() : tune(track, album.genre);
  await ffmpeg('ffmpeg', [
    '-v', 'error', '-y',
    '-f', 'lavfi', '-i', `aevalsrc=exprs='${signal}':s=${SAMPLE_RATE}:d=${track.duration}`,
    '-i', coverPath,
    '-map', '0:a', '-map', '1:v',
    '-filter:a', 'pan=stereo|c0=c0|c1=c0',
    '-c:a', 'flac', '-compression_level', '12', '-sample_fmt', 's16',
    '-c:v', 'copy', '-disposition:v', 'attached_pic', '-metadata:s:v', 'comment=Cover (front)',
    ...Object.entries(tags).flatMap(([name, value]) => ['-metadata', `${name}=${value}`]),
    '-bitexact', join(output, track.path),
  ]);
  if (track.lyrics) {
    await writeFile(join(output, track.lyrics.path), track.lyrics.text);
  }
}

async function main() {
  if (process.argv.length !== 3) {
    throw new Error('Usage: node ui/site/scripts/capture/sample-library.mjs <output-directory>');
  }
  const output = resolve(process.argv[2]);
  const { artists, albums } = buildManifest();
  const jobs = albums.flatMap(album => album.tracks.map(track => ({ album, track })));
  const files = jobs.flatMap(({ track }) => [track.path, ...(track.lyrics ? [track.lyrics.path] : [])]);

  await clearEarlierRun(output);
  await Promise.all(albums.map(album => mkdir(join(output, album.directory), { recursive: true })));
  await writeFile(join(output, MARKER), `${JSON.stringify({ files }, null, 2)}\n`);

  const scratch = await mkdtemp(join(tmpdir(), 'sample-library-'));
  try {
    const covers = await renderCovers(albums, scratch);
    const workers = Array.from({ length: availableParallelism() }, async () => {
      for (let job = jobs.shift(); job; job = jobs.shift()) {
        await writeTrack(output, job.album, job.track, covers.get(job.album));
      }
    });
    await Promise.all(workers);
  } finally {
    await rm(scratch, { recursive: true, force: true });
  }

  const sizes = await Promise.all(files.map(file => stat(join(output, file))));
  const megabytes = sizes.reduce((sum, { size }) => sum + size, 0) / 1e6;
  const steady = albums.flatMap(album => album.tracks).find(track => track.steady);
  console.log(`Wrote ${files.filter(file => file.endsWith('.flac')).length} tracks on ${albums.length} albums by ${artists.length} artists, `
    + `${files.filter(file => file.endsWith('.lrc')).length} of them with lyrics, to ${output} (${megabytes.toFixed(1)} MB).`);
  console.log(`The steady multi-tone track is ${steady.path}.`);
}

try {
  await main();
} catch (error) {
  console.error(error.message);
  process.exitCode = 1;
}
