import type { AnalysisData } from '@/features/player/services/audio-processor'

/** Extended visualizer modes including new Canvas/WebGL renderers. */
export type VisualizerMode =
  | 'spectrum'
  | 'meters'
  | 'phase'
  | 'enhanced-spectrum'
  | 'circular'
  | 'spectrogram'
  | 'particles'

/** All modes that use the new visualizer engine (not the legacy EQ panel modes). */
export const ENGINE_MODES: readonly VisualizerMode[] = [
  'enhanced-spectrum',
  'circular',
  'spectrogram',
  'particles',
] as const

/** Modes handled by the legacy EQ panel (spectrum/meters/phase). */
export const LEGACY_MODES: readonly VisualizerMode[] = [
  'spectrum',
  'meters',
  'phase',
] as const

/** Check whether a mode uses the new visualizer engine. */
export function isEngineMode(mode: VisualizerMode): boolean {
  return (ENGINE_MODES as readonly string[]).includes(mode)
}

/** Color palette extracted from album art. */
export interface PaletteColors {
  /** Hex — dominant color */
  primary: string
  /** Hex — secondary accent */
  secondary: string
  /** Hex — highlight/energy */
  accent: string
  /** Hex — dark background tint */
  background: string
  /** Whether the palette is predominantly dark */
  isDark: boolean
}

/** Context provided during renderer initialization. Data is not yet available. */
export interface InitContext {
  /** Canvas pixel width. */
  width: number
  /** Canvas pixel height. */
  height: number
  /** Smoothing alpha for EMA. */
  smoothingAlpha: number
  /** Whether this is a compact/mini context. */
  compact: boolean
}

/** Rendering context provided to each renderer on every frame. */
export interface RenderContext {
  /** Canvas 2D rendering context (null for WebGL renderers that manage their own). */
  ctx: CanvasRenderingContext2D | null
  /** Current audio analysis data. */
  data: AnalysisData
  /** Album art color palette (null if not yet extracted). */
  palette: PaletteColors | null
  /** Time since last frame in milliseconds. */
  deltaTime: number
  /** Canvas pixel width. */
  width: number
  /** Canvas pixel height. */
  height: number
  /** Smoothing alpha for EMA (0-1). Renderers should apply: prev * (1-alpha) + target * alpha. */
  smoothingAlpha: number
  /** Whether this is a compact/mini renderer context. */
  compact: boolean
}

/** Pluggable renderer interface — each visualizer implements this contract. */
export interface VisualizerRenderer {
  /** Unique identifier matching a VisualizerMode value. */
  readonly id: VisualizerMode

  readonly isWebGL: boolean

  /** Initialize the renderer. Called once when the renderer is activated. May return a Promise for async setup (e.g., Three.js lazy load). */
  init(canvas: HTMLCanvasElement, context: InitContext): void | Promise<void>

  /** Render one frame. Called at ~60fps via requestAnimationFrame. */
  render(context: RenderContext): void

  /** Handle canvas resize. Called on ResizeObserver trigger. */
  resize(width: number, height: number): void

  /** Clean up all resources (GPU textures, event listeners, Three.js objects). */
  destroy(): void
}
