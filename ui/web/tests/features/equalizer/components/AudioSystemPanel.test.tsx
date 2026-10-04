import { act, render, screen } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { AudioSystemInfo } from '@/features/player/services/audio-processor'

const { getSystemInfo, getAnalysisData } = vi.hoisted(() => ({
  getSystemInfo: vi.fn(),
  getAnalysisData: vi.fn(),
}))

vi.mock('@/features/player/services/audio-service', () => ({
  audioService: {
    getProcessor: () => ({ getSystemInfo, getAnalysisData }),
  },
}))

import { AudioSystemPanel } from '@/features/equalizer/components/AudioSystemPanel'

const system: AudioSystemInfo = {
  contextState: 'running',
  sampleRate: 44100,
  baseLatency: null,
  outputLatency: null,
  currentTime: 12.5,
  connected: true,
  passive: false,
  playing: true,
  dspReady: true,
  wasmSpectrumReady: true,
  workletActive: true,
  fftSize: 2048,
  filterCount: 10,
  compressorActive: false,
}

describe('AudioSystemPanel', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    getSystemInfo.mockReturnValue(system)
    getAnalysisData.mockReturnValue({
      spectralCentroid: 2200,
      spectralRolloff: 8000,
      spectralFlux: 1.5,
      spectralFlatness: 0.3,
      rms: 0.5,
    })
  })

  afterEach(() => {
    vi.clearAllMocks()
    vi.useRealTimers()
  })

  it('shows real analysis values in active mode without an obsolete worker status', () => {
    render(<AudioSystemPanel />)

    for (const value of ['2.2 kHz', '8.0 kHz', '1.50', '0.300', '50.0%']) {
      expect(screen.getByText(value)).toBeInTheDocument()
    }
    expect(screen.queryByText('Analysis Worker')).not.toBeInTheDocument()
    expect(screen.queryByText('Analysis unavailable in passive mode')).not.toBeInTheDocument()
  })

  it('shows unavailable metrics in passive mode while keeping real pipeline and context status', () => {
    getSystemInfo.mockReturnValue({ ...system, passive: true })
    render(<AudioSystemPanel />)

    expect(screen.getByText('Analysis unavailable in passive mode')).toBeInTheDocument()
    expect(screen.getAllByText('—')).toHaveLength(5)
    expect(getAnalysisData).not.toHaveBeenCalled()
    expect(screen.getByText('Audio Source')).toBeInTheDocument()
    expect(screen.getByText('44100 Hz')).toBeInTheDocument()
    expect(screen.getByText('Passive')).toBeInTheDocument()
    expect(screen.queryByText('Analysis Worker')).not.toBeInTheDocument()
    for (const value of ['2.2 kHz', '8.0 kHz', '1.50', '0.300', '50.0%', '0.00', '0.000', '0.0%']) {
      expect(screen.queryByText(value)).not.toBeInTheDocument()
    }
  })

  it('clears active readings when the processor switches to passive mode', () => {
    render(<AudioSystemPanel />)
    expect(screen.getByText('50.0%')).toBeInTheDocument()
    getSystemInfo.mockReturnValue({ ...system, passive: true })

    act(() => { vi.advanceTimersByTime(250) })

    expect(screen.getByText('Analysis unavailable in passive mode')).toBeInTheDocument()
    expect(screen.getAllByText('—')).toHaveLength(5)
    expect(screen.queryByText('50.0%')).not.toBeInTheDocument()
    expect(getAnalysisData).toHaveBeenCalledTimes(1)
  })
})
