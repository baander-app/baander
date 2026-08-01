import { create } from 'zustand'
import type { MediaSidebarSchema } from '../schemas/types'
import { ALL_SCHEMAS } from '../schemas'
import type { MediaType } from './media-mode-store'
import { useMediaModeStore } from './media-mode-store'

export interface SidebarItemData {
  id: string
  type: 'page_link' | 'smart_filter' | 'panel_action'
  label: string
  icon: string
  config: Record<string, unknown>
}

interface SidebarState {
  // Existing (backward compat)
  items: SidebarItemData[]
  isLoading: boolean
  error: string | null
  isEditorOpen: boolean
  // New — per-media-type schemas
  schemas: Record<MediaType, MediaSidebarSchema>
  // Actions
  setItems: (items: SidebarItemData[]) => void
  setLoading: (loading: boolean) => void
  setError: (error: string | null) => void
  setEditorOpen: (open: boolean) => void
  setSchema: (media: MediaType, schema: MediaSidebarSchema) => void
  getActiveSchema: () => MediaSidebarSchema
}

export const useSidebarStore = create<SidebarState>((set, get) => ({
  items: [],
  isLoading: false,
  error: null,
  isEditorOpen: false,
  schemas: { ...ALL_SCHEMAS },
  setItems: (items) => set({ items }),
  setLoading: (isLoading) => set({ isLoading }),
  setError: (error) => set({ error }),
  setEditorOpen: (isEditorOpen) => set({ isEditorOpen }),
  setSchema: (media, schema) =>
    set((state) => ({ schemas: { ...state.schemas, [media]: schema } })),
  getActiveSchema: () => {
    const activeMedia = useMediaModeStore.getState().activeMedia
    return get().schemas[activeMedia]
  },
}))
