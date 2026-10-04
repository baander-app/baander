import { test, expect } from './audio-graph-harness'

for (const mode of ['fallback', 'worklet'] as const) {
  for (const signal of ['right', 'antiphase', 'asymmetric', 'mono'] as const) {
    test(`native ${mode} meters preserve ${signal} stereo and ignore output gain`, async ({ stereoAnalysis }) => {
      const result = await stereoAnalysis({ mode, signal })
      const leftRms = signal === 'right' ? 0 : 0.2 / Math.sqrt(2)
      const rightRms = (signal === 'asymmetric' ? 0.1 : 0.2) / Math.sqrt(2)
      const combined = Math.sqrt((leftRms ** 2 + rightRms ** 2) / 2)
      for (const reading of [result.before, result.after]) {
        expect(reading.leftChannel).toBeCloseTo(leftRms * 100, 1)
        expect(reading.rightChannel).toBeCloseTo(rightRms * 100, 1)
        expect(reading.rms).toBeCloseTo(combined, 3)
        expect(Number.isFinite(reading.lufs)).toBe(true)
      }
      if (mode === 'worklet') {
        expect(result.phase).not.toBeNull()
        expect(result.phase!.samples).toHaveLength(128)
        if (signal === 'right') expect(result.phase!.correlation).toBeNull()
        else expect(result.phase!.correlation).toBeCloseTo(signal === 'antiphase' ? -1 : 1, 6)
        expect(Math.max(...result.phase!.samples.map(Math.abs))).toBeGreaterThan(0.19)
        for (let index = 0; index < 128; index += 2) {
          const left = result.phase!.samples[index], right = result.phase!.samples[index + 1]
          if (signal === 'right') expect(left).toBe(0)
          else expect(right).toBeCloseTo(left * (signal === 'antiphase' ? -1 : signal === 'asymmetric' ? 0.5 : 1), 6)
        }
        expect(result.workletReports).toBeGreaterThan(2)
        expect(result.wasmLoudnessReported).toBe(true)
        expect(result.before).toEqual(result.beforeWorkletFrame)
        expect(result.after).toEqual(result.afterWorkletFrame)
        const unweightedLufs = -0.691 + 20 * Math.log10(combined)
        expect(result.before.lufs).toBeGreaterThan(unweightedLufs + 2)
        expect(result.after.lufs).toBeGreaterThan(unweightedLufs + 2)
      }
    })
  }
}
