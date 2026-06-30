import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, fireEvent } from '@testing-library/react'
import { BrowserRouter } from 'react-router-dom'

const {
  mockSetIsPlaying,
  mockSeekTo,
  mockSetVolume,
  mockToggleMute,
  mockPlayNext,
  mockPlayPrevious,
  mockPlayTrack,
  mockRemoveFromQueue,
  mockClearQueue,
} = vi.hoisted(() => ({
  mockSetIsPlaying: vi.fn(),
  mockSeekTo: vi.fn(),
  mockSetVolume: vi.fn(),
  mockToggleMute: vi.fn(),
  mockPlayNext: vi.fn(),
  mockPlayPrevious: vi.fn(),
  mockPlayTrack: vi.fn(),
  mockRemoveFromQueue: vi.fn(),
  mockClearQueue: vi.fn(),
}))

const mockState = {
  currentTrack: null as { publicId: string; title: string; artistName?: string; albumName?: string; duration?: number; filePath?: string } | null,
  isPlaying: false,
  currentTime: 0,
  duration: 0,
  volume: 75,
  muted: false,
  queue: [] as unknown[],
  currentIndex: -1,
  shuffle: false,
  repeat: 'off' as const,
  setIsPlaying: mockSetIsPlaying,
  seekTo: mockSeekTo,
  setVolume: mockSetVolume,
  toggleMute: mockToggleMute,
  playNext: mockPlayNext,
  playPrevious: mockPlayPrevious,
  playTrack: mockPlayTrack,
  removeFromQueue: mockRemoveFromQueue,
  clearQueue: mockClearQueue,
}

vi.mock('@/features/player/stores/player-store', () => ({
  usePlayerStore: Object.assign(
    (selector: (s: unknown) => unknown) => selector(mockState),
    { getState: () => mockState },
  ),
}))

import { NowPlayingBar } from '@/features/player/components/NowPlayingBar'
import { QueueModal } from '@/features/player/components/QueueModal'

function renderWithRouter(ui: React.ReactElement) {
  return render(<BrowserRouter>{ui}</BrowserRouter>)
}

describe('NowPlayingBar', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockState.currentTrack = null
    mockState.isPlaying = false
    mockState.queue = []
    mockState.currentIndex = -1
  })

  it('renders nothing when no current track', () => {
    const { container } = renderWithRouter(<NowPlayingBar />)
    expect(container.firstChild).toBeNull()
  })

  it('renders track info and controls when track is playing', () => {
    mockState.currentTrack = {
      publicId: 'song_1',
      title: 'Test Song',
      artistName: 'Test Artist',
      albumName: 'Test Album',
      duration: 240,
    }
    mockState.isPlaying = true
    mockState.currentTime = 45
    mockState.duration = 240

    renderWithRouter(<NowPlayingBar />)

    expect(screen.getByText('Test Song')).toBeInTheDocument()
    expect(screen.getByText('Test Artist · Test Album')).toBeInTheDocument()
    expect(screen.getByLabelText('Pause')).toBeInTheDocument()
    expect(screen.getByLabelText('Next')).toBeInTheDocument()
    expect(screen.getByLabelText('Previous')).toBeInTheDocument()
  })
})

describe('QueueModal', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockState.queue = []
    mockState.currentIndex = -1
    mockState.currentTrack = null
  })

  it('renders nothing when closed', () => {
    const { container } = renderWithRouter(<QueueModal open={false} onClose={vi.fn()} />)
    expect(container.firstChild).toBeNull()
  })

  it('shows queue items when open', () => {
    mockState.queue = [
      { publicId: 's1', title: 'Song 1', artistName: 'Artist 1' },
      { publicId: 's2', title: 'Song 2', artistName: 'Artist 2' },
    ]
    mockState.currentIndex = 0
    mockState.currentTrack = { publicId: 's1', title: 'Song 1', artistName: 'Artist 1' }

    renderWithRouter(<QueueModal open={true} onClose={vi.fn()} />)

    expect(screen.getByText('Queue')).toBeInTheDocument()
    expect(screen.getByText('Song 1')).toBeInTheDocument()
    expect(screen.getByText('Song 2')).toBeInTheDocument()
    expect(screen.getByText('2 tracks')).toBeInTheDocument()
  })

  it('shows empty state when queue is empty', () => {
    renderWithRouter(<QueueModal open={true} onClose={vi.fn()} />)
    expect(screen.getByText('Queue is empty')).toBeInTheDocument()
  })

  it('calls onClose when backdrop is clicked', () => {
    const onClose = vi.fn()
    const { container } = renderWithRouter(<QueueModal open={true} onClose={onClose} />)
    // The QueueModal renders: Overlay > Backdrop + ModalCard
    // The Backdrop is a styled div with onClick={onClose}
    // In styled-components, the overlay is the top-level rendered div
    // The overlay is the root element
    const overlay = container.firstChild as HTMLElement
    // Click the overlay but not on the modal card
    // The overlay itself has no onClick, but its child Backdrop does
    // Backdrop is the first child of overlay
    const backdrop = overlay?.firstElementChild as HTMLElement
    // Verify it's the backdrop by checking it has no visible text
    expect(backdrop).toBeTruthy()
    fireEvent.click(backdrop)
    expect(onClose).toHaveBeenCalled()
  })
})
