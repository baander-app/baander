import type { ReactNode } from 'react'
import { createRoot } from 'react-dom/client'
import { act } from '@testing-library/react'

interface MeasureResult {
  durations: number[]
  mountDuration: number
  meanDuration: number
  maxDuration: number
  minDuration: number
  iterations: number
}

function hrMs(): number {
  // process.hrtime.bigint() is not affected by vi.useFakeTimers()
  return Number(process.hrtime.bigint()) / 1e6
}

/**
 * Measures React mount performance using high-resolution timing.
 * Works with Vitest and jsdom — no Jest dependency.
 */
export function measureRenders(element: ReactNode, iterations = 20): MeasureResult {
  const durations: number[] = []

  // Warm-up run (not measured)
  const warmup = document.createElement('div')
  document.body.appendChild(warmup)
  act(() => {
    const root = createRoot(warmup)
    root.render(element)
    root.unmount()
  })
  document.body.removeChild(warmup)

  for (let i = 0; i < iterations; i++) {
    const container = document.createElement('div')
    document.body.appendChild(container)

    const start = hrMs()

    act(() => {
      const root = createRoot(container)
      root.render(element)
      root.unmount()
    })

    const end = hrMs()
    durations.push(end - start)

    document.body.removeChild(container)
  }

  return {
    durations,
    mountDuration: durations[0] ?? 0,
    meanDuration: durations.reduce((a, b) => a + b, 0) / durations.length,
    maxDuration: Math.max(...durations, 0),
    minDuration: Math.min(...durations),
    iterations: durations.length,
  }
}

/**
 * Asserts that mean render time stays under a threshold (ms).
 * The threshold should be set to ~2x the current baseline to allow CI variance.
 */
export function expectRenderUnder(result: MeasureResult, maxMs: number) {
  if (result.meanDuration > maxMs) {
    throw new Error(
      `Render performance exceeded threshold: mean ${result.meanDuration.toFixed(2)}ms > ${maxMs}ms ` +
      `(min: ${result.minDuration.toFixed(2)}ms, max: ${result.maxDuration.toFixed(2)}ms, iterations: ${result.iterations})`,
    )
  }
}
