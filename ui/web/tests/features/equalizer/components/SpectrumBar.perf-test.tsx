import { describe, it, expect } from 'vitest'
import { measureRenders, expectRenderUnder } from '@tests/perf/benchmark'

import { SpectrumBar, ChannelMeter } from './spectrum-perf-fixtures'

describe('SpectrumBar', () => {
  it('renders 64 bars under 3ms mean mount time', () => {
    const bars = Array.from({ length: 64 }, (_, i) => {
      const base = Math.sin(i * 0.2) * 40 + 50
      return Math.max(0, Math.min(100, base))
    })

    const result = measureRenders(
      <div className="flex items-end justify-between gap-px" style={{ height: 200, padding: 8 }}>
        {bars.map((height, i) => (
          <SpectrumBar key={i} height={height} />
        ))}
      </div>,
    )

    expect(result.iterations).toBeGreaterThan(0)
    expectRenderUnder(result, 3)
  })
})

describe('ChannelMeter', () => {
  it('renders 4 meters under 1ms mean mount time', () => {
    const result = measureRenders(
      <div className="flex flex-col gap-4" style={{ width: 300 }}>
        <ChannelMeter label="L" level={85} />
        <ChannelMeter label="R" level={72} />
        <ChannelMeter label="L" level={45} />
        <ChannelMeter label="R" level={20} />
      </div>,
    )

    expect(result.iterations).toBeGreaterThan(0)
    expectRenderUnder(result, 1)
  })
})
