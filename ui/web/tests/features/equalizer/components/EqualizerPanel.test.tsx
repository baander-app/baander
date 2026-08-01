import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen } from '@testing-library/react'
import { BrowserRouter } from 'react-router-dom'

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
    spectralCentroid: 2200,
    spectralRolloff: 8000,
    spectralFlux: 1.5,
    spectralFlatness: 0.3,
  }),
  getSystemInfo: vi.fn().mockReturnValue({
    contextState: 'running',
    sampleRate: 44100,
    baseLatency: null,
    outputLatency: null,
    currentTime: 12.5,
    connected: true,
    passive: false,
    playing: false,
    dspReady: true,
    wasmSpectrumReady: true,
    workerReady: true,
    workletActive: false,
    fftSize: 2048,
    filterCount: 10,
    compressorActive: false,
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
  usePlayerStore: (selector: (s: unknown) => unknown) => selector(mockPlayerState),
}))

import { EqualizerPanel } from '@/features/equalizer/components/EqualizerPanel'

function renderWithRouter(ui: React.ReactElement) {
  return render(<BrowserRouter>{ui}</BrowserRouter>)
}

describe('EqualizerPanel', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.useFakeTimers()
    window.localStorage.clear()
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('renders the equalizer panel with all band labels', () => {
    renderWithRouter(<EqualizerPanel />)

    // Section titles
    expect(screen.getByText('Equalizer')).toBeInTheDocument()
    expect(screen.getByText('Volume')).toBeInTheDocument()
    expect(screen.getByText('Processing')).toBeInTheDocument()
    expect(screen.getByText('Presets')).toBeInTheDocument()

    // All band labels
    for (const freq of ['31.5', '63', '125', '250', '500', '1K', '2K', '4K', '8K', '16K']) {
      expect(screen.getByText(freq, { exact: true })).toBeInTheDocument()
    }
  })

  it('renders all preset buttons', () => {
    renderWithRouter(<EqualizerPanel />)

    for (const preset of ['FLAT', 'ROCK', 'POP', 'JAZZ', 'CLASSICAL', 'BASS', 'TREBLE', 'VOCAL', 'LOUDNESS']) {
      expect(screen.getByRole('button', { name: preset })).toBeInTheDocument()
    }
  })

  it('renders processing chain with module rows', () => {
    renderWithRouter(<EqualizerPanel />)

    // Processing chain module labels (unique ones)
    expect(screen.getByText('Compressor')).toBeInTheDocument()
    expect(screen.getByText('Stereo Width')).toBeInTheDocument()
    expect(screen.getByText('Crossfeed')).toBeInTheDocument()
  })

  it('renders visualizer mode buttons', () => {
    renderWithRouter(<EqualizerPanel />)

    expect(screen.getByRole('button', { name: 'Spectrum' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Meters' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Phase' })).toBeInTheDocument()
  })

  it('renders EQ mode toggle', () => {
    renderWithRouter(<EqualizerPanel />)

    expect(screen.getByRole('button', { name: 'Simple' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Parametric' })).toBeInTheDocument()
  })

  it('renders A/B Compare section', () => {
    renderWithRouter(<EqualizerPanel />)

    expect(screen.getByText('A/B Compare')).toBeInTheDocument()
  })

  it('renders device profiles section', () => {
    renderWithRouter(<EqualizerPanel />)

    expect(screen.getByText('Device Profiles')).toBeInTheDocument()
  })
})
