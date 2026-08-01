import { describe, it, expect } from 'vitest'
import { measureRenders, expectRenderUnder } from '@tests/perf/benchmark'

// Isolated sub-components for perf measurement
function SpectrumBar({ height }: { height: number }) {
  const color = height > 80 ? '#ff4444' : height > 60 ? '#ffaa00' : '#00ff00'
  return (
    <div
      className="w-full min-h-[2px] transition-[height] duration-100 ease-out"
      style={{ height: `${Math.max(2, height)}%`, backgroundColor: color }}
    />
  )
}

function ChannelMeter({ label, level }: { label: string; level: number }) {
  const color = level > 80 ? '#ff4444' : level > 60 ? '#ffaa00' : '#00ff00'
  return (
    <div className="flex items-center gap-2">
      <span className="min-w-[20px] font-bold" style={{ color: '#00ff00' }}>{label}</span>
      <div className="flex-1 h-5 rounded-sm overflow-hidden" style={{ background: '#333' }}>
        <div
          className="h-full transition-[width] duration-100 ease-out"
          style={{ width: `${Math.min(100, level)}%`, backgroundColor: color }}
        />
      </div>
      <span className="min-w-[30px] text-right text-xs" style={{ color: '#00ff00' }}>
        {Math.round(level)}
      </span>
    </div>
  )
}

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
