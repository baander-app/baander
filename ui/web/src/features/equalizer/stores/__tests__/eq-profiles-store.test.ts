import { describe, expect, it, vi } from 'vitest'

const getProcessor = vi.hoisted(() => vi.fn())
vi.mock('@/features/player/services/audio-service', () => ({
  audioService: { getProcessor },
}))

import { useEqProfilesStore } from '../eq-profiles-store'
import { presetToBands, useEqBandsStore } from '../eq-bands-store'

describe('EQ profile ownership reset', () => {
  it('clears the server profile mirror without applying audio settings', () => {
    useEqBandsStore.setState({ enabled: true, preset: 'ROCK', bands: presetToBands('ROCK') })
    const audioSettings = useEqBandsStore.getState()
    useEqProfilesStore.getState().setProfiles([{
      publicId: 'private-profile',
      name: 'Private profile',
      icon: 'headphones',
      payload: { gain: 4 },
      isDefault: true,
      sortOrder: 0,
      version: 1,
    }])
    useEqProfilesStore.getState().setActiveProfileId('private-profile')

    useEqProfilesStore.getState().resetForPrincipal()

    expect(useEqProfilesStore.getState()).toMatchObject({
      profiles: [],
      activeProfileId: null,
      loaded: false,
    })
    expect(useEqBandsStore.getState()).toBe(audioSettings)
    expect(getProcessor).not.toHaveBeenCalled()
  })
})
