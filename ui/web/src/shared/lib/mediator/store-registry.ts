import { usePlayerStore } from '@/features/player/stores/player-store'
import { useRadioStore } from '@/features/radio/stores/radio-store'
import { useEqBandsStore } from '@/features/equalizer/stores/eq-bands-store'
import { useEqProcessingStore } from '@/features/equalizer/stores/eq-processing-store'
import { useEqProfilesStore } from '@/features/equalizer/stores/eq-profiles-store'
import { useEqCompareStore } from '@/features/equalizer/stores/eq-compare-store'
import { useContextPanelStore } from '@/features/layout/stores/context-panel-store'
import { useSidebarStore } from '@/features/layout/stores/sidebar-store'
import { useSelectionStore } from '@/features/catalog/stores/selection-store'
import { useViewModeStore } from '@/features/catalog/stores/view-mode-store'
import { useListColumnStore } from '@/features/catalog/stores/list-column-store'

/**
 * Registry of all Zustand stores for the debug inspector.
 * Maps display names to their getState() snapshots.
 * Add new stores here as they're created.
 */
export const STORE_REGISTRY: Record<string, () => Record<string, unknown>> = {
  player: () => usePlayerStore.getState() as unknown as Record<string, unknown>,
  radio: () => useRadioStore.getState() as unknown as Record<string, unknown>,
  eqBands: () => useEqBandsStore.getState() as unknown as Record<string, unknown>,
  eqProcessing: () => useEqProcessingStore.getState() as unknown as Record<string, unknown>,
  eqProfiles: () => useEqProfilesStore.getState() as unknown as Record<string, unknown>,
  eqCompare: () => useEqCompareStore.getState() as unknown as Record<string, unknown>,
  contextPanel: () => useContextPanelStore.getState() as unknown as Record<string, unknown>,
  sidebar: () => useSidebarStore.getState() as unknown as Record<string, unknown>,
  selection: () => useSelectionStore.getState() as unknown as Record<string, unknown>,
  viewMode: () => useViewModeStore.getState() as unknown as Record<string, unknown>,
  listColumns: () => useListColumnStore.getState() as unknown as Record<string, unknown>,
}
