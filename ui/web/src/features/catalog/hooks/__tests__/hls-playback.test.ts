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
      LEVELS_UPDATED: 'levelsUpdated',
      LEVEL_SWITCHED: 'levelSwitched'
    }
    static readonly ErrorTypes = { NETWORK_ERROR: 'networkError' }

    levels = [
      { width: 1280, height: 720, bitrate: 2000000 },
      { width: 1920, height: 1080, bitrate: 4000000 }
    ]
    currentLevel = 0
    manualLevel = -1
    // hls.js answers every nextLevel assignment, even one naming the current choice,
    // with a level switch that flushes the forward and back buffers.
    readonly levelSwitches: number[] = []
    set nextLevel(level: number) {
      this.manualLevel = level
      this.levelSwitches.push(level)
    }
    static latest: FakeHlsPlayer

    constructor(config: Record<string, unknown>) {
      FakeHlsPlayer.latest = this
      constructorConfigs.push(config)
    }

    on(event: string, handler: (data?: unknown) => void) {
      listeners.set(event, [...(listeners.get(event) ?? []), handler])
    }

    attachMedia() {
      listeners.get('mediaAttached')?.forEach((handler) => handler())
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
  return {
    FakeHlsPlayer,
    constructorConfigs,
    destroyed,
    started,
    loaded,
    listeners
  }
})

vi.mock('hls.js', () => ({ default: hls.FakeHlsPlayer }))

const stubVideo = () =>
  ({
    addEventListener: vi.fn(),
    removeEventListener: vi.fn()
  }) as unknown as HTMLVideoElement

const settled = () =>
  new Promise((resolve) => {
    setTimeout(resolve, 0)
  })

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
      onReady: () => {}
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
      onReady: () => {
        ready = true
      }
    })
    await settled()

    hls.listeners.get('manifestParsed')?.forEach((handler) => handler())
    expect(ready).toBe(true)
    expect(hls.loaded).toEqual(['https://media.example/master.m3u8'])
    expect(hls.started.at(-1)).toBe(7)

    dispose()
    expect(hls.destroyed).toHaveLength(1)
  })
})

describe('rendition preferences', () => {
  it('resolves a manual choice by rendition identity as levels disappear and reorder', async () => {
    let preference = '1920x1080:4000000'
    const choices = vi.fn()
    const dispose = attachHlsPlayback({
      video: stubVideo(),
      url: 'https://media.baander.app/master.m3u8',
      position: 0,
      onError: vi.fn(),
      onReady: vi.fn(),
      getQualityPreference: () => preference,
      onQualityChoices: choices
    })
    await settled()
    hls.listeners.get('manifestParsed')?.forEach((handler) => handler())
    const instance = hls.FakeHlsPlayer.latest
    expect(instance.manualLevel).toBe(1)
    const preferred = instance.levels[1]!
    instance.levels = [instance.levels[0]!]
    hls.listeners.get('levelsUpdated')?.forEach((handler) => handler())
    expect(instance.manualLevel).toBe(-1)
    instance.levels = [preferred, ...instance.levels]
    hls.listeners.get('levelsUpdated')?.forEach((handler) => handler())
    expect(instance.manualLevel).toBe(0)
    preference = 'auto'
    hls.listeners.get('levelsUpdated')?.forEach((handler) => handler())
    expect(instance.manualLevel).toBe(-1)
    expect(instance.levelSwitches).toEqual([1, -1, 0, -1])
    expect(choices).toHaveBeenCalledWith(expect.any(Array), '1080p')
    dispose()
    choices.mockClear()
    hls.listeners.get('levelsUpdated')?.forEach((handler) => handler())
    expect(choices).not.toHaveBeenCalled()
  })

  it('reports adaptive rendition switches without requesting a buffer-flushing level switch', async () => {
    let preference = 'auto'
    let setQuality: ((id: string) => void) | undefined
    const choices = vi.fn()
    const dispose = attachHlsPlayback({
      video: stubVideo(),
      url: 'https://media.baander.app/master.m3u8',
      position: 0,
      onError: vi.fn(),
      onReady: vi.fn(),
      getQualityPreference: () => preference,
      onQualityChoices: choices,
      onPresentationControls: (controls) => {
        setQuality = controls.setQuality
      }
    })
    await settled()
    hls.listeners.get('manifestParsed')?.forEach((handler) => handler())
    const instance = hls.FakeHlsPlayer.latest
    instance.currentLevel = 1
    hls.listeners.get('levelSwitched')?.forEach((handler) => handler())
    instance.currentLevel = 0
    hls.listeners.get('levelSwitched')?.forEach((handler) => handler())
    hls.listeners.get('levelsUpdated')?.forEach((handler) => handler())
    expect(instance.levelSwitches).toEqual([])
    expect(choices).toHaveBeenNthCalledWith(2, expect.any(Array), '1080p')
    expect(choices).toHaveBeenLastCalledWith(expect.any(Array), '720p')
    preference = '1920x1080:4000000'
    setQuality?.(preference)
    instance.currentLevel = 1
    hls.listeners.get('levelSwitched')?.forEach((handler) => handler())
    expect(instance.levelSwitches).toEqual([1])
    dispose()
  })
})
