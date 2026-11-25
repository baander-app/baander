#!/usr/bin/env node
/* Fetches the prebuilt binding for this platform and architecture from the
 * GitHub release `audio-core-v<version>` of this package's version in the
 * repository named in package.json into
 * prebuilds/<platform>-<arch>/baander_audio_core.node.  The download is
 * trusted only through prebuilds.sha256, committed in this package: its
 * SHA-256 must equal the line for `<release tag>/<asset name>`.  Without
 * such a line nothing is requested, and bytes with another hash are never
 * written.  Checksums published in the release itself are not read, since
 * whoever can replace an asset can replace them too.  The verified file is
 * then loaded once in a child process to prove it is the right binary and
 * version.
 *
 *   node node/scripts/fetch-prebuilds.mjs [--force]
 *       Download; an existing binding is kept unless --force is given.
 *       Exits nonzero when nothing could be fetched and verified.
 *   node node/scripts/fetch-prebuilds.mjs --install
 *       The package's install script: the same, but when the download is
 *       not possible or not verified (offline, no release, a proxy, no
 *       committed hash, a hash that differs) it builds the binding from
 *       source with node-gyp instead, so an install never depends on the
 *       network when a toolchain is present.  It always exits zero: when
 *       neither works it says so and leaves the binding to a later
 *       `node-gyp rebuild` or `electron-rebuild`, instead of failing the
 *       whole install of the application that depends on this package.
 *
 * Environment: GITHUB_TOKEN or GH_TOKEN authenticates the API calls (needed
 * for a private repository and for busy CI machines, optional otherwise),
 * BAANDER_AUDIO_CORE_PREBUILD_REPO (owner/name) and BAANDER_AUDIO_CORE_PREBUILD_TAG pin
 * the release, BAANDER_AUDIO_CORE_PREBUILD_API replaces https://api.github.com (a
 * GitHub Enterprise host, or a local stand-in under test) and
 * BAANDER_AUDIO_CORE_BUILD_FROM_SOURCE=1 (or npm's --build-from-source)
 * skips the download in install mode. */
import { spawnSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { existsSync, mkdirSync, readFileSync, renameSync, rmSync, writeFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const require = createRequire(import.meta.url);
const root = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const pkg = JSON.parse(readFileSync(join(root, 'package.json'), 'utf8'));
const target = `${process.platform}-${process.arch}`;
const directory = join(root, 'prebuilds', target);
const binding = join(directory, 'baander_audio_core.node');
const assetName = `baander_audio_core-${target}.node`;
const hashList = 'prebuilds.sha256';

const args = new Set(process.argv.slice(2));
const installing = args.has('--install');
const force = args.has('--force');
const env = process.env;
/* Unless pinned, the release of this package's version: a binary of another
 * version may export another surface. */
const releaseTag = env.BAANDER_AUDIO_CORE_PREBUILD_TAG || `audio-core-v${pkg.version}`;

function log(message) {
  console.error(`@baander/audio-core: ${message}`);
}

function repository() {
  const pinned = env.BAANDER_AUDIO_CORE_PREBUILD_REPO;
  if (pinned) return pinned;
  const url = typeof pkg.repository === 'string' ? pkg.repository : pkg.repository && pkg.repository.url;
  const match = /github\.com[/:]([^/]+\/[^/]+?)(?:\.git)?\/?$/.exec(url || '');
  if (!match) throw new Error('package.json names no GitHub repository');
  return match[1];
}

async function github(url, accept, timeoutMs) {
  const headers = {
    accept,
    'user-agent': `${pkg.name} prebuild fetch`,
    'x-github-api-version': '2022-11-28',
  };
  const token = env.GITHUB_TOKEN || env.GH_TOKEN;
  if (token) headers.authorization = `Bearer ${token}`;
  const response = await fetch(url, { headers, signal: AbortSignal.timeout(timeoutMs) });
  if (!response.ok) throw new Error(`${url}: HTTP ${response.status}`);
  return response;
}

/* The committed hash of `key` (`<release tag>/<asset name>`) from
 * prebuilds.sha256, whose lines are sha256sum's "<hex>  <name>" with the
 * tag as a directory.  Blank lines and lines that begin with # are skipped;
 * any other line that is not exactly that shape, or that repeats a name,
 * refuses the whole list rather than being passed over. */
function committedHash(key) {
  const entries = new Map();
  readFileSync(join(root, hashList), 'utf8')
    .split(/\r?\n/)
    .forEach((line, index) => {
      if (line.trim() === '' || line.startsWith('#')) return;
      const entry = /^([0-9a-f]{64}) {2}(audio-core-v[^\s/]+\/baander_audio_core-[^\s/]+\.node)$/.exec(line);
      if (!entry) throw new Error(`${hashList} line ${index + 1} is not "<sha256>  <release tag>/<asset name>": ${line}`);
      if (entries.has(entry[2])) throw new Error(`${hashList} line ${index + 1} repeats ${entry[2]}`);
      entries.set(entry[2], entry[1]);
    });
  const hash = entries.get(key);
  if (!hash) throw new Error(`${hashList} has no entry for ${key}`);
  return hash;
}

async function findRelease(repo) {
  const api = `${(env.BAANDER_AUDIO_CORE_PREBUILD_API || 'https://api.github.com').replace(/\/$/, '')}/repos/${repo}/releases`;
  if (env.BAANDER_AUDIO_CORE_PREBUILD_TAG) {
    return (await github(`${api}/tags/${encodeURIComponent(releaseTag)}`, 'application/vnd.github+json', 20000)).json();
  }
  const releases = await (await github(`${api}?per_page=100`, 'application/vnd.github+json', 20000)).json();
  const release = releases.find((r) => !r.draft && r.tag_name === releaseTag);
  if (!release) throw new Error(`no release ${releaseTag} in ${repo}`);
  return release;
}

/* Loads the binding in a child process, so that a wrong or damaged binary
 * cannot take this process down, and returns the version it reports.
 * process.dlopen takes any file name, so the partial download can be
 * checked before it is given its final name. */
function loadedVersion(path) {
  const script =
    'const m = { exports: {} }; process.dlopen(m, process.argv[1]);' +
    ' if (typeof m.exports.version !== "string") throw new Error("the module exports no version");' +
    ' process.stdout.write(m.exports.version)';
  const result = spawnSync(process.execPath, ['-e', script, path], { encoding: 'utf8', timeout: 30000 });
  if (result.status !== 0) {
    const lines = (result.stderr || '').split('\n').filter((l) => l.trim());
    const reason = lines.find((l) => /\bError: /.test(l)) || lines[0] || `exit status ${result.status}`;
    throw new Error(`${path} does not load: ${reason.trim()}`);
  }
  return result.stdout.trim();
}

async function download() {
  const key = `${releaseTag}/${assetName}`;
  const expected = committedHash(key);
  const repo = repository();
  const asset = ((await findRelease(repo)).assets || []).find((a) => a.name === assetName);
  if (!asset) throw new Error(`release ${releaseTag} of ${repo} has no ${assetName}`);
  const bytes = Buffer.from(await (await github(asset.url, 'application/octet-stream', 120000)).arrayBuffer());
  const actual = createHash('sha256').update(bytes).digest('hex');
  if (actual !== expected) throw new Error(`${key}: sha256 ${actual} does not match the committed ${expected}`);
  mkdirSync(directory, { recursive: true });
  const partial = `${binding}.download`;
  writeFileSync(partial, bytes, { mode: 0o755 });
  try {
    const version = loadedVersion(partial);
    if (version !== pkg.version) {
      throw new Error(`${assetName} reports version ${version}, this package is ${pkg.version}`);
    }
    renameSync(partial, binding);
    log(`fetched ${assetName} ${version} from release ${releaseTag} of ${repo}`);
  } catch (error) {
    rmSync(partial, { force: true });
    throw error;
  }
}

function buildFromSource() {
  let command;
  let commandArgs;
  try {
    command = process.execPath;
    commandArgs = [require.resolve('node-gyp/bin/node-gyp.js'), 'rebuild'];
  } catch {
    command = process.platform === 'win32' ? 'node-gyp.cmd' : 'node-gyp';
    commandArgs = ['rebuild'];
  }
  const result = spawnSync(command, commandArgs, { cwd: root, stdio: 'inherit', shell: command !== process.execPath });
  if (result.error) throw result.error;
  return result.status === null ? 1 : result.status;
}

async function main() {
  if (!force && existsSync(binding)) {
    log(`keeping the existing ${binding} (pass --force to replace it)`);
    return 0;
  }
  const fromSource = installing && (env.BAANDER_AUDIO_CORE_BUILD_FROM_SOURCE === '1' || env.npm_config_build_from_source === 'true');
  if (!fromSource) {
    try {
      await download();
      return 0;
    } catch (error) {
      const reason = error instanceof Error ? error.message : String(error);
      if (!installing) {
        log(`no prebuilt binding for ${target}: ${reason}`);
        return 1;
      }
      log(`no prebuilt binding for ${target} (${reason}); building from source with node-gyp`);
    }
  } else {
    log('building from source with node-gyp as requested');
  }
  const status = buildFromSource();
  if (status !== 0 && installing) {
    log('the binding could not be fetched or built; run "npm rebuild @baander/audio-core" or "yarn electron-rebuild" before using it');
    return 0;
  }
  return status;
}

main().then(
  (code) => process.exit(code),
  (error) => {
    log(error instanceof Error ? error.message : String(error));
    process.exit(installing ? 0 : 1);
  },
);
