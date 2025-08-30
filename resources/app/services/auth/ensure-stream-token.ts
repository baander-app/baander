import { Token } from '@/services/auth/token.ts';
import { refreshToken } from '@/services/auth/refresh-token.service.ts';

let inFlightStream: Promise<void> | undefined;
let lastStreamRefreshAt = 0;
const COOLDOWN_MS = 15_000; // prevent tight loops if backend rejects temporarily

// Call this where you build stream URLs (player, media loader, etc.)
export async function ensureStreamToken(): Promise<string | undefined> {
  const current = Token.getStreamToken?.();
  if (current && !Token.isExpired(current.expiresAt)) {
    return current.token;
  }

  const now = Date.now();
  if (now - lastStreamRefreshAt < COOLDOWN_MS && inFlightStream) {
    // Just wait for the ongoing attempt if we recently tried
    await inFlightStream.catch(() => {});
  }

  if (!inFlightStream) {
    inFlightStream = (async () => {
      try {
        await refreshToken('stream');
        lastStreamRefreshAt = Date.now();
      } finally {
        inFlightStream = undefined;
      }
    })();
  }

  try {
    await inFlightStream;
  } catch {
    // leave token as-is; caller can decide what to do
  }

  return Token.getStreamToken?.()?.token;
}
