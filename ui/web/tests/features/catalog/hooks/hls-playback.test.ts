import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { attachHlsPlayback, type SubtitlePreference } from '@/features/catalog/hooks/hls-playback'

const mock = vi.hoisted(() => ({ supported: true, instances: [] as Array<{
  config: { autoStartLoad: boolean, startPosition: number }
  listeners: Record<string, (...args: unknown[]) => void>
  destroy: ReturnType<typeof vi.fn>
  attachMedia: ReturnType<typeof vi.fn>
  loadSource: ReturnType<typeof vi.fn>
  startLoad: ReturnType<typeof vi.fn>
}> }))
vi.mock('hls.js', () => ({ default: class {
  static isSupported() { return mock.supported }
  static Events = { ERROR: 'error', MEDIA_ATTACHED: 'attached', MANIFEST_PARSED: 'parsed' }
  static ErrorTypes = { NETWORK_ERROR: 'networkError' }
  listeners: Record<string, (...args: unknown[]) => void> = {}
  destroy = vi.fn()
  attachMedia = vi.fn()
  loadSource = vi.fn()
  startLoad = vi.fn()
  constructor(public config: { autoStartLoad: boolean, startPosition: number }) { mock.instances.push(this) }
  on(event: string, listener: (...args: unknown[]) => void) { this.listeners[event] = listener }
} }))

const settle = async () => { await new Promise(resolve => setTimeout(resolve, 0)) }
const setup = () => {
  const options = { video: document.createElement('video'), url: 'https://media.baander.app/master.m3u8?sig=proof',
    position: 18, onError: vi.fn(), onReady: vi.fn() }
  return { options, dispose: attachHlsPlayback(options) }
}
beforeEach(() => { mock.supported = true; mock.instances.length = 0 })
afterEach(() => vi.restoreAllMocks())

describe('HLS attachment ownership', () => {
  it('loads the unchanged signed URL and starts at the explicit local cursor', async () => {
    const { options, dispose } = setup()
    await settle()
    const instance = mock.instances[0]
    expect(instance.config).toEqual({ autoStartLoad: false, startPosition: 18, latencyController: undefined })
    expect(instance.attachMedia).toHaveBeenCalledWith(options.video)
    instance.listeners.attached()
    expect(instance.loadSource).toHaveBeenCalledWith(options.url)
    instance.listeners.parsed()
    expect(instance.startLoad).toHaveBeenCalledWith(18)
    expect(options.onReady).toHaveBeenCalledOnce()
    dispose()
    dispose()
    expect(instance.destroy).toHaveBeenCalledOnce()
    instance.listeners.error('error', { fatal: true })
    instance.listeners.parsed()
    expect(options.onError).not.toHaveBeenCalled()
    expect(instance.startLoad).toHaveBeenCalledOnce()
  })

  it('cancels an attachment before its lazy module resolves', async () => {
    const { options, dispose } = setup()
    dispose()
    await settle()
    expect(mock.instances).toHaveLength(0)
    expect(options.onReady).not.toHaveBeenCalled()
  })

  it('reports only fatal errors belonging to the active instance', async () => {
    const { options, dispose } = setup()
    await settle()
    const instance = mock.instances[0]
    instance.listeners.error('error', { fatal: false })
    expect(options.onError).not.toHaveBeenCalled()
    instance.listeners.error('error', { fatal: true })
    expect(options.onError).toHaveBeenCalledOnce()
    expect(instance.destroy).toHaveBeenCalledOnce()
    dispose()
    expect(instance.destroy).toHaveBeenCalledOnce()
  })

  it.each(['networkError', 'mediaError'])('classifies a fatal %s for owned recovery', async type => {
    const { options } = setup()
    await settle()
    mock.instances[0].listeners.error('error', { fatal: true, type })
    expect(options.onError).toHaveBeenCalledExactlyOnceWith(type === 'networkError' ? 'network' : undefined)
    expect(mock.instances[0].destroy).toHaveBeenCalledOnce()
  })

  it.each([false, true])('reports the retained cursor before destruction and tears down once if the callback throws: %s', async throws => {
    const { options, dispose } = setup()
    await settle()
    const instance = mock.instances[0]
    options.video.currentTime = 18
    instance.destroy.mockImplementation(() => { options.video.currentTime = 0 })
    const observed: number[] = []
    options.onError.mockImplementation(() => {
      observed.push(options.video.currentTime)
      dispose()
      instance.listeners.error('error', { fatal: true, type: 'networkError' })
      instance.listeners.parsed()
      if (throws) throw new Error('Observer failed')
    })
    const fail = () => instance.listeners.error('error', { fatal: true, type: 'networkError' })
    if (throws) expect(fail).toThrow('Observer failed')
    else fail()
    expect(observed).toEqual([18])
    expect(options.onReady).not.toHaveBeenCalled()
    expect(instance.destroy).toHaveBeenCalledOnce()
    expect(options.video.currentTime).toBe(0)
    dispose()
    expect(instance.destroy).toHaveBeenCalledOnce()
  })

  it.each(['maybe', ''])('uses native HLS only with its capability (%s)', async capability => {
    mock.supported = false
    vi.spyOn(HTMLMediaElement.prototype, 'canPlayType').mockReturnValue(capability as CanPlayTypeResult)
    const { options, dispose } = setup()
    await settle()
    expect(mock.instances).toHaveLength(0)
    expect(options.video.getAttribute('src')).toBe(capability ? options.url : null)
    expect(options.onError).toHaveBeenCalledTimes(capability ? 0 : 1)
    expect(options.onReady).toHaveBeenCalledTimes(capability ? 1 : 0)
    dispose()
  })
})


class SubtitleTracks extends EventTarget {
  [index: number]: TextTrack
  length = 0

  replace(entries: Array<{ key: number, label: string, language?: string, mode?: TextTrackMode }>) {
    const oldLength = this.length
    for (let index = 0; index < oldLength; index++) delete this[index]
    this.length = entries.length
    const notifyChange = () => queueMicrotask(() => this.dispatchEvent(new Event('change')))
    entries.forEach((entry, index) => {
      let mode = entry.mode ?? 'disabled'
      this[index] = {
        kind: 'subtitles', label: `${entry.label} (s${entry.key})`, language: entry.language ?? 'eng',
        get mode() { return mode },
        set mode(next: TextTrackMode) {
          if (mode === next) return
          mode = next
          notifyChange()
        },
      } as TextTrack
    })
    queueMicrotask(() => this.dispatchEvent(new Event(oldLength ? 'removetrack' : 'addtrack')))
  }

  show(key: number | 'off') {
    for (const track of Array.from(this)) track.mode = track.label.endsWith(`(s${key})`) ? 'showing' : 'disabled'
  }

  modes() { return Array.from(this, track => track.mode) }
}

const captions = (preference?: SubtitlePreference) => {
  const video = document.createElement('video')
  const tracks = new SubtitleTracks()
  Object.defineProperty(video, 'textTracks', { value: tracks })
  let remembered = preference
  const onSubtitlePreferenceChange = vi.fn((next: SubtitlePreference) => { remembered = next })
  const options = {
    video, url: 'https://media.baander.app/master.m3u8', position: 18,
    onError: vi.fn(), onReady: vi.fn(), getSubtitlePreference: () => remembered, onSubtitlePreferenceChange,
  }
  return { tracks, options, remembered: () => remembered }
}
const defaultTracks = () => [
  { key: 2, label: 'Dialogue', mode: 'showing' as const },
  { key: 3, label: 'Forced' },
]

describe('caption selection across attachments', () => {
  it.each(['s3', 'off'] as const)('restores remembered %s instead of a fresh manifest default', async preference => {
    const { tracks, options } = captions(preference)
    const dispose = attachHlsPlayback(options)
    await settle()
    tracks.replace(defaultTracks())
    mock.instances[0].listeners.parsed()
    await settle()
    expect(tracks.modes()).toEqual(preference === 's3' ? ['disabled', 'showing'] : ['disabled', 'disabled'])
    expect(options.onSubtitlePreferenceChange).not.toHaveBeenCalled()
    dispose()
  })

  it.each([3, 'off'] as const)('captures an actual choice of %s and carries it to fresh same-language tracks', async chosen => {
    const { tracks, options, remembered } = captions()
    let dispose = attachHlsPlayback(options)
    await settle()
    tracks.replace(defaultTracks())
    mock.instances[0].listeners.parsed()
    await settle()
    tracks.show(chosen)
    await settle()
    expect(remembered()).toBe(chosen === 'off' ? 'off' : 's3')
    dispose()
    tracks.replace([])
    dispose = attachHlsPlayback(options)
    await settle()
    tracks.replace(defaultTracks())
    mock.instances[1].listeners.parsed()
    await settle()
    expect(tracks.modes()).toEqual(chosen === 3 ? ['disabled', 'showing'] : ['disabled', 'disabled'])
    dispose()
  })

  it('keeps a temporarily absent source identity while suppressing defaults and restores it when published', async () => {
    const { tracks, options, remembered } = captions('s3')
    const dispose = attachHlsPlayback(options)
    await settle()
    mock.instances[0].listeners.parsed()
    tracks.replace(defaultTracks().slice(0, 1))
    await settle()
    expect(tracks.modes()).toEqual(['disabled'])
    expect(remembered()).toBe('s3')
    expect(options.onSubtitlePreferenceChange).not.toHaveBeenCalled()
    tracks.replace(defaultTracks())
    await settle()
    expect(tracks.modes()).toEqual(['disabled', 'showing'])
    expect(remembered()).toBe('s3')
    dispose()
  })

  it('accepts a later viewer choice while the remembered source track is absent', async () => {
    const { tracks, options, remembered } = captions('s3')
    const dispose = attachHlsPlayback(options)
    await settle()
    mock.instances[0].listeners.parsed()
    tracks.replace(defaultTracks().slice(0, 1))
    await settle()
    tracks.show(2)
    await settle()
    expect(remembered()).toBe('s2')
    tracks.replace(defaultTracks())
    await settle()
    expect(tracks.modes()).toEqual(['showing', 'disabled'])
    dispose()
  })

  it('does not turn initial empty or disabled tracks into an explicit Off preference', async () => {
    const { tracks, options, remembered } = captions()
    const dispose = attachHlsPlayback(options)
    await settle()
    mock.instances[0].listeners.parsed()
    tracks.dispatchEvent(new Event('change'))
    tracks.replace(defaultTracks().map(track => ({ ...track, mode: 'disabled' })))
    await settle()
    expect(remembered()).toBeUndefined()
    expect(options.onSubtitlePreferenceChange).not.toHaveBeenCalled()
    tracks.show(2)
    await settle()
    expect(remembered()).toBe('s2')
    tracks.show('off')
    await settle()
    expect(remembered()).toBe('off')
    dispose()
  })

  it('captures a queued gesture before teardown and ignores delayed mode events from its retired attachment', async () => {
    const { tracks, options, remembered } = captions()
    let dispose = attachHlsPlayback(options)
    await settle()
    tracks.replace(defaultTracks())
    mock.instances[0].listeners.parsed()
    await settle()
    tracks.show(3)
    dispose()
    expect(remembered()).toBe('s3')
    tracks.show('off')
    tracks.replace([])
    dispose = attachHlsPlayback(options)
    await settle()
    tracks.replace(defaultTracks())
    mock.instances[1].listeners.parsed()
    await settle()
    expect(remembered()).toBe('s3')
    expect(tracks.modes()).toEqual(['disabled', 'showing'])
    dispose()
  })

  it.each(['s3', 'off'] as const)('restores %s for native HLS without capturing early defaults as a new choice', async preference => {
    mock.supported = false
    vi.spyOn(HTMLMediaElement.prototype, 'canPlayType').mockReturnValue('maybe')
    const { tracks, options, remembered } = captions(preference)
    const dispose = attachHlsPlayback(options)
    await settle()
    tracks.replace(defaultTracks())
    await settle()
    options.video.dispatchEvent(new Event('loadedmetadata'))
    await settle()
    expect(remembered()).toBe(preference)
    expect(tracks.modes()).toEqual(preference === 's3' ? ['disabled', 'showing'] : ['disabled', 'disabled'])
    expect(options.onSubtitlePreferenceChange).not.toHaveBeenCalled()
    dispose()
  })

  it.each(['addtrack', 'loadedmetadata'])('retains a queued viewer choice when %s arrives first', async event => {
    const { tracks, options, remembered } = captions()
    const dispose = attachHlsPlayback(options)
    await settle()
    tracks.replace(defaultTracks())
    mock.instances[0].listeners.parsed()
    await settle()
    tracks.show(3)
    if (event === 'addtrack') {
      tracks[tracks.length++] = { kind: 'subtitles', label: 'Unlabelled (s4)', language: 'und', mode: 'disabled' } as TextTrack
      tracks.dispatchEvent(new Event('addtrack'))
    } else options.video.dispatchEvent(new Event('loadedmetadata'))
    await settle()
    expect(remembered()).toBe('s3')
    expect(tracks[0].mode).toBe('disabled')
    expect(tracks[1].mode).toBe('showing')
    dispose()
  })

  it('releases caption listeners before fatal-error destruction and ignores queued destruction events', async () => {
    const { tracks, options, remembered } = captions('s3')
    const remove = vi.spyOn(tracks, 'removeEventListener')
    const dispose = attachHlsPlayback(options)
    await settle()
    tracks.replace(defaultTracks())
    mock.instances[0].listeners.parsed()
    await settle()
    mock.instances[0].destroy.mockImplementation(() => { tracks.show('off') })
    mock.instances[0].listeners.error('error', { fatal: true })
    await settle()
    expect(remove).toHaveBeenCalledTimes(3)
    expect(remembered()).toBe('s3')
    dispose()
    expect(remove).toHaveBeenCalledTimes(3)
  })
})
