#!/usr/bin/env node
/* Fetches the prebuilt binding for this platform and architecture from the
 * GitHub release `aac-native-v<version>` of this package's version in the
 * repository named in package.json into
 * prebuilds/<platform>-<arch>/baander_aac.node, checks it against the
 * release's SHA256SUMS when the release carries one, and loads it once in
 * a child process to prove it is the right binary and version.
 *
 *   node node/scripts/fetch-prebuilds.mjs [--force]
 *       Download; an existing binding is kept unless --force is given.
 *       Exits nonzero when nothing could be fetched.
 *   node node/scripts/fetch-prebuilds.mjs --install
 *       The package's install script: the same, but when the download is
 *       not possible (offline, no release, a proxy) it builds the binding
 *       from source with node-gyp instead, so an install never depends on
 *       the network when a toolchain is present.  It always exits zero:
 *       when neither works it says so and leaves the binding to a later
 *       `node-gyp rebuild` or `electron-rebuild`, instead of failing the
 *       whole install of the application that depends on this package.
 *
 * Environment: GITHUB_TOKEN or GH_TOKEN authenticates the API calls (needed
 * for a private repository and for busy CI machines, optional otherwise),
 * BAANDER_AAC_PREBUILD_REPO (owner/name) and BAANDER_AAC_PREBUILD_TAG pin
 * the release, BAANDER_AAC_PREBUILD_API replaces https://api.github.com (a
 * GitHub Enterprise host, or a local stand-in under test) and
 * BAANDER_AAC_NATIVE_BUILD_FROM_SOURCE=1 (or npm's --build-from-source)
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
const binding = join(directory, 'baander_aac.node');
const assetName = `baander_aac-${target}.node`;
const tagPrefix = 'aac-native-v';

const args = new Set(process.argv.slice(2));
const installing = args.has('--install');
const force = args.has('--force');
const env = process.env;

function log(message) {
  console.error(`@baander/aac-native: ${message}`);
}

function repository() {
  const pinned = env.BAANDER_AAC_PREBUILD_REPO;
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

async function findRelease(repo) {
  const api = `${(env.BAANDER_AAC_PREBUILD_API || 'https://api.github.com').replace(/\/$/, '')}/repos/${repo}/releases`;
  const pinnedTag = env.BAANDER_AAC_PREBUILD_TAG;
  if (pinnedTag) {
    return (await github(`${api}/tags/${encodeURIComponent(pinnedTag)}`, 'application/vnd.github+json', 20000)).json();
  }
  /* The release of this package's version: a binary of another version
   * may export another surface. */
  const tag = `${tagPrefix}${pkg.version}`;
  const releases = await (await github(`${api}?per_page=100`, 'application/vnd.github+json', 20000)).json();
  const release = releases.find((r) => !r.draft && r.tag_name === tag);
  if (!release) throw new Error(`no release ${tag} in ${repo}`);
  return release;
}

async function downloadAsset(release, name, timeoutMs) {
  const asset = (release.assets || []).find((a) => a.name === name);
  if (!asset) return null;
  return Buffer.from(await (await github(asset.url, 'application/octet-stream', timeoutMs)).arrayBuffer());
}

/* Checks the asset against the SHA256SUMS of the release (sha256sum format:
 * "<hex>  <name>" per line) when the release has one. */
async function verifyChecksum(release, bytes) {
  const sums = await downloadAsset(release, 'SHA256SUMS', 20000);
  if (!sums) {
    log('the release carries no SHA256SUMS; the download is not checked');
    return;
  }
  const line = sums
    .toString('utf8')
    .split('\n')
    .find((l) => l.trim().endsWith(` ${assetName}`) || l.trim().endsWith(`*${assetName}`));
  if (!line) throw new Error(`SHA256SUMS has no entry for ${assetName}`);
  const expected = line.trim().split(/\s+/)[0].toLowerCase();
  const actual = createHash('sha256').update(bytes).digest('hex');
  if (actual !== expected) throw new Error(`${assetName}: checksum ${actual} differs from ${expected}`);
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
  const repo = repository();
  const release = await findRelease(repo);
  const bytes = await downloadAsset(release, assetName, 120000);
  if (!bytes) throw new Error(`release ${release.tag_name} of ${repo} has no ${assetName}`);
  await verifyChecksum(release, bytes);
  mkdirSync(directory, { recursive: true });
  const partial = `${binding}.download`;
  writeFileSync(partial, bytes, { mode: 0o755 });
  try {
    const version = loadedVersion(partial);
    if (version !== pkg.version) {
      throw new Error(`${assetName} reports version ${version}, this package is ${pkg.version}`);
    }
    renameSync(partial, binding);
    log(`fetched ${assetName} ${version} from release ${release.tag_name} of ${repo}`);
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
  const fromSource = installing && (env.BAANDER_AAC_NATIVE_BUILD_FROM_SOURCE === '1' || env.npm_config_build_from_source === 'true');
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
    log('the binding could not be fetched or built; run "npm rebuild @baander/aac-native" or "yarn electron-rebuild" before using it');
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
