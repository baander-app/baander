import { registerPlayerHandlers } from '@/features/player/stores/player-handlers'
import { registerRadioHandlers } from '@/features/radio/stores/radio-handlers'
import { registerEqHandlers } from '@/features/equalizer/stores/eq-handlers'
import { registerContextPanelHandlers } from '@/features/layout/stores/context-panel-handlers'

let registered = false

/**
 * Register all cross-context handler wiring.
 * Call once during app initialization (e.g., in App.tsx or main.tsx).
 * Idempotent — safe to call multiple times.
 */
export function registerAllHandlers() {
  if (registered) return
  registered = true

  registerPlayerHandlers()
  registerRadioHandlers()
  registerEqHandlers()
  registerContextPanelHandlers()
}
