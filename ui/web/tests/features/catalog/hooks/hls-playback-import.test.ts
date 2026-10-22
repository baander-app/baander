import { describe, expect, it, vi } from 'vitest'
import { attachHlsPlayback } from '@/features/catalog/hooks/hls-playback'

vi.mock('hls.js', () => { throw new Error('Module unavailable') })

describe('HLS lazy import failure', () => {
  it.each([false, true])('reports only an attachment that remains active (disposed=%s)', async disposed => {
    const onError = vi.fn()
    const dispose = attachHlsPlayback({ video: document.createElement('video'),
      url: 'https://media.baander.app/master.m3u8', position: 0, onError, onReady: vi.fn() })
    if (disposed) dispose()
    await new Promise(resolve => setTimeout(resolve, 0))
    expect(onError).toHaveBeenCalledTimes(disposed ? 0 : 1)
    dispose()
  })
})
