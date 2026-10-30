import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

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
const mockGet = vi.fn()
vi.mock('@/shared/api-client/axios-instance', () => ({
  AXIOS_INSTANCE: { get: (...args: unknown[]) => mockGet(...args) },
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

describe('ArtistContextMenu', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  const artist = {
    publicId: 'artist-1',
    name: 'Test Artist',
  }

  function renderMenu() {
    return render(
      <ArtistContextMenu artist={artist}>
        <div data-testid="trigger">Artist Card</div>
      </ArtistContextMenu>,
    )
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

  it.each(['Shuffle All Songs', 'Play All Albums'])('plays available songs from the canonical collection through %s', async (action) => {
    mockGet.mockImplementation(async (url: string) => {
      if (url !== '/api/songs/') throw new Error('Collection route requires its canonical path')
      return { data: { data: [{ publicId: 'song', title: 'Song', available: true },
        { publicId: 'missing', title: 'Missing', available: false }] } }
    })
    const user = userEvent.setup()
    renderMenu()
    await user.pointer({ keys: '[MouseRight]', target: screen.getByTestId('trigger') })
    await user.click(screen.getByText(action))
    await waitFor(() => expect(mockPlayTrack).toHaveBeenCalledWith(
      expect.objectContaining({ publicId: 'song' }), [expect.objectContaining({ publicId: 'song' })],
    ))
  })
})
