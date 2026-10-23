import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { attachHlsPlayback } from '@/features/catalog/hooks/hls-playback'

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
    expect(instance.config).toEqual({ autoStartLoad: false, startPosition: 18 })
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
