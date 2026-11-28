import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { act, cleanup } from '@testing-library/react'
import { render } from '../../../../../tests/test-utils'
import { useImageBlob } from '@/shared/hooks/use-image-blob'
import { VideoArtwork } from '../VideoArtwork'

vi.mock('@/shared/hooks/use-image-blob', () => ({
  useImageBlob: vi.fn((source?: string | null) => ({ src: source ? `blob:${source}` : null, isLoading: false })),
}))

const observers: ArtworkObserver[] = []

class ArtworkObserver {
  private target: Element | null = null
  private readonly callback: IntersectionObserverCallback
  readonly disconnect = vi.fn()
  readonly observe = vi.fn((target: Element) => { this.target = target })

  constructor(callback: IntersectionObserverCallback) {
    this.callback = callback
    observers.push(this)
  }

  intersect(isIntersecting: boolean, width = 80, height = 120) {
    this.callback([{
      target: this.target,
      isIntersecting,
      boundingClientRect: new DOMRect(0, 0, width, height),
    } as IntersectionObserverEntry], this as unknown as IntersectionObserver)
  }
}

beforeEach(() => {
  observers.length = 0
  vi.mocked(useImageBlob).mockClear()
  vi.stubGlobal('IntersectionObserver', ArtworkObserver)
})

afterEach(() => {
  cleanup()
  vi.unstubAllGlobals()
})

describe('authenticated video artwork admission', () => {
  it('keeps offscreen and hidden artwork on its fallback until it has a visible box', () => {
    const source = '/api/images/stillA0000000000000001'
    const { container, unmount } = render(<VideoArtwork source={source} />)
    expect(container.querySelector('img')).toBeNull()
    expect(container.querySelector('svg')).not.toBeNull()
    expect(useImageBlob).toHaveBeenLastCalledWith(null)

    act(() => observers[0].intersect(false))
    act(() => observers[0].intersect(true, 0, 0))
    expect(useImageBlob).toHaveBeenLastCalledWith(null)
    expect(container.querySelector('img')).toBeNull()

    act(() => observers[0].intersect(true))
    expect(useImageBlob).toHaveBeenLastCalledWith(source)
    expect(container.querySelector('img')).toHaveAttribute('src', `blob:${source}`)
    unmount()
    expect(observers[0].disconnect).toHaveBeenCalled()
  })

  it('requires a fresh visibility observation for a replacement source and clears old bytes', () => {
    const first = '/api/images/stillA0000000000000001'
    const second = '/api/images/stillB0000000000000001'
    const { container, rerender } = render(<VideoArtwork source={first} />)
    act(() => observers[0].intersect(true))
    expect(container.querySelector('img')).toHaveAttribute('src', `blob:${first}`)

    rerender(<VideoArtwork source={second} />)
    expect(observers[0].disconnect).toHaveBeenCalled()
    expect(useImageBlob).toHaveBeenLastCalledWith(null)
    expect(container.querySelector('img')).toBeNull()
    act(() => observers[0].intersect(true))
    expect(container.querySelector('img')).toBeNull()
    act(() => observers[1].intersect(true))
    expect(useImageBlob).toHaveBeenLastCalledWith(second)
    expect(container.querySelector('img')).toHaveAttribute('src', `blob:${second}`)
  })

  it('keeps native lazy loading for remote images and the real fallback for missing artwork', () => {
    const source = 'https://images.baander.app/poster.jpg'
    const { container, rerender } = render(<VideoArtwork source={source} />)
    expect(useImageBlob).toHaveBeenLastCalledWith(null)
    expect(observers).toHaveLength(0)
    expect(container.querySelector('img')).toHaveAttribute('src', source)
    expect(container.querySelector('img')).toHaveAttribute('loading', 'lazy')
    rerender(<VideoArtwork source={null} />)
    expect(container.querySelector('img')).toBeNull()
    expect(container.querySelector('svg')).not.toBeNull()
  })
})
