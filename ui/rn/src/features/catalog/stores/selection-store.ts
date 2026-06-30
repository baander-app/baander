/**
 * Selection store -- platform-agnostic Zustand store.
 *
 * Tracks currently selected catalog item (album, artist, song, genre).
 * Used across mobile, desktop, and TV for detail view navigation.
 */

import { create } from 'zustand';

export type CatalogItemType = 'album' | 'artist' | 'song' | 'genre';

export interface SelectionState {
  selectedId: string | null;
  selectedType: CatalogItemType | null;
  select: (id: string, type: CatalogItemType) => void;
  clear: () => void;
}

export const useSelectionStore = create<SelectionState>()((set) => ({
  selectedId: null,
  selectedType: null,
  select: (id, type) => set({ selectedId: id, selectedType: type }),
  clear: () => set({ selectedId: null, selectedType: null }),
}));
