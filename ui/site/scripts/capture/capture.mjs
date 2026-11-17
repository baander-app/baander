// Retakes the website's pictures of the application from this checkout's preview:
//
//   node ui/site/scripts/capture/capture.mjs --base-url <preview url> [--out <directory>] [--scene <name>]
//
// The preview must hold the sample library of sample-library.mjs and nothing else. The script
// creates the three sample accounts when they are missing, then checks the preview and refuses
// to take a picture while anything differs: the accounts, the libraries, the catalog's artists
// and albums, the two settings that would look names up at a provider, and unfinished scans or
// background jobs. It never resets or deletes anything, and it opens no screen that asks a
// metadata or lyrics provider about a name.
//
// A scene signs in through the login page and does what its feature page tells a visitor to do.
// A step of it may work in the window of another sample account, and a scene about several
// browsers of one account also gets a window in Firefox, which needs Playwright's Firefox.
// Each picture is taken twice from one state, in the dark theme and in the light one, as a
// lossless WebP that is compared pixel for pixel with what the browser drew. The pairs go to
// ui/site/assets/screenshots, and the record of every picture, which the site build checks the
// files against, to ui/site/features/captures.json. With --out, a trial run writes the pairs and
// their record into that directory instead. A contact sheet of the run goes to
// var/site-capture/contact-sheet.html. It needs ffmpeg and the preview's Make target.
import { execFile, spawn } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { mkdir, readFile, writeFile } from 'node:fs/promises';
import { dirname, join, relative, resolve } from 'node:path';
import { parseArgs, promisify } from 'node:util';
import { chromium, firefox } from '@playwright/test';
import { SCALE, THEMES, WINDOW, chooseTheme, leave, openFromSidebar, openWindow, readCatalog, signIn } from './application.mjs';
import {
  SAMPLE_ACCOUNTS, SETTINGS_KEPT_OFF, accountProblems, catalogProblems, contactSheet, coverFailures, libraryProblems,
  mergeRecords, pendingWorkProblems, recordEntry, settingProblems, settingValue,
} from './checks.mjs';
import { buildManifest } from './library-manifest.mjs';
import administration from './scenes/administration.mjs';
import destinations from './scenes/destinations.mjs';
import equalizer from './scenes/equalizer.mjs';
import libraries from './scenes/libraries.mjs';
import library from './scenes/library.mjs';
import player from './scenes/player.mjs';
import playlists from './scenes/playlists.mjs';

const SCENES = [equalizer, library, player, playlists, destinations, libraries, administration];
const ROOT = resolve(import.meta.dirname, '../../../..');
// Everything under ui/site/assets is published with the site, so the record is kept beside the pages' data instead.
const PICTURES = join(ROOT, 'ui/site/assets/screenshots');
const RECORD = join(ROOT, 'ui/site/features/captures.json');
const SCRATCH = join(ROOT, 'var/site-capture');
const ACCOUNTS_FILE = join(SCRATCH, 'accounts.json');
const run = promisify(execFile);

// A picture the driver will not take, with the reason; the run goes on with the next picture.
class NotReady extends Error {}

/** Runs a console command in the preview's application container and returns what it printed. */
async function consoleCommand(words, input = '') {
  const quoted = words.map(word => (/^[\w@.:=/-]+$/.test(word) ? word : `'${word}'`));
  const child = spawn('make', ['--no-print-directory', 'exec', `cmd=php bin/console ${quoted.join(' ')}`], { cwd: ROOT, stdio: 'pipe' });
  let printed = '';
  let complaints = '';
  child.stdout.on('data', (chunk) => { printed += chunk; });
  child.stderr.on('data', (chunk) => { complaints += chunk; });
  child.stdin.end(input);
  const code = await new Promise((done, failed) => child.on('error', failed).on('close', done));
  if (code !== 0) {
    throw new Error(`The console command ${words[0]} failed (exit ${code}): ${(complaints || printed).trim()}`);
  }
  return printed;
}

const consoleJson = async words => JSON.parse(await consoleCommand([...words, '--json']));
const listUsers = () => consoleJson(['app:user:list', '--limit=100']);

/** Creates the sample accounts the preview lacks and returns every sample account's password, by address. */
async function ensureAccounts() {
  const passwords = JSON.parse(await readFile(ACCOUNTS_FILE, 'utf8').catch(() => '{}'));
  const present = new Set((await listUsers()).map(user => user.email));
  for (const account of SAMPLE_ACCOUNTS.filter(({ email }) => !present.has(email))) {
    // The password is kept before the account exists, so no account is left without one.
    passwords[account.email] = randomBytes(18).toString('base64url');
    await mkdir(SCRATCH, { recursive: true });
    await writeFile(ACCOUNTS_FILE, `${JSON.stringify(passwords, null, 2)}\n`, { mode: 0o600 });
    const elevated = account.roles.some(role => role !== 'user');
    await consoleCommand(
      ['app:user:create', account.email, account.name, '--password', ...account.roles.map(role => `--role=${role}`), ...(elevated ? ['--force'] : [])],
      passwords[account.email],
    );
    if (account.library) {
      await consoleCommand(['app:library:member:grant', account.email, account.library]);
    }
    console.log(`Created the sample account ${account.email}.`);
  }
  for (const { email } of SAMPLE_ACCOUNTS) {
    if (!passwords[email]) {
      throw new Error(`No password for ${email} is kept in ${relative(ROOT, ACCOUNTS_FILE)}. Set one with app:user:reset-password and record it there.`);
    }
  }
  return passwords;
}

/** What the console can tell is wrong with the preview. */
async function previewProblems() {
  const libraries = await consoleJson(['app:library:list']);
  const settings = {};
  for (const key of SETTINGS_KEPT_OFF) {
    settings[key] = settingValue(await consoleCommand(['app:settings:get', key]));
  }
  return [
    ...accountProblems(await listUsers()),
    ...libraryProblems(libraries),
    ...settingProblems(settings),
    ...pendingWorkProblems(await consoleJson(['app:monitor:status']), libraries),
  ];
}

function refuseOver(problems) {
  if (problems.length > 0) {
    throw new Error(`The preview is not ready for a capture:\n${problems.map(problem => `  ${problem}`).join('\n')}`);
  }
}

/** The newest commit that changed the application, which is what the pictures show. */
async function applicationCommit() {
  const { stdout: changes } = await run('git', ['status', '--porcelain', '--', 'src', 'ui/web'], { cwd: ROOT });
  if (changes.trim() !== '') {
    throw new Error(`The application has changes that are not committed, so no commit describes what the pictures would show:\n${changes.trimEnd()}`);
  }
  const { stdout: commit } = await run('git', ['log', '-1', '--format=%H', '--', 'src', 'ui/web'], { cwd: ROOT });
  return commit.trim();
}

/**
 * Stops the picture when its view is not finished: something lies over the page or a cover is
 * missing. A picture of an open dialog or menu names it as its `overlay`; that one must be open
 * and the others must not.
 */
async function requireFinishedView({ page, covers }, clip, wholeWindow, overlay) {
  await page.mouse.move(0, 0);
  for (const [name, selector] of [['dialog', '[role="dialog"]'], ['menu', '[role="menu"]'], ['notice', '[data-sonner-toast]']]) {
    const wanted = name === overlay;
    await page.locator(selector).first().waitFor({ state: wanted ? 'visible' : 'hidden', timeout: 15000 }).catch(() => {
      throw new NotReady(wanted ? `the ${name} the picture is of is not open` : `a ${name} lies over the page`);
    });
  }
  for (let waited = 0; covers.pending.size > 0; waited += 100) {
    if (waited >= 15000) throw new NotReady('a cover is still loading');
    await page.waitForTimeout(100);
  }
  // The application draws a placeholder where a cover failed, and nothing on the page says where
  // that is. A window shows every cover on screen, so a failure anywhere stops it; a crop is judged
  // by the images inside it, and its scene checks the covers it expects there.
  const [failure] = coverFailures(covers.failed);
  if (wholeWindow && failure) {
    throw new NotReady(`a cover did not load: ${failure}`);
  }
  const unloaded = await page.evaluate(frame => [...document.images].filter((image) => {
    const box = image.getBoundingClientRect();
    const inFrame = box.right > frame.x && box.left < frame.x + frame.width && box.bottom > frame.y && box.top < frame.y + frame.height;
    return inFrame && !(image.complete && image.naturalWidth > 0);
  }).length, clip);
  if (unloaded > 0) throw new NotReady(`${unloaded} image(s) in the frame have not loaded`);
}

/**
 * The whole-pixel rectangle around the elements of a crop, which must lie uncovered inside the
 * window. `underOverlay` says that the picture is of an open menu or dialog with the page behind
 * it. `margin` widens the rectangle on every side by that many CSS pixels of what surrounds the
 * elements, for a crop whose text would otherwise touch the picture's edge.
 */
async function cropAround(locators, underOverlay = false, margin = 0) {
  const boxes = await Promise.all(locators.map(locator => locator.boundingBox()));
  const left = Math.floor(Math.min(...boxes.map(box => box.x)));
  const top = Math.floor(Math.min(...boxes.map(box => box.y)));
  const right = Math.ceil(Math.max(...boxes.map(box => box.x + box.width)));
  const bottom = Math.ceil(Math.max(...boxes.map(box => box.y + box.height)));
  for (const locator of locators) {
    // The middle of each edge belongs to the element unless something covers it or it runs off the window.
    const uncovered = await locator.evaluate((element, behindOverlay) => {
      const box = element.getBoundingClientRect();
      const middle = [box.left + box.width / 2, box.top + box.height / 2];
      return [[middle[0], box.top + 2], [middle[0], box.bottom - 2], [box.left + 2, middle[1]], [box.right - 2, middle[1]]]
        .every(([x, y]) => {
          const hit = document.elementFromPoint(x, y);
          // While a menu or dialog is open the application takes the page behind it out of the
          // pointer's reach, and a point of the page that the overlay does not cover answers
          // with the document itself.
          return element.contains(hit) || (behindOverlay && hit === document.documentElement);
        });
    }, underOverlay);
    if (!uncovered) throw new NotReady('part of the crop is covered or outside the window');
  }
  const clip = { x: left - margin, y: top - margin, width: right - left + 2 * margin, height: bottom - top + 2 * margin };
  if (margin > 0 && (clip.x < 0 || clip.y < 0 || clip.x + clip.width > WINDOW.width || clip.y + clip.height > WINDOW.height)) {
    throw new NotReady('the crop with its margin runs off the window');
  }
  return clip;
}

const pngSize = png => ({ width: png.readUInt32BE(16), height: png.readUInt32BE(20) });

/** Takes one picture in both themes from the state the window is in and returns the two captures. */
async function takePair(session, step, scene) {
  const { page, appearance } = session;
  const shots = {};
  for (const theme of Object.keys(THEMES)) {
    await chooseTheme(appearance, page, theme);
    await step.before?.({ page });
    const clip = step.kind === 'window' ? { x: 0, y: 0, ...WINDOW } : await cropAround(step.region({ page }), Boolean(step.overlay), step.margin ?? 0);
    await requireFinishedView(session, clip, step.kind === 'window', step.overlay);
    // Playwright hides the text cursor with a style of its own unless told to leave it.
    shots[theme] = await page.screenshot({ clip, caret: 'initial' });
  }
  await chooseTheme(appearance, page, 'dark');

  const { width, height } = pngSize(shots.dark);
  const light = pngSize(shots.light);
  if (light.width !== width || light.height !== height) {
    throw new NotReady(`the dark picture is ${width} × ${height} px and the light one ${light.width} × ${light.height}`);
  }
  // A window is the whole window; a crop is no wider than it and halves to whole pixels.
  const allowed = step.kind === 'window'
    ? width === WINDOW.width * SCALE && height === WINDOW.height * SCALE
    : width <= WINDOW.width * SCALE && width % 2 === 0 && height % 2 === 0;
  if (!allowed) {
    throw new NotReady(`${width} × ${height} px is not a size a ${step.kind} picture may have`);
  }
  return { name: step.picture, scene: scene.name, kind: step.kind, width, height, ...shots };
}

const pixels = async file => (await run('ffmpeg', ['-v', 'error', '-i', file, '-f', 'rawvideo', '-pix_fmt', 'rgba', 'pipe:1'], { encoding: 'buffer', maxBuffer: 2 ** 28 })).stdout;

/** Writes a capture as a lossless WebP and requires that it decodes to the pixels the browser drew. */
async function encode(png, name, out) {
  const source = join(SCRATCH, 'work', `${name}.png`);
  const target = join(out, `${name}.webp`);
  await writeFile(source, png);
  await run('ffmpeg', ['-v', 'error', '-y', '-i', source, '-c:v', 'libwebp', '-lossless', '1', '-compression_level', '6', '-pix_fmt', 'bgra', target]);
  if (!(await pixels(source)).equals(await pixels(target))) {
    throw new Error(`${target} does not hold the pixels that were captured.`);
  }
  return readFile(target);
}

async function main() {
  const { values } = parseArgs({ options: { 'base-url': { type: 'string' }, out: { type: 'string' }, scene: { type: 'string' } } });
  const scenes = values.scene ? SCENES.filter(scene => scene.name === values.scene) : SCENES;
  if (!values['base-url'] || scenes.length === 0) {
    throw new Error(`Usage: node ui/site/scripts/capture/capture.mjs --base-url <preview url> [--out <directory>] [--scene ${SCENES.map(scene => scene.name).join('|')}]`);
  }
  const out = values.out ? resolve(values.out) : PICTURES;
  const recordFile = values.out ? join(out, 'captures.json') : RECORD;
  const commit = await applicationCommit();
  const passwords = await ensureAccounts();
  refuseOver(await previewProblems());

  const browser = await chromium.launch({ args: ['--mute-audio'] });
  const browsers = [browser];
  const pairs = [];
  const refused = [];
  try {
    // One signed-in window per account for the scenes, and a second for choosing the theme.
    const sessions = new Map();
    const sessionOf = async (email) => {
      if (!sessions.has(email)) {
        const account = { email, password: passwords[email] };
        const session = await openWindow(browser, values['base-url']);
        await signIn(session.page, account);
        const { page: appearance } = await openWindow(browser, values['base-url'], 1);
        await signIn(appearance, account);
        await openFromSidebar(appearance, 'Settings', '/settings');
        sessions.set(email, { ...session, appearance });
      }
      return sessions.get(email);
    };
    // A scene about several browsers of one account names the other makes it needs. It gets a
    // window in each, signed in as its account through the login page, which stays open until
    // the run ends. Firefox is launched as silent as Chromium is.
    const otherBrowsersOf = async (scene) => {
      const others = {};
      for (const make of scene.otherBrowsers ?? []) {
        const other = await { firefox }[make].launch({ firefoxUserPrefs: { 'media.volume_scale': '0.0' } });
        browsers.push(other);
        ({ page: others[make] } = await openWindow(other, values['base-url']));
        await signIn(others[make], { email: scene.account, password: passwords[scene.account] });
      }
      return others;
    };

    refuseOver(catalogProblems(buildManifest(), await readCatalog((await sessionOf(scenes[0].account)).page)));

    for (const scene of scenes) {
      const session = await sessionOf(scene.account);
      await chooseTheme(session.appearance, session.page, 'dark');
      const others = await otherBrowsersOf(scene);
      for (const step of scene.steps) {
        console.log(`${scene.name}: ${step.picture ?? step.title}`);
        // A step may name another sample account; it then works in, or is a picture of, that account's window.
        const window = step.account ? await sessionOf(step.account) : session;
        if (!step.picture) {
          await step.run({ page: window.page, others });
          continue;
        }
        try {
          pairs.push(await takePair(window, step, scene));
        } catch (error) {
          if (!(error instanceof NotReady)) throw error;
          refused.push(`${step.picture}: ${error.message}`);
          console.error(`  not taken: ${error.message}`);
        }
      }
    }
  } finally {
    const windows = browsers.flatMap(each => each.contexts()).flatMap(context => context.pages());
    await Promise.all(windows.map(window => leave(window).catch(() => {})));
    await Promise.all(browsers.map(each => each.close()));
  }

  await mkdir(join(SCRATCH, 'work'), { recursive: true });
  await mkdir(out, { recursive: true });
  const date = new Date().toISOString().slice(0, 10);
  const entries = {};
  for (const pair of pairs) {
    const dark = await encode(pair.dark, pair.name, out);
    const light = await encode(pair.light, `${pair.name}-light`, out);
    entries[pair.name] = recordEntry({ scene: pair.scene, commit, date, dark, light });
  }
  const earlier = JSON.parse(await readFile(recordFile, 'utf8').catch(() => '{}'));
  await writeFile(recordFile, `${JSON.stringify(mergeRecords(earlier, entries), null, 2)}\n`);

  const sheet = join(SCRATCH, 'contact-sheet.html');
  const beside = file => relative(dirname(sheet), join(out, file));
  await writeFile(sheet, contactSheet(pairs.map(pair => ({ ...pair, dark: beside(`${pair.name}.webp`), light: beside(`${pair.name}-light.webp`) }))));

  console.log(`Wrote ${pairs.length} pair(s) to ${out}, their records to ${recordFile} and the contact sheet to ${sheet}.`);
  if (refused.length > 0) {
    throw new Error(`Not taken:\n${refused.map(reason => `  ${reason}`).join('\n')}`);
  }
}

try {
  await main();
} catch (error) {
  console.error(error.message);
  process.exitCode = 1;
}
