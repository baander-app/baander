/* The prebuild installer against a stand-in GitHub releases API on the
 * loopback interface: it trusts only the hashes committed in the package's
 * prebuilds.sha256.  The script runs from a copy in a temporary package
 * root, so the package's own prebuilds/ is never touched, and node-gyp is a
 * stub there that records the source build install mode falls back to. */
import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { createHash } from 'node:crypto';
import { copyFileSync, existsSync, mkdirSync, mkdtempSync, readdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { createServer } from 'node:http';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { test } from 'node:test';
import { fileURLToPath } from 'node:url';

const script = fileURLToPath(new URL('../scripts/fetch-prebuilds.mjs', import.meta.url));
const repo = 'baander-app/baander';
const version = '3.1.4';
const tag = `audio-core-v${version}`;
const target = `${process.platform}-${process.arch}`;
const asset = `baander_audio_core-${target}.node`;
const header = '# <sha256>  <release tag>/<asset name>, as the release job prints them\n';

const sha256 = (bytes) => createHash('sha256').update(bytes).digest('hex');

function packageRoot(t, hashList) {
  const root = mkdtempSync(join(tmpdir(), 'baander-audio-core-fetch-'));
  t.after(() => rmSync(root, { recursive: true, force: true }));
  mkdirSync(join(root, 'node', 'scripts'), { recursive: true });
  copyFileSync(script, join(root, 'node', 'scripts', 'fetch-prebuilds.mjs'));
  writeFileSync(
    join(root, 'package.json'),
    JSON.stringify({ name: '@baander/audio-core', version, repository: { type: 'git', url: `https://github.com/${repo}.git` } }),
  );
  writeFileSync(join(root, 'prebuilds.sha256'), hashList);
  const nodeGyp = join(root, 'node_modules', 'node-gyp');
  mkdirSync(join(nodeGyp, 'bin'), { recursive: true });
  writeFileSync(join(nodeGyp, 'package.json'), JSON.stringify({ name: 'node-gyp', version: '0.0.0' }));
  writeFileSync(
    join(nodeGyp, 'bin', 'node-gyp.js'),
    "require('node:fs').writeFileSync(require('node:path').join(__dirname, '..', 'ran.json'), JSON.stringify({ args: process.argv.slice(2) }));\n" +
      'process.exit(1);\n',
  );
  return root;
}

/* `releases` maps a tag to its assets, { name: bytes }; every request
 * path is recorded in `requests`. */
async function standInApi(t, releases) {
  const requests = [];
  const releaseJson = (name) => ({
    tag_name: name,
    draft: false,
    assets: Object.keys(releases[name]).map((file) => ({
      name: file,
      url: `${origin}/assets/${encodeURIComponent(name)}/${encodeURIComponent(file)}`,
    })),
  });
  const server = createServer((request, response) => {
    requests.push(request.url);
    const path = decodeURIComponent(new URL(request.url, origin).pathname);
    const send = (status, body, type) => {
      response.writeHead(status, { 'content-type': type });
      response.end(body);
    };
    if (path === `/repos/${repo}/releases`) {
      return send(200, JSON.stringify(Object.keys(releases).map(releaseJson)), 'application/json');
    }
    const byTag = path.startsWith(`/repos/${repo}/releases/tags/`) && path.slice(`/repos/${repo}/releases/tags/`.length);
    if (byTag && releases[byTag]) {
      return send(200, JSON.stringify(releaseJson(byTag)), 'application/json');
    }
    const [, kind, name, file] = path.split('/');
    if (kind === 'assets' && releases[name] && releases[name][file]) {
      return send(200, releases[name][file], 'application/octet-stream');
    }
    return send(404, '{"message":"Not Found"}', 'application/json');
  });
  await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
  const origin = `http://127.0.0.1:${server.address().port}`;
  t.after(() => new Promise((resolve) => server.close(resolve)));
  return { origin, requests };
}

function fetchPrebuilds(root, api, args = [], extraEnv = {}) {
  const env = { ...process.env, BAANDER_AUDIO_CORE_PREBUILD_API: api, BAANDER_AUDIO_CORE_PREBUILD_REPO: repo, ...extraEnv };
  for (const name of [
    'GITHUB_TOKEN',
    'GH_TOKEN',
    'BAANDER_AUDIO_CORE_BUILD_FROM_SOURCE',
    'npm_config_build_from_source',
    'HTTP_PROXY',
    'HTTPS_PROXY',
    'http_proxy',
    'https_proxy',
    'NODE_USE_ENV_PROXY',
  ]) {
    delete env[name];
  }
  if (!('BAANDER_AUDIO_CORE_PREBUILD_TAG' in extraEnv)) delete env.BAANDER_AUDIO_CORE_PREBUILD_TAG;
  return new Promise((resolve, reject) => {
    const child = spawn(process.execPath, [join(root, 'node', 'scripts', 'fetch-prebuilds.mjs'), ...args], {
      env,
      stdio: ['ignore', 'ignore', 'pipe'],
    });
    let stderr = '';
    child.stderr.setEncoding('utf8').on('data', (chunk) => {
      stderr += chunk;
    });
    child.on('error', reject);
    child.on('close', (status) => resolve({ status, stderr }));
  });
}

const placedFiles = (root) => (existsSync(join(root, 'prebuilds')) ? readdirSync(join(root, 'prebuilds'), { recursive: true }) : []);

const releaseJobBuild = Buffer.from('the binding the release job built\n');
const replacement = Buffer.from('a binding put in its place later\n');

test('refuses an asset that matches no committed hash, whatever the release lists in its own SHA256SUMS', async (t) => {
  const root = packageRoot(t, `${header}${sha256(releaseJobBuild)}  ${tag}/${asset}\n`);
  const api = await standInApi(t, {
    [tag]: { [asset]: replacement, SHA256SUMS: Buffer.from(`${sha256(replacement)}  ${asset}\n`) },
  });

  const { status, stderr } = await fetchPrebuilds(root, api.origin);

  assert.equal(status, 1, stderr);
  assert.match(stderr, new RegExp(`${sha256(replacement)} does not match the committed ${sha256(releaseJobBuild)}`));
  assert.deepEqual(placedFiles(root), [], 'nothing is written under prebuilds/');
  assert.ok(!api.requests.some((path) => path.includes('SHA256SUMS')), `requests: ${api.requests.join(', ')}`);
});

test('refuses a release that lacks the asset', async (t) => {
  const root = packageRoot(t, `${header}${sha256(releaseJobBuild)}  ${tag}/${asset}\n`);
  const api = await standInApi(t, { [tag]: { 'baander_audio_core-plan9-mips.node': releaseJobBuild } });

  const { status, stderr } = await fetchPrebuilds(root, api.origin);

  assert.equal(status, 1, stderr);
  assert.match(stderr, new RegExp(`release ${tag} of ${repo} has no ${asset}`));
  assert.deepEqual(placedFiles(root), []);
});

test('refuses before any request when the list has no entry for the release and asset', async (t) => {
  const root = packageRoot(t, `${header}\n${sha256(releaseJobBuild)}  audio-core-v3.1.3/${asset}\n`);
  const api = await standInApi(t, { [tag]: { [asset]: releaseJobBuild } });

  const { status, stderr } = await fetchPrebuilds(root, api.origin);

  assert.equal(status, 1, stderr);
  assert.match(stderr, new RegExp(`prebuilds\\.sha256 has no entry for ${tag}/${asset}`));
  assert.deepEqual(api.requests, []);
});

test('looks up the pinned release tag in the list', async (t) => {
  const root = packageRoot(t, `${header}${sha256(releaseJobBuild)}  ${tag}/${asset}\n`);
  const api = await standInApi(t, { 'audio-core-v3.1.3': { [asset]: releaseJobBuild } });

  const { status, stderr } = await fetchPrebuilds(root, api.origin, [], { BAANDER_AUDIO_CORE_PREBUILD_TAG: 'audio-core-v3.1.3' });

  assert.equal(status, 1, stderr);
  assert.match(stderr, new RegExp(`prebuilds\\.sha256 has no entry for audio-core-v3\\.1\\.3/${asset}`));
  assert.deepEqual(api.requests, []);
});

test('refuses the whole list when a line is malformed', async (t) => {
  for (const line of [
    `${sha256(releaseJobBuild).toUpperCase()}  ${tag}/baander_audio_core-plan9-mips.node`,
    `${sha256(releaseJobBuild)} ${tag}/baander_audio_core-plan9-mips.node`,
    `${sha256(releaseJobBuild)}  baander_audio_core-plan9-mips.node`,
    `${sha256(releaseJobBuild)}  ${tag}/${asset}`,
    ` # an indented comment`,
  ]) {
    const root = packageRoot(t, `${header}${sha256(releaseJobBuild)}  ${tag}/${asset}\n${line}\n`);
    const api = await standInApi(t, { [tag]: { [asset]: releaseJobBuild } });

    const { status, stderr } = await fetchPrebuilds(root, api.origin);

    assert.equal(status, 1, `${line}: ${stderr}`);
    assert.match(stderr, /prebuilds\.sha256 line 3 /, line);
    assert.deepEqual(api.requests, [], line);
  }
});

test('takes bytes that match the committed hash on to the load check', async (t) => {
  const root = packageRoot(t, `${header}${sha256(releaseJobBuild)}  ${tag}/${asset}\n`);
  const api = await standInApi(t, { [tag]: { [asset]: releaseJobBuild } });

  const { status, stderr } = await fetchPrebuilds(root, api.origin);

  assert.equal(status, 1, stderr);
  assert.match(stderr, /does not load/);
  assert.ok(api.requests.includes(`/assets/${tag}/${encodeURIComponent(asset)}`), `requests: ${api.requests.join(', ')}`);
  assert.deepEqual(readdirSync(join(root, 'prebuilds', target)), [], 'the partial download is removed');
});

test('install mode builds from source after a mismatch, places no binary and exits zero', async (t) => {
  const root = packageRoot(t, `${header}${sha256(releaseJobBuild)}  ${tag}/${asset}\n`);
  const api = await standInApi(t, {
    [tag]: { [asset]: replacement, SHA256SUMS: Buffer.from(`${sha256(replacement)}  ${asset}\n`) },
  });

  const { status, stderr } = await fetchPrebuilds(root, api.origin, ['--install']);

  assert.equal(status, 0, stderr);
  assert.match(stderr, /does not match the committed/);
  assert.deepEqual(placedFiles(root), [], 'nothing is written under prebuilds/');
  const build = JSON.parse(readFileSync(join(root, 'node_modules', 'node-gyp', 'ran.json'), 'utf8'));
  assert.deepEqual(build.args, ['rebuild']);
});
