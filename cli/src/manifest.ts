import { readFileSync, existsSync, writeFileSync, statSync } from 'fs';
import { execInContainer, findProjectRoot } from './lib/docker.js';
import { ensureCacheDir, getManifestCachePath, loadConfig } from './config.js';
import { ManifestError } from './lib/errors.js';

export interface ConsoleCommand {
  name: string;
  description: string;
  aliases: string[];
  hidden: boolean;
  arguments: Array<{
    name: string;
    description: string;
    default: unknown;
    required: boolean;
  }>;
  options: Array<{
    name: string;
    shortcut: string | null;
    description: string;
    default: unknown;
    isValueRequired: boolean;
    isArray: boolean;
  }>;
}

export interface ToolingConfig {
  phpstan: {
    config: string;
    level: number | null;
    memoryLimit: string;
    paths: string[];
  };
  deptrac: {
    config: string;
    layers: string[];
  };
  composer: {
    scripts: Record<string, unknown>;
    autoload: Record<string, unknown>;
  };
  phpunit: {
    config: string;
    coverage: boolean;
  };
  paratest: {
    processes: string;
    config: string;
  };
}

export interface Manifest {
  console: {
    commands: ConsoleCommand[];
  };
  phpstan: ToolingConfig['phpstan'];
  deptrac: ToolingConfig['deptrac'];
  composer: ToolingConfig['composer'];
  phpunit: ToolingConfig['phpunit'];
  paratest: ToolingConfig['paratest'];
}

/** Cached config to avoid repeated file reads */
let cachedConfig: { staleSeconds: number } | null = null;

function getStaleSeconds(): number {
  if (!cachedConfig) {
    const config = loadConfig();
    cachedConfig = { staleSeconds: config.manifestStaleSeconds ?? 3600 };
  }
  return cachedConfig.staleSeconds;
}

/**
 * Check if the manifest cache is stale
 */
function isCacheStale(): boolean {
  const cachePath = getManifestCachePath();
  if (!existsSync(cachePath)) return true;

  try {
    const stat = statSync(cachePath);
    return Date.now() - stat.mtimeMs > getStaleSeconds() * 1000;
  } catch {
    return true;
  }
}

/**
 * Generate manifest by running app:cli:manifest in the container and capturing output
 */
export async function generateManifest(): Promise<string> {
  const projectRoot = findProjectRoot();
  ensureCacheDir();

  const { spawn } = await import('child_process');

  return new Promise<string>((resolvePromise, reject) => {
    const proc = spawn('docker', [
      'compose',
      '--env-file', '.env',
      '-f', 'docker-compose.yml',
      'exec',
      '-T',
      '-u', 'www-data',
      'app',
      'php', 'bin/console', 'app:cli:manifest', '--no-interaction',
    ], {
      cwd: projectRoot,
      env: {
        ...process.env,
        HOST_UID: String(process.getuid?.() ?? 1000),
        HOST_GID: String(process.getgid?.() ?? 1000),
      },
    });

    let stdout = '';
    let stderr = '';

    proc.stdout.on('data', (data: Buffer) => {
      stdout += data.toString();
    });

    proc.stderr.on('data', (data: Buffer) => {
      stderr += data.toString();
    });

    proc.on('close', (code: number) => {
      if (code !== 0) {
        reject(
          new ManifestError(
            `Failed to generate manifest (exit ${code}): ${stderr.slice(-200)}`,
          ),
        );
        return;
      }

      // Validate it's valid JSON
      try {
        JSON.parse(stdout);
      } catch {
        reject(new ManifestError('Manifest output is not valid JSON'));
        return;
      }

      // Save to cache
      const cachePath = getManifestCachePath();
      writeFileSync(cachePath, stdout);

      resolvePromise(stdout);
    });

    proc.on('error', (err: Error) => {
      reject(new ManifestError(`Failed to spawn docker: ${err.message}`));
    });
  });
}

/**
 * Load manifest from cache, regenerating if stale
 */
export async function loadManifest(): Promise<Manifest> {
  if (isCacheStale()) {
    await generateManifest();
  }

  const cachePath = getManifestCachePath();
  const raw = readFileSync(cachePath, 'utf-8');
  return JSON.parse(raw);
}

/**
 * Get all non-hidden console command names from manifest
 */
export async function getConsoleCommandNames(): Promise<string[]> {
  try {
    const manifest = await loadManifest();
    return manifest.console.commands
      .filter((c) => !c.hidden)
      .map((c) => c.name);
  } catch {
    return [];
  }
}
