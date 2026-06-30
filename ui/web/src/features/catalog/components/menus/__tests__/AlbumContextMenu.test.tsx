import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

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
  beforeEach(() => {
    vi.clearAllMocks()
  })

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

  it('navigates to artist when Go to Artist is clicked', async () => {
    const user = userEvent.setup()
    renderMenu()

    await user.pointer({ keys: '[MouseRight]', target: screen.getByTestId('trigger') })
    await user.click(screen.getByText('Go to Artist'))

    expect(mockNavigate).toHaveBeenCalledWith('/artists/artist-1')
  })
})
