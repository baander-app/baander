import { create } from 'zustand'

export type EqProfileIcon = 'headphones' | 'speakers' | 'hifi-speaker' | 'wireless-speaker' | 'car' | 'tv' | 'monitor' | 'custom'

export interface EqProfile {
  id: string
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
  setProfiles: (profiles: EqProfile[]) => void
  addProfile: (profile: EqProfile) => void
  updateProfile: (id: string, updates: Partial<EqProfile>) => void
  removeProfile: (id: string) => void
  setActiveProfileId: (id: string) => void
  setLoaded: (loaded: boolean) => void
}

export const useEqProfilesStore = create<EqProfilesState>()((set) => ({
  profiles: [],
  activeProfileId: null,
  loaded: false,

  setProfiles: (profiles) => {
    set({ profiles, loaded: true })
  },

  addProfile: (profile) => {
    set((s) => ({ profiles: [...s.profiles, profile] }))
  },

  updateProfile: (id, updates) => {
    set((s) => ({
      profiles: s.profiles.map((p) => (p.id === id ? { ...p, ...updates } : p)),
    }))
  },

  removeProfile: (id) => {
    set((s) => ({
      profiles: s.profiles.filter((p) => p.id !== id),
      activeProfileId: s.activeProfileId === id
        ? s.profiles.find((p) => p.isDefault)?.id ?? null
        : s.activeProfileId,
    }))
  },

  setActiveProfileId: (id) => {
    set({ activeProfileId: id })
  },

  setLoaded: (loaded) => {
    set({ loaded })
  },
}))
