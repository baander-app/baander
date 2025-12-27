import { isElectron } from '@/app/utils/platform';
import { DspLoader } from './dsp-loader.interface';
import { WebDspLoader } from './web-dsp-loader';
import { ElectronDspLoader } from './electron-dsp-loader';

// Singleton instances
let webLoader: WebDspLoader | null = null;
let electronLoader: ElectronDspLoader | null = null;

/**
 * Resolve the appropriate DSP loader for the current platform
 * Follows the established pattern from credentialStore.ts
 */
export function resolveDspLoader(): DspLoader {
  if (isElectron()) {
    if (!electronLoader) {
      electronLoader = new ElectronDspLoader();
    }
    return electronLoader;
  }

  if (!webLoader) {
    webLoader = new WebDspLoader();
  }
  return webLoader;
}

// Export for testing
export function resetLoaders(): void {
  webLoader = null;
  electronLoader = null;
}
