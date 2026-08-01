import { describe, it, expect, beforeEach } from 'vitest'
import { useEqBandsStore, DEFAULT_Q } from '@/features/equalizer/stores/eq-bands-store'

beforeEach(() => {
  localStorage.clear()
  useEqBandsStore.setState({
    enabled: true,
    bands: [...Array(10).fill(0)].map((gain) => ({ gain, q: DEFAULT_Q })),
    preset: 'FLAT',
    visualizerMode: 'spectrum',
    showSystemPanel: false,
  })
})

describe('eq-bands-store cyclePreset', () => {
  it('cycles from FLAT to ROCK', () => {
    expect(useEqBandsStore.getState().preset).toBe('FLAT')
    useEqBandsStore.getState().cyclePreset()
    expect(useEqBandsStore.getState().preset).toBe('ROCK')
  })

  it('cycles through all 9 presets and wraps back to FLAT', () => {
    const order = ['FLAT', 'ROCK', 'POP', 'JAZZ', 'CLASSICAL', 'BASS', 'TREBLE', 'VOCAL', 'LOUDNESS']
    for (const expected of order) {
      expect(useEqBandsStore.getState().preset).toBe(expected)
      useEqBandsStore.getState().cyclePreset()
    }
    expect(useEqBandsStore.getState().preset).toBe('FLAT')
  })

  it('wraps from LOUDNESS to FLAT', () => {
    useEqBandsStore.getState().setPreset('LOUDNESS')
    useEqBandsStore.getState().cyclePreset()
    expect(useEqBandsStore.getState().preset).toBe('FLAT')
  })

  it('updates bands when cycling presets', () => {
    useEqBandsStore.getState().cyclePreset() // FLAT -> ROCK
    const bands = useEqBandsStore.getState().bands
    const gains = bands.map((b) => b.gain)
    expect(gains).toEqual([4, 3, 1, -1, -2, 0, 1, 3, 4, 4])
  })

  it('bands have gain and q properties', () => {
    const bands = useEqBandsStore.getState().bands
    expect(bands.length).toBe(10)
    for (const band of bands) {
      expect(band).toHaveProperty('gain')
      expect(band).toHaveProperty('q')
    }
  })
})
