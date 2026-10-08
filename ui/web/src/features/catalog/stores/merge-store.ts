import { withNoopGuard } from '@/shared/stores/with-noop-guard'
import { withStoreDebug } from '@/shared/stores/debug'
import { create } from 'zustand'

interface MergeState {
  isOpen: boolean
  sourcePublicId: string | null
  targetPublicId: string | null
  sourceTitle: string | null
  targetTitle: string | null
  openMerge: (sourcePublicId: string, sourceTitle: string, targetPublicId?: string, targetTitle?: string) => void
  setTarget: (targetPublicId: string, targetTitle: string) => void
  closeMerge: () => void
}

export const useMergeStore = create<MergeState>()(withStoreDebug('catalog.merge', withNoopGuard((set) => ({
  isOpen: false,
  sourcePublicId: null,
  targetPublicId: null,
  sourceTitle: null,
  targetTitle: null,
  openMerge: (sourcePublicId, sourceTitle, targetPublicId, targetTitle) =>
    set({
      isOpen: true,
      sourcePublicId,
      sourceTitle,
      targetPublicId: targetPublicId ?? null,
      targetTitle: targetTitle ?? null,
    }),
  setTarget: (targetPublicId, targetTitle) => set({ targetPublicId, targetTitle }),
  closeMerge: () => set({ isOpen: false, sourcePublicId: null, targetPublicId: null, sourceTitle: null, targetTitle: null }),
}))))
