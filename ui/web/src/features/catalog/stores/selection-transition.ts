import type { DebugValue, StoreTransitionDescriptor } from '@/shared/stores/debug'

export type CatalogItemType = 'album' | 'artist' | 'song' | 'genre'

export interface SelectionData {
  selectedId: string | null
  selectedType: CatalogItemType | null
}

type SelectionCommand = { action: 'select'; id: string; type: CatalogItemType } | { action: 'clear' }

export function calculateSelection(command: SelectionCommand): SelectionData {
  return command.action === 'clear'
    ? { selectedId: null, selectedType: null }
    : { selectedId: command.id, selectedType: command.type }
}

function isItemType(value: unknown): value is CatalogItemType {
  return value === 'album' || value === 'artist' || value === 'song' || value === 'genre'
}

function isSelectionData(value: DebugValue): value is SelectionData & Record<string, DebugValue> {
  if (!value || typeof value !== 'object' || Array.isArray(value)) return false
  if (Object.keys(value).length !== 2 || !Object.hasOwn(value, 'selectedId') || !Object.hasOwn(value, 'selectedType')) return false
  return (value.selectedId === null && value.selectedType === null)
    || (typeof value.selectedId === 'string' && isItemType(value.selectedType))
}

function validArgs(action: string, args: DebugValue): args is DebugValue[] {
  if (!Array.isArray(args)) return false
  return action === 'clear' ? args.length === 0
    : action === 'select' && args.length === 2 && typeof args[0] === 'string' && isItemType(args[1])
}

export const selectionTransition: StoreTransitionDescriptor = {
  id: 'catalog.selection',
  version: 1,
  actions: ['select', 'clear'],
  validateState: isSelectionData,
  validateArgs: validArgs,
  calculate(state, action, args) {
    if (!isSelectionData(state) || !validArgs(action, args)) throw new Error('Invalid selection transition inputs')
    const result = action === 'clear' ? calculateSelection({ action })
      : calculateSelection({ action: 'select', id: args[0] as string, type: args[1] as CatalogItemType })
    return { ...result }
  },
}
