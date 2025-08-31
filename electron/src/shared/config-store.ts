import { app, safeStorage } from 'electron';
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';

type ConfigShape = {
  serverUrl?: string;
};

const CONFIG_PATH = join(app.getPath('userData'), 'config.json');

function ensureDir(filePath: string) {
  mkdirSync(dirname(filePath), { recursive: true });
}

export function loadConfig(): ConfigShape {
  try {
    const raw = readFileSync(CONFIG_PATH, 'utf8');
    return JSON.parse(raw) as ConfigShape;
  } catch {
    return {};
  }
}

export function saveConfig(config: ConfigShape) {
  ensureDir(CONFIG_PATH);
  writeFileSync(CONFIG_PATH, JSON.stringify(config, null, 2), 'utf8');
}

export function getServerUrl(): string | undefined {
  const cfg = loadConfig();
  return cfg.serverUrl;
}

export function setServerUrl(url: string | undefined) {
  const cfg = loadConfig();
  cfg.serverUrl = url;
  saveConfig(cfg);
}

export function getUserName(): string | undefined {
  return safeStorage
}
