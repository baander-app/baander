import { createElement, type PropsWithChildren } from 'react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { cleanup, renderHook } from '@testing-library/react'
import { audioService } from '@/features/player/services/audio-service'
import { usePlayerStore } from '@/features/player/stores/player-store'
import { useAudioPlayback } from '@/features/player/hooks/use-audio-playback'

vi.mock('@/features/player/services/audio-service', () => ({
  audioService: {
    initialize: vi.fn(),
    connectDualAudioElements: vi.fn().mockResolvedValue(undefined),
    getProcessor: vi.fn(() => null),
    setPlayingState: vi.fn(),
    resumeContextIfNeeded: vi.fn().mockResolvedValue(undefined),
    destroy: vi.fn(),
  },
}))

function wrapper({ children }: PropsWithChildren) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false, queryFn: async () => null } } })
  return createElement(QueryClientProvider, { client }, children)
}

beforeEach(() => {
  vi.clearAllMocks()
  usePlayerStore.setState(usePlayerStore.getInitialState(), true)
  vi.spyOn(HTMLMediaElement.prototype, 'pause').mockImplementation(() => {})
})

afterEach(() => {
  cleanup()
  vi.restoreAllMocks()
})

describe('useAudioPlayback lifecycle', () => {
  it('creates and initializes its owned audio element on mount', () => {
    renderHook(() => useAudioPlayback(), { wrapper })
    expect(audioService.initialize).toHaveBeenCalledOnce()
    expect(usePlayerStore.getState().audioElement).toBeInstanceOf(HTMLAudioElement)
    expect(usePlayerStore.getState().isPlaying).toBe(false)
  })

  it('destroys AudioService and releases its element on unmount', () => {
    const { unmount } = renderHook(() => useAudioPlayback(), { wrapper })
    unmount()
    expect(audioService.destroy).toHaveBeenCalledOnce()
    expect(usePlayerStore.getState().audioElement).toBeNull()
  })
})
