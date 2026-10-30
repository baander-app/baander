import { withNoopGuard } from '@/shared/stores/with-noop-guard'
import { withStoreDebug } from '@/shared/stores/debug'
import { create } from 'zustand'

export type EqProfileIcon = 'headphones' | 'speakers' | 'hifi-speaker' | 'wireless-speaker' | 'car' | 'tv' | 'monitor' | 'custom'

export interface EqProfile {
  publicId: string
  name: string
  icon: EqProfileIcon
  deviceId?: string
  payload: Record<string, unknown>
  isDefault: boolean
  sortOrder: number
  version: number
}

export interface EqProfilesState {
  profiles: EqProfile[]
  activeProfileId: string | null
  loaded: boolean

  // Actions
  resetForPrincipal: () => void
  setProfiles: (profiles: EqProfile[]) => void
  addProfile: (profile: EqProfile) => void
  updateProfile: (id: string, updates: Partial<EqProfile>) => void
  removeProfile: (id: string) => void
  setActiveProfileId: (id: string) => void
  setLoaded: (loaded: boolean) => void
}

export const useEqProfilesStore = create<EqProfilesState>()(withStoreDebug('equalizer.eq-profiles', withNoopGuard((set) => ({
  profiles: [],
  activeProfileId: null,
  loaded: false,

  resetForPrincipal: () => set({ profiles: [], activeProfileId: null, loaded: false }),

  setProfiles: (profiles) => {
    set({ profiles, loaded: true })
  },

  addProfile: (profile) => {
    set((s) => s.profiles.some((item) => item.publicId === profile.publicId) ? s : { profiles: [...s.profiles, profile] })
  },

  updateProfile: (id, updates) => {
    set((s) => !s.profiles.some((profile) => profile.publicId === id && Object.entries(updates).some(([key, value]) => profile[key as keyof EqProfile] !== value)) ? s : ({
      profiles: s.profiles.map((p) => (p.publicId === id ? { ...p, ...updates } : p)),
    }))
  },

  removeProfile: (id) => {
    set((s) => !s.profiles.some((profile) => profile.publicId === id) ? s : ({
      profiles: s.profiles.filter((p) => p.publicId !== id),
      activeProfileId: s.activeProfileId === id
        ? s.profiles.find((p) => p.publicId !== id && p.isDefault)?.publicId ?? null
        : s.activeProfileId,
    }))
  },

  setActiveProfileId: (id) => {
    set({ activeProfileId: id })
  },

  setLoaded: (loaded) => {
    set({ loaded })
  },
}))))
