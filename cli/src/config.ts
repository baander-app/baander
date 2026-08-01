import { readFileSync, existsSync, mkdirSync, writeFileSync } from 'fs';
import { resolve } from 'path';
import { homedir } from 'os';
import { ConfigError } from './lib/errors';
import { warn } from './lib/output';

export interface BaanderConfig {
  /** Command to run when `baander` is invoked with no arguments */
  defaultCommand?: string;
  /** Manifest staleness threshold in seconds (default: 3600 = 1 hour) */
  manifestStaleSeconds?: number;
}

const DEFAULT_CONFIG: BaanderConfig = {
  defaultCommand: 'help',
  manifestStaleSeconds: 3600,
};

function getXdgConfigHome(): string {
  return process.env.XDG_CONFIG_HOME ?? resolve(homedir(), '.config');
}

function getXdgCacheHome(): string {
  return process.env.XDG_CACHE_HOME ?? resolve(homedir(), '.cache');
}

export function getConfigDir(): string {
  return resolve(getXdgConfigHome(), 'baander');
}

export function getCacheDir(): string {
  return resolve(getXdgCacheHome(), 'baander');
}

export function getConfigPath(): string {
  return resolve(getConfigDir(), 'config.json');
}

export function getManifestCachePath(): string {
  return resolve(getCacheDir(), 'manifest.json');
}

export function ensureCacheDir(): void {
  const cacheDir = getCacheDir();
  if (!existsSync(cacheDir)) {
    mkdirSync(cacheDir, { recursive: true });
  }
}

export function loadConfig(): BaanderConfig {
  const configPath = getConfigPath();

  if (!existsSync(configPath)) {
    return { ...DEFAULT_CONFIG };
  }

  try {
    const raw = readFileSync(configPath, 'utf-8');
    const parsed = JSON.parse(raw);

    return {
      ...DEFAULT_CONFIG,
      ...parsed,
    };
  } catch (e: unknown) {
    if (e instanceof SyntaxError) {
      warn(`Invalid JSON in ${configPath}. Using defaults.`);
      return { ...DEFAULT_CONFIG };
    }
    throw e;
  }
}

export function saveConfig(config: BaanderConfig): void {
  const configDir = getConfigDir();
  if (!existsSync(configDir)) {
    mkdirSync(configDir, { recursive: true });
  }
  writeFileSync(getConfigPath(), JSON.stringify(config, null, 2) + '\n');
}
