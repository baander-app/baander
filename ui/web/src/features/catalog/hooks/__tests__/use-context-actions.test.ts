import { describe, it, expect, vi, beforeEach } from 'vitest'
import { renderHook, act } from '@testing-library/react'

// Mock react-router-dom
const mockNavigate = vi.fn()
vi.mock('react-router-dom', () => ({
  useNavigate: () => mockNavigate,
}))

// Mock player store
const mockPlayTrack = vi.fn()
const mockInsertAfterCurrent = vi.fn()
const mockAddToQueue = vi.fn()

vi.mock('@/features/player/stores/player-store', () => ({
  usePlayerStore: (selector: (s: Record<string, unknown>) => unknown) =>
    selector({
      playTrack: mockPlayTrack,
      insertAfterCurrent: mockInsertAfterCurrent,
      addToQueue: mockAddToQueue,
    }),
}))

// Mock AddToPlaylistDialog
vi.mock('@/features/playlist/components/AddToPlaylistDialog', () => ({
  AddToPlaylistDialog: () => null,
}))

import { useContextActions, type SongContextMenuData } from '../use-context-actions'

describe('useContextActions', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  const sampleSong: SongContextMenuData = {
    publicId: 'song-1',
    title: 'Test Song',
    artistName: 'Test Artist',
    albumName: 'Test Album',
    duration: 180,
  }

  it('playSong calls playerStore.playTrack with a Track object', () => {
    const { result } = renderHook(() => useContextActions())
    act(() => {
      result.current.playSong(sampleSong)
    })
    expect(mockPlayTrack).toHaveBeenCalledWith(
      {
        publicId: 'song-1',
        title: 'Test Song',
        artistName: 'Test Artist',
        albumName: 'Test Album',
        duration: 180,
      },
      undefined,
    )
  })

  it('playSong passes queue when provided', () => {
    const { result } = renderHook(() => useContextActions())
    const queue = [
      { publicId: 'song-1', title: 'A' },
      { publicId: 'song-2', title: 'B' },
    ]
    act(() => {
      result.current.playSong(sampleSong, queue)
    })
    expect(mockPlayTrack).toHaveBeenCalledWith(
      expect.objectContaining({ publicId: 'song-1' }),
      queue,
    )
  })

  it('playNext calls insertAfterCurrent', () => {
    const { result } = renderHook(() => useContextActions())
    const tracks = [{ publicId: 'song-1', title: 'A' }]
    act(() => {
      result.current.playNext(tracks)
    })
    expect(mockInsertAfterCurrent).toHaveBeenCalledWith(tracks)
  })

  it('playLast calls addToQueue', () => {
    const { result } = renderHook(() => useContextActions())
    const track = { publicId: 'song-1', title: 'A' }
    act(() => {
      result.current.playLast(track)
    })
    expect(mockAddToQueue).toHaveBeenCalledWith(track)
  })

  it('goToAlbum navigates to /albums/:id', () => {
    const { result } = renderHook(() => useContextActions())
    act(() => {
      result.current.goToAlbum('album-42')
    })
    expect(mockNavigate).toHaveBeenCalledWith('/albums/album-42')
  })

  it('goToArtist navigates to /artists/:id', () => {
    const { result } = renderHook(() => useContextActions())
    act(() => {
      result.current.goToArtist('artist-7')
    })
    expect(mockNavigate).toHaveBeenCalledWith('/artists/artist-7')
  })

  it('toggleLove is a no-op and does not throw', () => {
    const { result } = renderHook(() => useContextActions())
    expect(() => result.current.toggleLove('song-1')).not.toThrow()
  })

  it('addToPlaylist does not crash with empty array', () => {
    const { result } = renderHook(() => useContextActions())
    expect(() => result.current.addToPlaylist([])).not.toThrow()
  })
})
