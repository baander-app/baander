/**
 * Screen Wake Lock API hook
 *
 * Prevents the screen from turning off automatically during media playback.
 *
 * @see https://developer.mozilla.org/en-US/docs/Web/API/Screen_Wake_Lock_API
 */
export function useWakeLock(enabled = true) {
  let wakeLock: WakeLockSentinel | null = null;

  const request = async (): Promise<boolean> => {
    if (!enabled || !('wakeLock' in navigator)) {
      return false;
    }

    try {
      wakeLock = await navigator.wakeLock.request('screen');
      return true;
    } catch {
      return false;
    }
  };

  const release = (): void => {
    wakeLock?.release();
    wakeLock = null;
  };

  // Re-acquire wake lock when visibility changes (user returns to tab)
  if (enabled) {
    document.addEventListener('visibilitychange', async () => {
      if (document.visibilityState === 'visible' && wakeLock === null) {
        await request();
      }
    });
  }

  return {
    request,
    release,
    isSupported: 'wakeLock' in navigator,
  };
}
