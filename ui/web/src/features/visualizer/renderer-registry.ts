import type { VisualizerRenderer, VisualizerMode } from './types'

/**
 * Registry for visualizer renderers. Modules register their renderers on import;
 * VisualizerHost looks them up by mode.
 */
class RendererRegistry {
  private readonly renderers = new Map<VisualizerMode, () => VisualizerRenderer>()

  /** Register a renderer factory by mode. Idempotent — re-registration overwrites. */
  register(mode: VisualizerMode, factory: () => VisualizerRenderer): void {
    this.renderers.set(mode, factory)
  }

  /** Create a new renderer instance for the given mode. Returns null if not registered. */
  create(mode: VisualizerMode): VisualizerRenderer | null {
    const factory = this.renderers.get(mode)
    return factory ? factory() : null
  }

  /** Check whether a renderer is registered for the given mode. */
  has(mode: VisualizerMode): boolean {
    return this.renderers.has(mode)
  }

  /** List all registered mode identifiers. */
  modes(): VisualizerMode[] {
    return Array.from(this.renderers.keys())
  }
}

/** Singleton registry instance. */
export const rendererRegistry = new RendererRegistry()
