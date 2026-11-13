import { beforeEach, describe, expect, it, vi } from 'vitest'
import { attachHlsPlayback } from '../hls-playback'

const hls = vi.hoisted(() => {
  const constructorConfigs: Array<Record<string, unknown>> = []
  const destroyed: number[] = []
  const started: number[] = []
  const loaded: string[] = []
  const listeners = new Map<string, Array<(data?: unknown) => void>>()
  class FakeHlsPlayer {
    static readonly isSupported = () => true
    static readonly Events = {
      ERROR: 'error',
      MEDIA_ATTACHED: 'mediaAttached',
      MANIFEST_PARSED: 'manifestParsed',
    }
    static readonly ErrorTypes = { NETWORK_ERROR: 'networkError' }

    constructor(config: Record<string, unknown>) {
      constructorConfigs.push(config)
    }

    on(event: string, handler: (data?: unknown) => void) {
      listeners.set(event, [...(listeners.get(event) ?? []), handler])
    }

    attachMedia() {
      listeners.get('mediaAttached')?.forEach(handler => handler())
    }

    loadSource(url: string) {
      loaded.push(url)
    }

    startLoad(position: number) {
      started.push(position)
    }

    destroy() {
      destroyed.push(destroyed.length + 1)
    }
  }
  return { FakeHlsPlayer, constructorConfigs, destroyed, started, loaded, listeners }
})

vi.mock('hls.js', () => ({ default: hls.FakeHlsPlayer }))

const stubVideo = () => ({
  addEventListener: vi.fn(),
  removeEventListener: vi.fn(),
}) as unknown as HTMLVideoElement

const settled = () => new Promise(resolve => { setTimeout(resolve, 0) })

describe('attachHlsPlayback', () => {
  beforeEach(() => {
    hls.constructorConfigs.length = 0
    hls.destroyed.length = 0
    hls.started.length = 0
    hls.loaded.length = 0
    hls.listeners.clear()
  })

  it('constructs hls.js with live-edge chasing disabled and the requested start position', async () => {
    attachHlsPlayback({
      video: stubVideo(),
      url: 'https://media.example/master.m3u8',
      position: 42,
      onError: () => {},
      onReady: () => {},
    })
    await settled()

    expect(hls.constructorConfigs).toHaveLength(1)
    const config = hls.constructorConfigs[0]!
    // An own key set to undefined is what replaces hls.js's default latency
    // controller class through its config spread; the key must stay present.
    expect('latencyController' in config).toBe(true)
    expect(config.latencyController).toBeUndefined()
    expect(config.lowLatencyMode).toBe(false)
    expect(config.autoStartLoad).toBe(false)
    expect(config.startPosition).toBe(42)
  })

  it('announces readiness after the manifest parses and destroys the player on dispose', async () => {
    let ready = false
    const dispose = attachHlsPlayback({
      video: stubVideo(),
      url: 'https://media.example/master.m3u8',
      position: 7,
      onError: () => {},
      onReady: () => { ready = true },
    })
    await settled()

    hls.listeners.get('manifestParsed')?.forEach(handler => handler())
    expect(ready).toBe(true)
    expect(hls.loaded).toEqual(['https://media.example/master.m3u8'])
    expect(hls.started.at(-1)).toBe(7)

    dispose()
    expect(hls.destroyed).toHaveLength(1)
  })
})
