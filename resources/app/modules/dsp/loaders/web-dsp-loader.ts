import { DspLoader, DspModuleType } from './dsp-loader.interface';

export class WebDspLoader implements DspLoader {
  private readonly jsBasePath = '/dsp/';
  private readonly wasmBasePath = '/dsp/';

  async loadJsModule<T>(module: DspModuleType): Promise<T> {
    const url = `${this.jsBasePath}${module}.js`;
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
    return `${this.wasmBasePath}${module}.wasm`;
  }
}
