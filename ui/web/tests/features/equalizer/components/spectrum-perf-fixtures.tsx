// Isolated sub-components for perf measurement
export function SpectrumBar({ height }: { height: number }) {
  const color = height > 80 ? '#ff4444' : height > 60 ? '#ffaa00' : '#00ff00'
  return (
    <div
      className="w-full min-h-[2px] transition-[height] duration-100 ease-out"
      style={{ height: `${Math.max(2, height)}%`, backgroundColor: color }}
    />
  )
}

export function ChannelMeter({ label, level }: { label: string; level: number }) {
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
