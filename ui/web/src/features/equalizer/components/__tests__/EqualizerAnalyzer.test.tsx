import { act, cleanup, fireEvent, render, screen } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { AnalysisData } from '@/features/player/services/audio-processor'
import { usePlayerStore } from '@/features/player/stores/player-store'
import { flatBands, useEqBandsStore } from '../../stores/eq-bands-store'
import { EqualizerAnalyzer } from '../EqualizerAnalyzer'

const { getAnalysisData } = vi.hoisted(() => ({ getAnalysisData: vi.fn() }))
vi.mock('@/features/player/services/audio-service', () => ({
  audioService: { getProcessor: () => ({ getAnalysisData, getNormalizationGainDb: () => -1 }) },
}))
vi.mock('@/features/visualizer/register-visualizer-renderers', () => ({ registerVisualizerRenderers: vi.fn() }))
vi.mock('@/features/visualizer/components/VisualizerHost', () => ({
  VisualizerHost: ({ mode }: { mode: string }) => <div role="img" aria-label={mode} />,
}))

const initialPlayer = usePlayerStore.getState()
const initialBands = useEqBandsStore.getState()

function signal(): AnalysisData {
  const frequencyData = new Uint8Array(1024)
  frequencyData[43] = 200
  return {
    sampleRate: 48000, fftSize: 2048, minDecibels: -100, maxDecibels: 0,
    loudnessAvailable: true, frequencyData, timeDomainData: new Uint8Array(2048).fill(128),
    phase: null, leftChannel: 10, rightChannel: 5, lufs: -17.2, rms: 0.08,
    peakFrequency: 1007.8, spectralCentroid: 1007.8, spectralRolloff: 1007.8,
    spectralFlux: 0, spectralFlatness: 0,
  }
}

function showAnalyzer() {
  render(<EqualizerAnalyzer bands={flatBands()} masterGain={0} normalizationEnabled />)
  act(() => vi.advanceTimersByTime(45))
}

describe('input analyzer', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    getAnalysisData.mockReturnValue(signal())
    useEqBandsStore.setState({ visualizerMode: 'meters' })
    usePlayerStore.setState({ isPlaying: true, currentTrack: { publicId: 'analyzer-track', title: 'Test signal', available: true } })
  })

  afterEach(() => {
    cleanup()
    usePlayerStore.setState(initialPlayer, true)
    useEqBandsStore.setState(initialBands, true)
    vi.useRealTimers()
  })

  it('labels channel RMS in dBFS and clears measurements on pause and track replacement', () => {
    showAnalyzer()
    expect(screen.getByRole('meter', { name: 'Left input RMS' })).toHaveAttribute('aria-valuenow', '-20')
    expect(screen.getByText('-26.0 dBFS')).toBeVisible()
    expect(screen.getByText('-17.2')).toBeVisible()
    act(() => usePlayerStore.setState({ isPlaying: false }))
    expect(screen.getByRole('meter', { name: 'Left input RMS' })).toHaveAttribute('aria-valuenow', '-60')
    expect(screen.getByText('Unavailable')).toBeVisible()
    act(() => usePlayerStore.setState({ isPlaying: true, currentTrack: { publicId: 'another-track', title: 'Another signal', available: true } }))
    expect(screen.getByRole('meter', { name: 'Left input RMS' })).toHaveAttribute('aria-valuenow', '-60')
    act(() => vi.advanceTimersByTime(45))
    expect(screen.getByRole('meter', { name: 'Left input RMS' })).toHaveAttribute('aria-valuenow', '-20')
  })

  it('does not present the fallback RMS estimate as measured LUFS', () => {
    getAnalysisData.mockReturnValue({ ...signal(), loudnessAvailable: false })
    showAnalyzer()
    expect(screen.getByText('Unavailable')).toBeVisible()
    expect(screen.queryByText('-17.2')).not.toBeInTheDocument()
  })

  it('waits for fresh measurements when the same track resumes', () => {
    showAnalyzer()
    act(() => usePlayerStore.setState({ isPlaying: false }))
    act(() => usePlayerStore.setState({ isPlaying: true }))
    expect(screen.getByText('Unavailable')).toBeVisible()
    expect(screen.getByRole('meter', { name: 'Left input RMS' })).toHaveAttribute('aria-valuenow', '-60')
    act(() => vi.advanceTimersByTime(45))
    expect(screen.getByText('-17.2')).toBeVisible()
  })

  it('shows an off-stride narrow tone and selects the actual particle renderer', () => {
    useEqBandsStore.setState({ visualizerMode: 'spectrum' })
    showAnalyzer()
    const spectrum = screen.getByRole('img', { name: 'Input spectrum with configured EQ response' })
    expect(Array.from(spectrum.children).some(element => element instanceof HTMLElement && parseFloat(element.style.height) > 20)).toBe(true)
    fireEvent.click(screen.getByRole('button', { name: 'Particles' }))
    expect(screen.getByRole('img', { name: 'particles' })).toBeVisible()
  })
})
