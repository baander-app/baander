import { act, cleanup, render, screen } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { PhaseAnalysis } from '@/features/player/services/audio-processor'

const { getAnalysisData } = vi.hoisted(() => ({ getAnalysisData: vi.fn() }))
vi.mock('@/features/player/services/audio-service', () => ({
  audioService: { getProcessor: () => ({ getAnalysisData }) },
}))
vi.mock('@/features/visualizer/components/VisualizerHost', () => ({ VisualizerHost: () => null }))
vi.mock('@/features/visualizer/register-visualizer-renderers', () => ({
  registerVisualizerRenderers: vi.fn(), getCompactMode: (mode: string) => mode,
}))

import { EqualizerAnalyzer } from '@/features/equalizer/components/EqualizerAnalyzer'
import { usePlayerStore } from '@/features/player/stores/player-store'
import { flatBands, useEqBandsStore } from '@/features/equalizer/stores/eq-bands-store'

function report(phase: PhaseAnalysis | null) {
  return { phase, frequencyData: new Uint8Array(64), leftChannel: 50, rightChannel: 50,
    lufs: -18, peakFrequency: 1000, rms: 0.5 }
}

function signal(right: (angle: number) => number, correlation: number | null): PhaseAnalysis {
  const samples = new Float32Array(128)
  for (let i = 0; i < 64; i++) {
    const angle = i * Math.PI * 2 / 64
    samples[i * 2] = Math.sin(angle) * 0.5
    samples[i * 2 + 1] = right(angle) * 0.5
  }
  return { samples, correlation }
}

function mount(phase: PhaseAnalysis | null) {
  getAnalysisData.mockReturnValue(report(phase))
  render(<EqualizerAnalyzer bands={flatBands()} masterGain={0} normalizationEnabled={false} targetLufs={-14} />)
  act(() => { vi.advanceTimersByTime(40) })
}

function points(): number[][] {
  return screen.getByLabelText('Captured stereo samples').getAttribute('points')!
    .split(' ').map((point) => point.split(',').map(Number))
}

describe('EqualizerAnalyzer stereo phase', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    useEqBandsStore.setState({ visualizerMode: 'phase' })
    usePlayerStore.setState({ isPlaying: true, currentTrack: { publicId: 'first', title: 'First' } })
  })
  afterEach(() => {
    cleanup()
    vi.clearAllMocks()
    vi.useRealTimers()
  })

  it('draws identical channels vertically and displays measured correlation', () => {
    mount(signal(Math.sin, 1))
    expect(points()).toHaveLength(64)
    expect(points().every(([x]) => x === 50)).toBe(true)
    expect(points().some(([, y]) => y < 50)).toBe(true)
    expect(points().some(([, y]) => y > 50)).toBe(true)
    expect(screen.getByText('Correlation: 1.00')).toBeInTheDocument()
  })

  it('draws opposite channels horizontally', () => {
    mount(signal((angle) => -Math.sin(angle), -1))
    expect(points().every(([, y]) => y === 50)).toBe(true)
    expect(points().some(([x]) => x < 50)).toBe(true)
    expect(points().some(([x]) => x > 50)).toBe(true)
    expect(screen.getByText('Correlation: -1.00')).toBeInTheDocument()
  })

  it('draws quadrature as a circle rather than an invented waveform', () => {
    mount(signal(Math.cos, 0))
    const radii = points().map(([x, y]) => Math.hypot(x - 50, y - 50))
    expect(Math.max(...radii) - Math.min(...radii)).toBeLessThan(0.02)
    expect(screen.getByText('Correlation: 0.00')).toBeInTheDocument()
  })

  it('removes the trace when the next capture is unavailable', () => {
    mount(signal(Math.sin, 1))
    getAnalysisData.mockReturnValue(report(null))
    act(() => { vi.advanceTimersByTime(40) })
    expect(screen.queryByLabelText('Captured stereo samples')).not.toBeInTheDocument()
    expect(screen.getByText('Phase unavailable')).toBeInTheDocument()
  })

  it('removes the trace immediately on pause and keeps it cleared on resume until a new poll', () => {
    mount(signal(Math.sin, 1))
    act(() => { usePlayerStore.setState({ isPlaying: false }) })
    expect(screen.queryByLabelText('Captured stereo samples')).not.toBeInTheDocument()
    act(() => { usePlayerStore.setState({ isPlaying: true }) })
    expect(screen.queryByLabelText('Captured stereo samples')).not.toBeInTheDocument()
  })

  it('clears the previous track before polling a new capture', () => {
    mount(signal(Math.sin, 1))
    act(() => { usePlayerStore.setState({ currentTrack: { publicId: 'second', title: 'Second' } }) })
    expect(screen.queryByLabelText('Captured stereo samples')).not.toBeInTheDocument()
    getAnalysisData.mockReturnValue(report(null))
    act(() => { vi.advanceTimersByTime(40) })
    expect(screen.getByText('Phase unavailable')).toBeInTheDocument()
  })

  it('marks correlation unavailable for silent captures', () => {
    mount({ samples: new Float32Array(128), correlation: null })
    expect(points().every(([x, y]) => x === 50 && y === 50)).toBe(true)
    expect(screen.getByText('Correlation: unavailable')).toBeInTheDocument()
  })
})
