import { app, protocol } from 'electron';
import * as path from 'node:path';
import { dirname } from 'node:path';
import * as url from 'node:url';
import { fileURLToPath } from 'node:url';
import * as fs from 'node:fs';
import * as crypto from 'node:crypto';
import { isDev } from '../../shared/env';

// Register the custom scheme as privileged before the app is ready
// This allows the Fetch API to use this protocol
protocol.registerSchemesAsPrivileged([
  {
    scheme: 'baander',
    privileges: {
      standard: true,
      secure: true,
      supportFetchAPI: true,
      corsEnabled: true,
    },
  },
]);

const __filename = fileURLToPath(import.meta.url);
const __dirname = dirname(__filename);

const PROTOCOL = 'baander';

// Load expected hashes at build time
let expectedHashes: Record<string, string> = {};

try {
  // wasm-hashes.json is in the same directory as the bundled main process
  const hashesPath = path.join(__dirname, 'wasm-hashes.json');
  expectedHashes = JSON.parse(fs.readFileSync(hashesPath, 'utf-8'));
  console.log('[wasm-protocol] Loaded integrity hashes for WASM files');
} catch (error) {
  if (isDev()) {
    console.warn('[wasm-protocol] Could not load WASM hashes, integrity verification disabled:', error);
  } else {
    throw error;
  }
}

/**
 * Verify the SHA-256 hash of a file
 */
async function verifyFileHash(filePath: string, moduleName: string): Promise<boolean> {
  // Skip verification in development or if hashes are not available
  if (!app.isPackaged || !expectedHashes[moduleName]) {
    return true;
  }

  const expectedHash = expectedHashes[moduleName];

  try {
    const fileBuffer = fs.readFileSync(filePath);
    const actualHash = crypto.createHash('sha256').update(fileBuffer).digest('hex');

    if (actualHash !== expectedHash) {
      console.error(`[wasm-protocol] Hash mismatch for ${moduleName}!`);
      console.error(`[wasm-protocol] Expected: ${expectedHash}`);
      console.error(`[wasm-protocol] Actual:   ${actualHash}`);
      return false;
    }

    console.log(`[wasm-protocol] Verified ${moduleName} integrity`);
    return true;
  } catch (error) {
    console.error(`[wasm-protocol] Failed to verify ${moduleName}:`, error);
    return false;
  }
}

/**
 * Register a custom protocol to serve WASM and audio worklet files from the Resources directory
 */
export function registerWasmProtocol(): void {
  protocol.handle(PROTOCOL, async (request) => {
    const reqUrl = url.parse(request.url);

    // For custom protocols, url.parse() doesn't include the host in pathname
    // We need to manually construct it: baander://dsp/file.wasm -> /dsp/file.wasm
    const host = reqUrl.host || '';
    const pathname = reqUrl.pathname || '';
    const relativePath = host ? `/${host}${pathname}` : pathname;

    console.log(`[wasm-protocol] Request: ${request.url} -> host: ${host}, pathname: ${pathname}, relativePath: ${relativePath}`);

    // Extract module name from path for WASM files (e.g., "spectral_features" from "/dsp/spectral_features.wasm")
    const wasmMatch = relativePath.match(/\/dsp\/(.+)\.wasm$/);
    const moduleName = wasmMatch ? wasmMatch[1] : null;

    // In development, serve from public/
    // In production, serve from Resources directory
    let fullPath: string;

    if (process.env.VITE_DEV_SERVER_URL) {
      // Development: serve from public/
      fullPath = path.join(process.cwd(), 'public', relativePath);
    } else {
      // Production: serve from Resources directory
      const resourcesPath = process.resourcesPath;
      fullPath = path.join(resourcesPath, relativePath);

      console.log(`[wasm-protocol] resourcesPath: ${resourcesPath}`);
      console.log(`[wasm-protocol] fullPath: ${fullPath}`);

      // Verify file integrity before serving (production only, for WASM files)
      if (moduleName && !(await verifyFileHash(fullPath, moduleName))) {
        console.error(`[wasm-protocol] Refusing to serve tampered WASM file: ${moduleName}.wasm`);
        return new Response('WASM file integrity check failed', {status: 403});
      }
    }

    // Read file directly instead of using net.fetch to avoid session issues
    try {
      const fileBuffer = fs.readFileSync(fullPath);
      let contentType: string;

      if (relativePath.endsWith('.wasm')) {
        contentType = 'application/wasm';
      } else if (relativePath.endsWith('.js')) {
        contentType = 'application/javascript; charset=utf-8';
      } else {
        contentType = 'application/octet-stream';
      }

      return new Response(fileBuffer, {
        headers: {
          'Content-Type': contentType,
          'Content-Length': fileBuffer.length.toString(),
        },
      });
    } catch (error) {
      console.error(`[wasm-protocol] Failed to read file: ${fullPath}`, error);
      return new Response('File not found', {status: 404});
    }
  });

  console.log(`[wasm-protocol] Registered custom protocol '${PROTOCOL}://' for serving WASM and audio worklet files`);
}

/**
 * Get the URL for a WASM file
 * @param relativePath - Relative path from Resources/dsp (e.g., 'spectral_features.wasm')
 * @returns Full URL using the custom protocol
 */
export function getWasmUrl(relativePath: string): string {
  return `${PROTOCOL}://dsp/${relativePath}`;
}

/**
 * Get the URL for an audio worklet file
 * @param filename - The worklet filename (e.g., 'audio-analysis-worker.js')
 * @returns Full URL using the custom protocol
 */
export function getAudioWorkletUrl(filename: string): string {
  return `${PROTOCOL}://audio-worklets/${filename}`;
}
