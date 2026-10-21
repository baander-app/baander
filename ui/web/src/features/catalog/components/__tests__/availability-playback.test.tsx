import type { ReactNode } from 'react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { cleanup, fireEvent, render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { SearchResults } from '../SearchResults'
import { AlbumTrackList } from '../AlbumTrackList'
import { VirtualSongRow } from '../VirtualSongRow'
import { SpotlightOverlay } from '@/features/layout/components/SpotlightOverlay'

const { playTrack, songs } = vi.hoisted(() => ({
  playTrack: vi.fn(),
  songs: [
    { publicId: 'missing', title: 'Missing song', available: false, artistName: 'Artist' },
    { publicId: 'present', title: 'Present song', available: true, artistName: 'Artist' },
  ],
}))
vi.mock('@/features/player/stores/player-store', () => ({
  usePlayerStore: (selector: (state: { currentTrack: null; playTrack: typeof playTrack }) => unknown) =>
    selector({ currentTrack: null, playTrack }),
}))
vi.mock('@/shared/api-client/gen/endpoints', () => ({
  useGetAlbumIndex: () => ({ data: { data: [] } }),
  useGetArtistIndex: () => ({ data: { data: [] } }),
  useGetSongIndex: () => ({ data: { data: songs } }),
}))
vi.mock('@tanstack/react-virtual', () => ({
  useVirtualizer: ({ count }: { count: number }) => ({
    getTotalSize: () => count * 32,
    getVirtualItems: () => Array.from({ length: count }, (_, index) => ({ index, size: 32, start: index * 32 })),
  }),
}))
vi.mock('../menus/SongContextMenu', () => ({ SongContextMenu: ({ children }: { children: ReactNode }) => children }))
vi.mock('../menus/AlbumContextMenu', () => ({ AlbumContextMenu: ({ children }: { children: ReactNode }) => children }))
vi.mock('../menus/ArtistContextMenu', () => ({ ArtistContextMenu: ({ children }: { children: ReactNode }) => children }))

beforeEach(() => {
  cleanup()
  playTrack.mockClear()
  Element.prototype.scrollIntoView = vi.fn()
})

function expectPresentSelection() {
  expect(playTrack).toHaveBeenLastCalledWith(
    expect.objectContaining({ publicId: 'present', available: true }),
    [expect.objectContaining({ publicId: 'present', available: true })],
  )
}

describe('music availability in browse surfaces', () => {
  it('keeps unavailable search metadata visible and selects the right available result after filtering', () => {
    render(<MemoryRouter><SearchResults query="song" /></MemoryRouter>)
    expect(screen.getByText('· Unavailable')).toBeInTheDocument()
    fireEvent.click(screen.getByText('Missing song'))
    expect(playTrack).not.toHaveBeenCalled()
    fireEvent.click(screen.getByText('Present song'))
    expectPresentSelection()
  })

  it('keeps unavailable album rows selectable and blocks their hover and double-click playback', () => {
    render(<AlbumTrackList songs={songs} albumTitle="Album" albumPublicId="album" />)
    fireEvent.mouseEnter(screen.getByText('Missing song').closest('[style]')!)
    expect(screen.getByRole('button', { name: 'Play Missing song' })).toBeDisabled()
    fireEvent.doubleClick(screen.getByText('Missing song'))
    expect(playTrack).not.toHaveBeenCalled()
    fireEvent.doubleClick(screen.getByText('Present song'))
    expectPresentSelection()
  })

  it('updates an existing virtual row when its availability observation changes', () => {
    const onPlay = vi.fn()
    const props = { song: songs[1], index: 0, isPlaying: false, isFocused: false, onPlay, onClick: vi.fn(), height: 32, translateY: 0 }
    const { rerender } = render(<VirtualSongRow {...props} />)
    expect(screen.getByRole('button', { name: 'Play Present song' })).toBeEnabled()
    rerender(<VirtualSongRow {...props} song={{ ...songs[1], available: false }} />)
    expect(screen.getByText('· Unavailable')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Play Present song' })).toBeDisabled()
    fireEvent.doubleClick(screen.getByText('Present song'))
    expect(onPlay).not.toHaveBeenCalled()
  })

  it('keeps unavailable Spotlight results visible and queues the selected available public ID', () => {
    render(<SpotlightOverlay />)
    fireEvent.keyDown(document, { key: 'k', ctrlKey: true })
    expect(screen.getByText('· Unavailable')).toBeInTheDocument()
    fireEvent.click(screen.getByText('Missing song'))
    expect(playTrack).not.toHaveBeenCalled()
    fireEvent.click(screen.getByText('Present song'))
    expectPresentSelection()
  })
})
