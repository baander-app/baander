export const LIVE_FAMILIES = [
  'catalog',
  'activity',
  'playlists',
  'libraries',
  'notifications',
  'account',
  'preferences',
  'devices',
  'sessions',
  'radio',
  'lyrics',
  'recommendations',
  'admin-users',
  'admin-settings',
  'admin-jobs',
  'admin-runtime',
  'admin-alerts',
  'admin-security',
  'admin-metadata',
  'admin-media',
  'deployment-definitions',
] as const;

export type LiveFamily = typeof LIVE_FAMILIES[number];
export type LiveMessageType = 'live.ready' | 'live.changed' | 'live.reset' | 'live.health';

export interface LiveHealth {
  state: 'current' | 'degraded';
  oldestPendingAgeMs: number | null;
}

export interface LiveMessage {
  version: 1;
  type: LiveMessageType;
  subscriptionId: string;
  authorizationGeneration: string;
  authorizationFingerprint: string;
  families: LiveFamily[];
  health: LiveHealth;
}

export const LIVE_MAX_BYTES = 65_536;

export function liveSubscription() {
  return { type: 'live.subscribe', version: 1 } as const;
}

function record(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

export function isLiveFamily(value: unknown): value is LiveFamily {
  return typeof value === 'string' && LIVE_FAMILIES.some(family => family === value);
}

export function parseLiveMessage(value: unknown): LiveMessage | null {
  if (!record(value) || Object.keys(value).length !== 7 || value.version !== 1) return null;
  if (typeof value.type !== 'string' || !['live.ready', 'live.changed', 'live.reset', 'live.health'].includes(value.type)) return null;
  if (typeof value.subscriptionId !== 'string' || value.subscriptionId.length !== 32 || !/^[0-9a-f]{32}$/.test(value.subscriptionId)) return null;
  if (typeof value.authorizationFingerprint !== 'string' || value.authorizationFingerprint.length !== 64 || !/^[0-9a-f]{64}$/.test(value.authorizationFingerprint)) return null;
  const generation = value.authorizationGeneration;
  if (typeof generation !== 'string' || !/^[1-9][0-9]{0,18}$/.test(generation) || /\D/.test(generation)
    || (generation.length === 19 && generation > '9223372036854775807')) return null;
  if (!Array.isArray(value.families) || value.families.length > LIVE_FAMILIES.length
    || !value.families.every(isLiveFamily) || new Set(value.families).size !== value.families.length) return null;
  const health = value.health;
  if (!record(health) || Object.keys(health).length !== 2
    || (health.state !== 'current' && health.state !== 'degraded')
    || (health.oldestPendingAgeMs !== null && (
      typeof health.oldestPendingAgeMs !== 'number'
      || !Number.isSafeInteger(health.oldestPendingAgeMs) || health.oldestPendingAgeMs < 0
    ))) return null;
  return value as unknown as LiveMessage;
}

