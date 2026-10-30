import type { AnalysisData } from '@/features/player/services/audio-processor'

type SpectrumData = Pick<AnalysisData, 'frequencyData' | 'sampleRate' | 'fftSize'>

/** Shared display range; FFT bin zero (DC) is never part of this axis. */
export function getFrequencyRange(sampleRate: number): { min: number; max: number } {
  return { min: 20, max: Math.min(20_000, sampleRate / 2) }
}

export function frequencyToPosition(hz: number, sampleRate: number): number {
  const { min, max } = getFrequencyRange(sampleRate)
  if (max <= min || !Number.isFinite(hz)) return 0
  return Math.max(0, Math.min(1, Math.log(hz / min) / Math.log(max / min)))
}

/** Peak encoded dB level in each logarithmic band; unresolved bands remain zero. */
export function aggregateSpectrum(data: SpectrumData, bandCount = 64): Float32Array {
  const bands = new Float32Array(bandCount)
  const { min, max } = getFrequencyRange(data.sampleRate)
  if (max <= min || data.fftSize <= 0) return bands
  const binWidth = data.sampleRate / data.fftSize
  const logRange = Math.log(max / min)
  const lastBand = bandCount - 1
  const binCount = data.frequencyData.length
  for (let bin = 1; bin < binCount; bin++) {
    const hz = bin * binWidth
    if (hz < min) continue
    if (hz > max) break
    const position = Math.max(0, Math.min(1, Math.log(hz / min) / logRange))
    const band = Math.min(lastBand, Math.floor(position * bandCount))
    bands[band] = Math.max(bands[band]!, data.frequencyData[bin]! / 255)
  }
  return bands
}

/** Convert a 60 Hz smoothing coefficient to the actual elapsed frame interval. */
export function frameSmoothing(alpha: number, deltaTime: number): number {
  return 1 - Math.pow(1 - alpha, Math.max(0, deltaTime) / (1000 / 60))
}
