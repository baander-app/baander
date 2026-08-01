import { create } from 'zustand'

interface MergeState {
  isOpen: boolean
  sourceId: string | null
  targetId: string | null
  sourceTitle: string | null
  targetTitle: string | null
  openMerge: (sourceId: string, sourceTitle: string, targetId?: string, targetTitle?: string) => void
  setTarget: (targetId: string, targetTitle: string) => void
  closeMerge: () => void
}

export const useMergeStore = create<MergeState>((set) => ({
  isOpen: false,
  sourceId: null,
  targetId: null,
  sourceTitle: null,
  targetTitle: null,
  openMerge: (sourceId, sourceTitle, targetId, targetTitle) =>
    set({
      isOpen: true,
      sourceId,
      sourceTitle,
      targetId: targetId ?? null,
      targetTitle: targetTitle ?? null,
    }),
  setTarget: (targetId, targetTitle) => set({ targetId, targetTitle }),
  closeMerge: () => set({ isOpen: false, sourceId: null, targetId: null, sourceTitle: null, targetTitle: null }),
}))
