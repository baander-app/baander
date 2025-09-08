export type Platform = 'electron' | 'web' | 'unknown';

export function isElectron() {
  return typeof window.BaanderElectron !== 'undefined';
}

export function isWeb() {
  return typeof window.BaanderAppConfig !== 'undefined';
}

export function getPlatform(): Platform {
  if (isElectron()) {
    return 'electron';
  }
  if (isWeb()) {
    return 'web';
  }

  return 'unknown';
}
