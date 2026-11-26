import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { toast } from 'sonner'
import type { GetSongIndexParams } from '@/shared/api-client/gen/endpoints'
import { Toaster } from '@/shared/components/ui/sonner'

// Mock shortcut display hook
vi.mock('@/shared/hooks/use-shortcut-display', () => ({
  useShortcutDisplay: (id: string) => {
    if (id === 'panel.info') return ['I']
    if (id === 'panel.lyrics') return ['L']
    if (id === 'transport.play-pause') return ['Space']
    return null
  },
}))

const mockNavigate = vi.fn()
vi.mock('react-router-dom', () => ({
  useNavigate: () => mockNavigate,
}))

const mockPlayTrack = vi.fn()
const mockInsertAfterCurrent = vi.fn()
const mockAddToQueue = vi.fn()
const mockGetSongIndex = vi.fn()
vi.mock('@/shared/api-client/gen/endpoints', () => ({
  getSongIndex: (...args: unknown[]) => mockGetSongIndex(...args),
}))

vi.mock('@/features/player/stores/player-store', () => ({
  usePlayerStore: (selector: (s: Record<string, unknown>) => unknown) =>
    selector({
      playTrack: mockPlayTrack,
      insertAfterCurrent: mockInsertAfterCurrent,
      addToQueue: mockAddToQueue,
    }),
}))

vi.mock('@/features/playlist/components/AddToPlaylistDialog', () => ({
  AddToPlaylistDialog: () => null,
}))

const mockSetSelectedItem = vi.fn()
const mockSetActiveTab = vi.fn()
vi.mock('@/features/layout/stores/context-panel-store', () => ({
  useContextPanelStore: (selector: (s: Record<string, unknown>) => unknown) =>
    selector({
      setSelectedItem: mockSetSelectedItem,
      setActiveTab: mockSetActiveTab,
    }),
}))

import { ArtistContextMenu } from '../ArtistContextMenu'

function songs(from: number, count: number) {
  return Array.from({ length: count }, (_, index) => ({
    publicId: `song-${from + index}`,
    title: `Song ${from + index}`,
    available: true,
  }))
}

/** Serves the artist's `total` songs a page at a time; the cursor is the next page's offset. */
async function songPage(total: number, params: GetSongIndexParams) {
  const offset = params.cursor === undefined ? 0 : Number(params.cursor)
  const limit = params.limit ?? 50
  const next = offset + limit

  return {
    data: songs(offset, Math.max(0, Math.min(limit, total - offset))),
    meta: {
      next_cursor: next < total ? String(next) : null,
      has_next_page: next < total,
    },
  }
}

function serveArtistSongs(total: number) {
  mockGetSongIndex.mockImplementation((params: GetSongIndexParams) => songPage(total, params))
}

describe('ArtistContextMenu', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  afterEach(() => {
    // Sonner keeps active toasts in a module-wide store and replays them to the next Toaster.
    toast.dismiss()
  })

  const artist = {
    publicId: 'artist-1',
    name: 'Test Artist',
  }

  function renderMenu() {
    return render(
      <>
        <Toaster />
        <ArtistContextMenu artist={artist}>
          <div data-testid="trigger">Artist Card</div>
        </ArtistContextMenu>
      </>,
    )
  }

  async function choose(action: string) {
    const user = userEvent.setup()
    renderMenu()
    await user.pointer({ keys: '[MouseRight]', target: screen.getByTestId('trigger') })
    await user.click(screen.getByText(action))
  }

  it('renders children as the trigger', () => {
    renderMenu()
    expect(screen.getByTestId('trigger')).toHaveTextContent('Artist Card')
  })

  it('shows menu items on right-click', async () => {
    const user = userEvent.setup()
    renderMenu()

    await user.pointer({ keys: '[MouseRight]', target: screen.getByTestId('trigger') })

    expect(screen.getByText('Shuffle All Songs')).toBeInTheDocument()
    expect(screen.getByText('Play All Albums')).toBeInTheDocument()
    expect(screen.getByText('Add to Playlist')).toBeInTheDocument()
    expect(screen.getByText('Get Info')).toBeInTheDocument()
  })

  it('displays keyboard shortcut for Get Info', async () => {
    const user = userEvent.setup()
    renderMenu()

    await user.pointer({ keys: '[MouseRight]', target: screen.getByTestId('trigger') })

    expect(screen.getByText('I')).toBeInTheDocument()
  })

  it.each(['Shuffle All Songs', 'Play All Albums'])('plays the available songs of the artist through %s', async (action) => {
    mockGetSongIndex.mockResolvedValue({
      data: [
        { publicId: 'song', title: 'Song', available: true },
        { publicId: 'missing', title: 'Missing', available: false },
      ],
      meta: { next_cursor: null, has_next_page: false },
    })

    await choose(action)

    await waitFor(() => expect(mockPlayTrack).toHaveBeenCalledWith(
      expect.objectContaining({ publicId: 'song' }),
      [expect.objectContaining({ publicId: 'song' })],
    ))
    expect(mockGetSongIndex).toHaveBeenCalledWith({ artistId: 'artist-1', limit: 100 })
  })

  it('queues every song of an artist with 250 songs', async () => {
    serveArtistSongs(250)

    await choose('Play All Albums')

    await waitFor(() => expect(mockPlayTrack).toHaveBeenCalledTimes(1))
    const [first, queue] = mockPlayTrack.mock.calls[0]
    expect(first).toEqual(expect.objectContaining({ publicId: 'song-0' }))
    expect(queue).toHaveLength(250)
    expect(queue.at(-1)).toEqual(expect.objectContaining({ publicId: 'song-249' }))
    expect(mockGetSongIndex.mock.calls.map(([params]) => params)).toEqual([
      { artistId: 'artist-1', limit: 100 },
      { artistId: 'artist-1', limit: 100, cursor: '100' },
      { artistId: 'artist-1', limit: 100, cursor: '200' },
    ])
  })

  it('queues nothing when a page fails and plays the artist after a retry', async () => {
    mockGetSongIndex.mockImplementation(async (params: GetSongIndexParams) => {
      if (params.cursor !== undefined) {
        throw new Error('Request failed with status code 500')
      }

      return songPage(250, params)
    })

    await choose('Play All Albums')

    expect(await screen.findByText("The artist's songs could not be loaded.")).toBeInTheDocument()
    expect(mockPlayTrack).not.toHaveBeenCalled()

    serveArtistSongs(250)
    // A click without pointer events: jsdom lacks the pointer capture the toast's swipe handling calls.
    fireEvent.click(screen.getByRole('button', { name: 'Retry' }))

    await waitFor(() => expect(mockPlayTrack).toHaveBeenCalledTimes(1))
    expect(mockPlayTrack.mock.calls[0][1]).toHaveLength(250)
  })

  it('queues the first 10,000 songs of a larger artist and says the queue was cut', async () => {
    serveArtistSongs(10_050)

    await choose('Play All Albums')

    await waitFor(() => expect(mockPlayTrack).toHaveBeenCalledTimes(1))
    const queue = mockPlayTrack.mock.calls[0][1]
    expect(queue).toHaveLength(10_000)
    expect(queue.at(-1)).toEqual(expect.objectContaining({ publicId: 'song-9999' }))
    expect(mockGetSongIndex).toHaveBeenCalledTimes(100)
    expect(await screen.findByText(
      "A queue holds at most 10,000 songs, so the artist's later songs were left out.",
    )).toBeInTheDocument()
  })
})
