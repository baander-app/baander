import { rendererRegistry } from './renderer-registry'
import { EnhancedSpectrumRenderer } from './renderers/enhanced-spectrum'
import { CircularSpectrumRenderer } from './renderers/circular-spectrum'
import { SpectrogramRenderer } from './renderers/spectrogram'
import { ParticleRenderer } from './renderers/particle'
import type { VisualizerMode } from './types'

let registered = false

/** Register all visualizer renderers with the global registry. Idempotent. */
export function registerVisualizerRenderers(): void {
  if (registered) return
  rendererRegistry.register('enhanced-spectrum', () => new EnhancedSpectrumRenderer())
  rendererRegistry.register('circular', () => new CircularSpectrumRenderer())
  rendererRegistry.register('spectrogram', () => new SpectrogramRenderer())
  rendererRegistry.register('particles', () => new ParticleRenderer())
  registered = true
}

/** Map a visualizer mode to its compact/mini equivalent. Particles → enhanced-spectrum (no Three.js for mini). */
export function getCompactMode(mode: VisualizerMode): VisualizerMode {
  return mode === 'particles' ? 'enhanced-spectrum' : mode
}
