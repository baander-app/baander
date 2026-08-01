import { describe, it, expect, vi, beforeEach } from 'vitest'
import { BrowserRouter } from 'react-router-dom'
import { measureRenders, expectRenderUnder } from '@tests/perf/benchmark'

const {
  mockSetVolume,
  mockToggleMute,
  mockUpdateEQBands,
  mockSetCompression,
  mockSetMasterGain,
  mockApplyVolumeNormalization,
} = vi.hoisted(() => ({
  mockSetVolume: vi.fn(),
  mockToggleMute: vi.fn(),
  mockUpdateEQBands: vi.fn(),
  mockSetCompression: vi.fn(),
  mockSetMasterGain: vi.fn(),
  mockApplyVolumeNormalization: vi.fn(),
}))

const mockProcessor = {
  getAnalysisData: vi.fn().mockReturnValue({
    frequencyData: new Uint8Array(64).fill(128),
    timeDomainData: new Uint8Array(64).fill(128),
    leftChannel: 45,
    rightChannel: 42,
    lufs: -14,
    peakFrequency: 440,
    rms: 0.5,
  }),
  updateEQBands: mockUpdateEQBands,
  setCompression: mockSetCompression,
  setMasterGain: mockSetMasterGain,
  applyVolumeNormalization: mockApplyVolumeNormalization,
}

vi.mock('@/features/player/services/audio-service', () => ({
  audioService: {
    getProcessor: () => mockProcessor,
  },
}))

const mockPlayerState = {
  volume: 75,
  muted: false,
  isPlaying: false,
  setVolume: mockSetVolume,
  toggleMute: mockToggleMute,
}

vi.mock('@/features/player/stores/player-store', () => ({
  usePlayerStore: (selector: (s: typeof mockPlayerState) => unknown) => selector(mockPlayerState),
}))

import { EqualizerPanel } from '@/features/equalizer/components/EqualizerPanel'

describe('EqualizerPanel', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    window.localStorage.clear()
  })

  it('renders full equalizer panel under 3ms mean mount time', () => {
    const result = measureRenders(
      <BrowserRouter>
        <EqualizerPanel />
      </BrowserRouter>,
    )

    expect(result.iterations).toBeGreaterThan(0)
    expectRenderUnder(result, 3)
  })
})
