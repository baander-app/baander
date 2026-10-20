import { withNoopGuard } from '@/shared/stores/with-noop-guard'
import { withStoreDebug } from '@/shared/stores/debug'
import { create } from 'zustand'
import { calculateSelection, selectionTransition, type CatalogItemType, type SelectionData } from './selection-transition'

export type { CatalogItemType } from './selection-transition'

export interface SelectionState extends SelectionData {
  select: (id: string, type: CatalogItemType) => void
  clear: () => void
}

export const useSelectionStore = create<SelectionState>()(withStoreDebug('catalog.selection', withNoopGuard((set) => ({
  selectedId: null,
  selectedType: null,

  select: (id, type) => set(calculateSelection({ action: 'select', id, type })),
  clear: () => set(calculateSelection({ action: 'clear' })),
})), selectionTransition))
