import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

// Mock shortcut display hook
vi.mock('@/shared/hooks/use-shortcut-display', () => ({
  useShortcutDisplay: (id: string) => {
    if (id === 'transport.play-pause') return ['Space']
    if (id === 'panel.lyrics') return ['L']
    if (id === 'panel.info') return ['I']
    return null
  },
}))

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

// Mock LyricsDialog to avoid QueryClient dependency
vi.mock('@/features/catalog/components/LyricsDialog', () => ({
  LyricsDialog: () => null,
}))

// Mock API hooks that require QueryClient
vi.mock('@/shared/api-client/gen/endpoints', () => ({
  useGetPlaylistIndex: () => ({ data: undefined }),
  usePostPlaylistAddSong: () => ({ mutateAsync: vi.fn() }),
}))

vi.mock('@tanstack/react-query', async () => {
  const actual = await vi.importActual('@tanstack/react-query')
  return {
    ...actual,
    useQueryClient: () => ({ invalidateQueries: vi.fn() }),
  }
})

// Mock context panel store
const mockSetSelectedItem = vi.fn()
const mockSetActiveTab = vi.fn()
vi.mock('@/features/layout/stores/context-panel-store', () => ({
  useContextPanelStore: (selector: (s: Record<string, unknown>) => unknown) =>
    selector({
      setSelectedItem: mockSetSelectedItem,
      setActiveTab: mockSetActiveTab,
    }),
}))

import { SongContextMenu } from '../SongContextMenu'

describe('SongContextMenu', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  const song = {
    publicId: 'song-1',
    title: 'Test Song',
    artistName: 'Test Artist',
    albumName: 'Test Album',
    duration: 180,
    albumId: 'album-1',
    artistId: 'artist-1',
  }

  function renderMenu() {
    return render(
      <SongContextMenu song={song}>
        <div data-testid="trigger">Song Row</div>
      </SongContextMenu>,
    )
  }

  it('renders children as the trigger', () => {
    renderMenu()
    expect(screen.getByTestId('trigger')).toHaveTextContent('Song Row')
  })

  it('shows menu items on right-click', async () => {
    const user = userEvent.setup()
    renderMenu()

    await user.pointer({ keys: '[MouseRight]', target: screen.getByTestId('trigger') })

    expect(screen.getByText('Play')).toBeInTheDocument()
    expect(screen.getByText('Play Next')).toBeInTheDocument()
    expect(screen.getByText('Play Last')).toBeInTheDocument()
    expect(screen.getByText('Go to Album')).toBeInTheDocument()
    expect(screen.getByText('Go to Artist')).toBeInTheDocument()
    expect(screen.getByText('Add to Queue')).toBeInTheDocument()
    expect(screen.getByText('Love')).toBeInTheDocument()
    expect(screen.getByText('Get Info')).toBeInTheDocument()
    expect(screen.getByText('Add to Playlist')).toBeInTheDocument()
  })

  it('displays keyboard shortcut for Play', async () => {
    const user = userEvent.setup()
    renderMenu()

    await user.pointer({ keys: '[MouseRight]', target: screen.getByTestId('trigger') })

    expect(screen.getByText('Space')).toBeInTheDocument()
  })

  it('displays keyboard shortcut for Get Info', async () => {
    const user = userEvent.setup()
    renderMenu()

    await user.pointer({ keys: '[MouseRight]', target: screen.getByTestId('trigger') })

    expect(screen.getByText('I')).toBeInTheDocument()
  })

  it('calls playTrack when Play is clicked', async () => {
    const user = userEvent.setup()
    renderMenu()

    await user.pointer({ keys: '[MouseRight]', target: screen.getByTestId('trigger') })
    await user.click(screen.getByText('Play'))

    expect(mockPlayTrack).toHaveBeenCalledWith(
      expect.objectContaining({ publicId: 'song-1' }),
      undefined,
    )
  })

  it('calls insertAfterCurrent when Play Next is clicked', async () => {
    const user = userEvent.setup()
    renderMenu()

    await user.pointer({ keys: '[MouseRight]', target: screen.getByTestId('trigger') })
    await user.click(screen.getByText('Play Next'))

    expect(mockInsertAfterCurrent).toHaveBeenCalledWith([
      expect.objectContaining({ publicId: 'song-1' }),
    ])
  })

  it('calls addToQueue when Play Last is clicked', async () => {
    const user = userEvent.setup()
    renderMenu()

    await user.pointer({ keys: '[MouseRight]', target: screen.getByTestId('trigger') })
    await user.click(screen.getByText('Play Last'))

    expect(mockAddToQueue).toHaveBeenCalledWith(
      expect.objectContaining({ publicId: 'song-1' }),
    )
  })

  it('navigates to info tab when Get Info is clicked', async () => {
    const user = userEvent.setup()
    renderMenu()

    await user.pointer({ keys: '[MouseRight]', target: screen.getByTestId('trigger') })
    await user.click(screen.getByText('Get Info'))

    expect(mockSetSelectedItem).toHaveBeenCalledWith({ type: 'song', publicId: 'song-1' })
    expect(mockSetActiveTab).toHaveBeenCalledWith('info')
  })
})
