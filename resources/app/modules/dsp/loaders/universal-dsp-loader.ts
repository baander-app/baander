import { DspLoader, DspModuleType } from './dsp-loader.interface';

/**
 * Universal DSP loader for both Web and Electron
 *
 * Uses relative paths that work in both contexts:
 * - Web: /dsp/* (Vite handles the path resolution)
 * - Electron: ./dsp/* (works with file:// protocol)
 */
export class UniversalDspLoader implements DspLoader {
  private readonly basePath = './dsp/';

  async loadJsModule<T>(module: DspModuleType): Promise<T> {
    const url = `${this.basePath}${module}.js`;
    const response = await fetch(url);

    if (!response.ok) {
      throw new Error(`Failed to load DSP JS module: ${url} (status: ${response.status})`);
    }

    const code = await response.text();
    const blob = new Blob([code], { type: 'application/javascript' });
    const blobUrl = URL.createObjectURL(blob);

    try {
      const mod = await import(/* @vite-ignore */ blobUrl);
      return mod as T;
    } finally {
      URL.revokeObjectURL(blobUrl);
    }
  }

  getWasmUrl(module: DspModuleType): string {
    return `${this.basePath}${module}.wasm`;
  }
}
