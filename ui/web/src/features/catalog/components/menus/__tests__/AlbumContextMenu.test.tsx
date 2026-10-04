import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import MockAdapter from 'axios-mock-adapter'
import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'

vi.mock('@/features/auth/stores/auth-store', () => ({
  useAuthStore: { getState: () => ({ accessToken: null, refreshToken: null }) },
}))
vi.mock('@/features/auth/hooks/use-admin-check', () => ({
  useAdminCheck: () => ({ isAdmin: false }),
}))
vi.mock('@/shared/crypto/dpop-store', () => ({
  getDpopKeyPair: () => null,
  getDpopNonce: () => null,
  setDpopNonce: vi.fn(),
}))
vi.mock('@/shared/crypto/dpop-proof', () => ({ createDpopProof: vi.fn() }))

// Mock shortcut display hook
vi.mock('@/shared/hooks/use-shortcut-display', () => ({
  useShortcutDisplay: (id: string) => {
    if (id === 'panel.info') return ['I']
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

import { AlbumContextMenu } from '../AlbumContextMenu'

describe('AlbumContextMenu', () => {
  let api: MockAdapter

  beforeEach(() => {
    vi.clearAllMocks()
    api = new MockAdapter(AXIOS_INSTANCE, { onNoMatch: 'throwException' })
  })

  afterEach(() => api.restore())

  const album = {
    publicId: 'album-1',
    title: 'Test Album',
    artistName: 'Test Artist',
    artistPublicId: 'artist-1',
  }

  function renderMenu(tracks?: { publicId: string; title: string }[]) {
    return render(
      <AlbumContextMenu album={album} tracks={tracks}>
        <div data-testid="trigger">Album Card</div>
      </AlbumContextMenu>,
    )
  }

  it('renders children as the trigger', () => {
    renderMenu()
    expect(screen.getByTestId('trigger')).toHaveTextContent('Album Card')
  })

  it('shows menu items on right-click', async () => {
    const user = userEvent.setup()
    renderMenu()

    await user.pointer({ keys: '[MouseRight]', target: screen.getByTestId('trigger') })

    expect(screen.getByText('Play All')).toBeInTheDocument()
    expect(screen.getByText('Shuffle All')).toBeInTheDocument()
    expect(screen.getByText('Play Next')).toBeInTheDocument()
    expect(screen.getByText('Play Last')).toBeInTheDocument()
    expect(screen.getByText('Go to Artist')).toBeInTheDocument()
    expect(screen.getByText('Add to Playlist')).toBeInTheDocument()
    expect(screen.getByText('Get Info')).toBeInTheDocument()
  })

  it('displays keyboard shortcut for Get Info', async () => {
    const user = userEvent.setup()
    renderMenu()

    await user.pointer({ keys: '[MouseRight]', target: screen.getByTestId('trigger') })

    expect(screen.getByText('I')).toBeInTheDocument()
  })

  it('calls playTrack when Play All is clicked', async () => {
    const user = userEvent.setup()
    const tracks = [
      { publicId: 'song-1', title: 'Track 1' },
      { publicId: 'song-2', title: 'Track 2' },
    ]
    renderMenu(tracks)

    await user.pointer({ keys: '[MouseRight]', target: screen.getByTestId('trigger') })
    await user.click(screen.getByText('Play All'))

    expect(mockPlayTrack).toHaveBeenCalledWith(
      expect.objectContaining({ publicId: 'song-1' }),
      tracks,
    )
  })

  it('calls insertAfterCurrent when Play Next is clicked', async () => {
    const user = userEvent.setup()
    const tracks = [{ publicId: 'song-1', title: 'Track 1' }]
    renderMenu(tracks)

    await user.pointer({ keys: '[MouseRight]', target: screen.getByTestId('trigger') })
    await user.click(screen.getByText('Play Next'))

    expect(mockInsertAfterCurrent).toHaveBeenCalledWith(tracks)
  })

  it.each(['Play All', 'Shuffle All', 'Play Next', 'Play Last'])(
    'fetches songs through the generated client for %s',
    async (action) => {
      const songs = [
        { publicId: 'song-1', title: 'Track 1', artistName: 'Artist 1', length: 180 },
        { publicId: 'song-2', title: 'Track 2', artistName: null, length: null },
      ]
      const expectedTracks = songs.map((song) => ({
        publicId: song.publicId,
        title: song.title,
        artistName: song.artistName ?? undefined,
        duration: song.length ?? undefined,
        albumName: album.title,
        albumPublicId: album.publicId,
      }))
      api.onGet('/api/albums/album-1').reply(200, { data: { ...album, songs } })
      const user = userEvent.setup()
      renderMenu()

      await user.pointer({ keys: '[MouseRight]', target: screen.getByTestId('trigger') })
      await user.click(screen.getByText(action))

      await waitFor(() => {
        if (action === 'Play Next') {
          expect(mockInsertAfterCurrent).toHaveBeenCalledWith(expectedTracks)
        } else if (action === 'Play Last') {
          expect(mockAddToQueue.mock.calls).toEqual(expectedTracks.map((track) => [track]))
        } else if (action === 'Shuffle All') {
          expect(mockPlayTrack).toHaveBeenCalledOnce()
          const [first, queue] = mockPlayTrack.mock.calls[0]
          expect(queue).toHaveLength(expectedTracks.length)
          expect(queue).toEqual(expect.arrayContaining(expectedTracks))
          expect(first).toEqual(queue[0])
        } else {
          expect(mockPlayTrack).toHaveBeenCalledWith(expectedTracks[0], expectedTracks)
        }
      })
      expect(api.history.get).toHaveLength(1)
    },
  )

  it.each([
    { status: 200, body: { data: { songs: [] } } },
    { status: 404, body: { error: 'Album not found' } },
  ])('does not enqueue tracks when the album request returns $status without songs', async ({ status, body }) => {
    api.onGet('/api/albums/album-1').reply(status, body)
    const user = userEvent.setup()
    renderMenu()

    await user.pointer({ keys: '[MouseRight]', target: screen.getByTestId('trigger') })
    await user.click(screen.getByText('Play Next'))

    expect(api.history.get).toHaveLength(1)
    expect(mockPlayTrack).not.toHaveBeenCalled()
    expect(mockInsertAfterCurrent).not.toHaveBeenCalled()
    expect(mockAddToQueue).not.toHaveBeenCalled()
  })

  it('navigates to artist when Go to Artist is clicked', async () => {
    const user = userEvent.setup()
    renderMenu()

    await user.pointer({ keys: '[MouseRight]', target: screen.getByTestId('trigger') })
    await user.click(screen.getByText('Go to Artist'))

    expect(mockNavigate).toHaveBeenCalledWith('/artists/artist-1')
  })
})
