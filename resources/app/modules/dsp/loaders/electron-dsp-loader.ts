import { DspLoader, DspModuleType } from './dsp-loader.interface';

/**
 * Electron-specific WASM loader
 * Uses bundled resources from electron/src/dsp/ and electron/public/dsp/
 *
 * In Electron:
 * - DSP JS files are at electron/src/dsp/*.js (imported via @/dsp alias)
 * - WASM files are at electron/public/dsp/*.wasm (bundled to ./dsp/*.wasm)
 */
export class ElectronDspLoader implements DspLoader {
  async loadJsModule<T>(module: DspModuleType): Promise<T> {
    // Import from electron/src/dsp/*.js using Vite alias
    const url = `@/dsp/${module}.js`;

    try {
      const mod = await import(url);
      return mod as T;
    } catch (error) {
      throw new Error(
        `Failed to load bundled DSP module: ${url}${error instanceof Error ? `: ${error.message}` : ''}`,
        { cause: error }
      );
    }
  }

  getWasmUrl(module: DspModuleType): string {
    // WASM files are copied from electron/public/dsp/ to renderer root as ./dsp/*.wasm
    return `./dsp/${module}.wasm`;
  }
}
