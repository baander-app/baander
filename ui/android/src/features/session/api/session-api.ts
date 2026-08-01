/**
 * Session API -- API client for session/queue sync endpoints.
 *
 * Uses shared Axios instance with DPoP auth.
 * Includes X-Device-Id header for multi-device awareness.
 */

import { catalogApi } from '@/features/catalog/api/catalog-api';
import { getAuthSnapshot } from '@/features/auth/stores/auth-store';

/**
 * Session types.
 */
export interface Session {
  uuid: string;
  publicId: string;
  deviceName: string;
  lastActiveAt: string;
  queue: SessionTrack[];
}

export interface SessionTrack {
  publicId: string;
  title: string;
  artistName: string | null;
  albumName: string | null;
  albumPublicId: string | null;
  duration: number | null;
  position: number;
}

/**
 * Device types.
 */
export interface Device {
  deviceId: string;
  deviceName: string;
  registeredAt: string;
}

/** Get device ID from auth store or generate a default. */
function getDeviceId(): string {
  const auth = getAuthSnapshot();
  return auth.user?.email ?? 'android-device';
}

/** Fetch current session state including queue. */
export async function getSession(): Promise<Session> {
  const { data } = await catalogApi.get<{ data: Session }>('/api/session', {
    headers: { 'X-Device-Id': getDeviceId() },
  });
  return data.data;
}

/** Sync local queue to server. */
export async function syncSession(queue: SessionTrack[]): Promise<Session> {
  const { data } = await catalogApi.put<{ data: Session }>(
    '/api/session/queue',
    { queue },
    { headers: { 'X-Device-Id': getDeviceId() } },
  );
  return data.data;
}

/** Register this device for multi-device awareness. */
export async function registerDevice(deviceName: string): Promise<Device> {
  const { data } = await catalogApi.post<{ data: Device }>('/api/session/devices', {
    deviceId: getDeviceId(),
    deviceName,
  });
  return data.data;
}
