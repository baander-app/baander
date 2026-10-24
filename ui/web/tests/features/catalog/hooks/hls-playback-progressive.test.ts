import type Hls from 'hls.js'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { attachHlsPlayback } from '@/features/catalog/hooks/hls-playback'

const captured = vi.hoisted(() => ({ instances: [] as Hls[] }))

vi.mock('hls.js', async importOriginal => {
  const actual = await importOriginal<typeof import('hls.js')>()
  return {
    ...actual,
    default: class extends actual.default {
      static isSupported() { return true }
      constructor(config?: ConstructorParameters<typeof actual.default>[0]) {
        super(config)
        captured.instances.push(this)
      }
      // Keep the actual constructor and controllers, without creating MSE or
      // issuing network requests. The regression exercises their clock writes.
      attachMedia() {}
    },
  }
})

type GrowingPlaylist = {
  edge: number
  age: number
  totalduration: number
  targetduration: number
  holdBack: number
  partHoldBack: number
  partTarget: number
  fragmentStart: number
  live: boolean
}

interface ProgressiveRuntime {
  latencyController?: { levelDetails: GrowingPlaylist }
  streamController: {
    media: Pick<HTMLMediaElement, 'duration' | 'readyState' | 'currentTime'> | null
    _hasEnoughToStart: boolean
    synchronizeToLiveEdge: (details: GrowingPlaylist) => void
  }
}

function synchronizeAtGrowingEdge(hls: Hls) {
  const runtime = hls as unknown as ProgressiveRuntime
  const details: GrowingPlaylist = {
    edge: 24, age: 7.501, totalduration: 24, targetduration: 7,
    holdBack: 0, partHoldBack: 0, partTarget: 0, fragmentStart: 0, live: true,
  }
  const writes: number[] = []
  let position = 24.00566
  runtime.streamController.media = {
    duration: 66, readyState: HTMLMediaElement.HAVE_CURRENT_DATA,
    get currentTime() { return position },
    set currentTime(value) {
      writes.push(value)
      position = value
    },
  }
  runtime.streamController._hasEnoughToStart = true
  if (runtime.latencyController) {
    Object.defineProperty(runtime.latencyController, 'levelDetails', { value: details })
  }
  try {
    const liveSyncPosition = hls.liveSyncPosition
    runtime.streamController.synchronizeToLiveEdge(details)
    return { liveSyncPosition, position, writes }
  } finally {
    runtime.streamController.media = null
  }
}

afterEach(() => {
  for (const instance of captured.instances) instance.destroy()
  captured.instances.length = 0
})

describe('progressively published movie playback', () => {
  it('reproduces the installed HLS live-edge clock correction for an ordinary live configuration', async () => {
    const { default: ActualHls } = await vi.importActual<typeof import('hls.js')>('hls.js')
    const ordinary = new ActualHls({ autoStartLoad: false, startPosition: 18 })
    try {
      const result = synchronizeAtGrowingEdge(ordinary)
      expect(result.liveSyncPosition).toBeCloseTo(10.501)
      expect(result.writes).toHaveLength(1)
      expect(result.position).toBeCloseTo(10.501)
    } finally {
      ordinary.destroy()
    }
  })

  it('keeps the movie cursor beyond the currently published edge without an automatic seek', async () => {
    const onError = vi.fn()
    const dispose = attachHlsPlayback({
      video: document.createElement('video'), url: 'https://media.baander.app/master.m3u8',
      position: 18, onReady: vi.fn(), onError,
    })
    await vi.waitFor(() => expect(captured.instances).toHaveLength(1))
    try {
      expect(onError).not.toHaveBeenCalled()
      const instance = captured.instances[0]
      const result = synchronizeAtGrowingEdge(instance)
      expect(result.position).toBe(24.00566)
      expect(result.writes).toEqual([])
      expect(result.liveSyncPosition).toBeNull()
      expect(instance.config.startPosition).toBe(18)
    } finally {
      dispose()
    }
  })
})
