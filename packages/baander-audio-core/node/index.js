/* ESM entry of @baander/audio-core.  A Node-API addon has no ESM import
 * form, so the compiled binding is loaded through createRequire.  It is
 * looked for, in this order, at the path in BAANDER_AUDIO_CORE_BINDING when
 * that is set, in build/Release and build/Debug, where node-gyp,
 * @electron/rebuild and electron-builder put a local build (a deliberate
 * local build wins over a fetched binary), and in prebuilds/<platform>-<arch>/
 * (the release asset the install script fetches; a Node-API binding is
 * ABI-stable across Node and Electron versions, so the path carries no
 * runtime version). */
import { existsSync } from 'node:fs';
import { createRequire } from 'node:module';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const require = createRequire(import.meta.url);
const root = join(dirname(fileURLToPath(import.meta.url)), '..');

function candidates() {
  const paths = [
    join(root, 'build', 'Release', 'baander_audio_core.node'),
    join(root, 'build', 'Debug', 'baander_audio_core.node'),
    join(root, 'prebuilds', `${process.platform}-${process.arch}`, 'baander_audio_core.node'),
  ];
  const override = process.env.BAANDER_AUDIO_CORE_BINDING;
  return override ? [override, ...paths] : paths;
}

function load() {
  const failures = [];
  for (const path of candidates()) {
    if (!existsSync(path)) {
      failures.push(`${path}: not found`);
      continue;
    }
    try {
      return require(path);
    } catch (error) {
      failures.push(`${path}: ${error && error.message}`);
    }
  }
  throw new Error(
    '@baander/audio-core: no loadable binding (run "npm run prebuild:download" in the package, ' +
      '"npm rebuild @baander/audio-core" or "yarn electron-rebuild"); tried:\n  ' +
      failures.join('\n  '),
  );
}

const binding = load();

export const AacDecoder = binding.AacDecoder;
export const AacEncoder = binding.AacEncoder;
export const FlacDecoder = binding.FlacDecoder;
export const FlacEncoder = binding.FlacEncoder;
export const measureLoudness = binding.measureLoudness;
export const version = binding.version;
export default binding;
