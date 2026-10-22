/* ESM entry of @baander/aac-native.  A Node-API addon has no ESM import
 * form, so the compiled binding is loaded through createRequire from
 * build/Release (or build/Debug) of the package root, where node-gyp,
 * @electron/rebuild and electron-builder all put it. */
import { createRequire } from 'node:module';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const require = createRequire(import.meta.url);
const root = join(dirname(fileURLToPath(import.meta.url)), '..');

function load() {
  let lastError;
  for (const configuration of ['Release', 'Debug']) {
    try {
      return require(join(root, 'build', configuration, 'baander_aac.node'));
    } catch (error) {
      lastError = error;
    }
  }
  throw new Error(
    `@baander/aac-native: compiled binding not found (run "npm rebuild @baander/aac-native" ` +
      `or "yarn electron-rebuild"): ${lastError && lastError.message}`,
  );
}

const binding = load();

export const AacDecoder = binding.AacDecoder;
export const AacEncoder = binding.AacEncoder;
export const version = binding.version;
export default binding;
