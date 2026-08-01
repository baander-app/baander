import { describe, it, expect, vi, beforeEach } from 'vitest'
import { renderHook } from '@testing-library/react'

// vi.mock is hoisted, so use vi.hoisted() to create references accessible inside the factory
const {
  mockInitialize,
  mockConnectAudioElement,
  mockSetPlayingState,
  mockResumeContextIfNeeded,
  mockDestroy,
  mockSetAudioElement,
  mockSetIsPlaying,
  mockSetCurrentTime,
  mockSetDuration,
  mockPlayNext,
} = vi.hoisted(() => ({
  mockInitialize: vi.fn(),
  mockConnectAudioElement: vi.fn().mockResolvedValue(undefined),
  mockSetPlayingState: vi.fn(),
  mockResumeContextIfNeeded: vi.fn().mockResolvedValue(undefined),
  mockDestroy: vi.fn(),
  mockSetAudioElement: vi.fn(),
  mockSetIsPlaying: vi.fn(),
  mockSetCurrentTime: vi.fn(),
  mockSetDuration: vi.fn(),
  mockPlayNext: vi.fn(),
}))

vi.mock('@/features/player/services/audio-service', () => ({
  audioService: {
    initialize: mockInitialize,
    connectAudioElement: mockConnectAudioElement,
    setPlayingState: mockSetPlayingState,
    resumeContextIfNeeded: mockResumeContextIfNeeded,
    destroy: mockDestroy,
  },
}))

vi.mock('@/features/player/stores/player-store', () => {
  const mockState = {
    volume: 75,
    muted: false,
    repeat: 'off' as const,
    currentTrack: null,
    setAudioElement: mockSetAudioElement,
    setIsPlaying: mockSetIsPlaying,
    setCurrentTime: mockSetCurrentTime,
    setDuration: mockSetDuration,
    playNext: mockPlayNext,
  }
  return {
    usePlayerStore: Object.assign(
      (selector: (state: Record<string, unknown>) => unknown) => selector(mockState),
      { getState: () => mockState },
    ),
  }
})

import { useAudioPlayback } from '@/features/player/hooks/use-audio-playback'

beforeEach(() => {
  vi.clearAllMocks()
})

describe('useAudioPlayback', () => {
  it('creates and initializes audio element on mount', () => {
    renderHook(() => useAudioPlayback())

    expect(mockInitialize).toHaveBeenCalled()
    expect(mockSetAudioElement).toHaveBeenCalled()
    // connectAudioElement fires on loadstart (when audio.src is set), not on mount
  })

  it('destroys AudioService on unmount', () => {
    const { unmount } = renderHook(() => useAudioPlayback())
    unmount()

    expect(mockDestroy).toHaveBeenCalled()
  })
})
