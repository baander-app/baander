import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, fireEvent } from '@testing-library/react'
import { ActivityItem } from '../ActivityItem'
import type { ActivityEntry } from '../../types/activity'

// Mock SongContextMenu to just render children (avoids router/player dependency chain)
vi.mock('../menus/SongContextMenu', () => ({
  SongContextMenu: ({ children }: any) => children,
}))

// Mock selection store
const mockSelect = vi.fn()
vi.mock('../../stores/selection-store', () => ({
  useSelectionStore: (selector: (s: Record<string, unknown>) => unknown) =>
    selector({ selectedId: null, select: mockSelect }),
}))

// Mock player store
const mockPlayTrack = vi.fn()
vi.mock('@/features/player/stores/player-store', () => ({
  usePlayerStore: (selector: (s: Record<string, unknown>) => unknown) =>
    selector({ currentTrack: null, playTrack: mockPlayTrack }),
}))

const now = new Date()
const oneHourAgo = new Date(now.getTime() - 3600_000).toISOString()

function makeEntry(overrides: Partial<ActivityEntry> = {}): ActivityEntry {
  return {
    uuid: 'u1',
    publicId: 'p1',
    userId: 'user1',
    activityType: 'play',
    songId: 'song1',
    albumId: null,
    artistId: null,
    movieId: null,
    playCount: 1,
    love: false,
    lastPlayedAt: oneHourAgo,
    lastPlatform: 'web',
    lastPlayer: null,
    createdAt: oneHourAgo,
    ...overrides,
  }
}

describe('ActivityItem', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('renders song title', () => {
    render(<ActivityItem entry={makeEntry({ songTitle: 'My Song' })} />)
    expect(screen.getByText('My Song')).toBeInTheDocument()
  })

  it('falls back to songId when no title', () => {
    render(<ActivityItem entry={makeEntry({ songId: 'abc123', songTitle: null })} />)
    expect(screen.getByText('abc123')).toBeInTheDocument()
  })

  it('renders artist name', () => {
    render(<ActivityItem entry={makeEntry({ artistName: 'Artist X' })} />)
    expect(screen.getByText('Artist X')).toBeInTheDocument()
  })

  it('renders album name', () => {
    render(<ActivityItem entry={makeEntry({ albumName: 'Album Y' })} />)
    expect(screen.getByText('Album Y')).toBeInTheDocument()
  })

  it('renders relative timestamp', () => {
    render(<ActivityItem entry={makeEntry()} />)
    expect(screen.getByText(/ago$/)).toBeInTheDocument()
  })

  it('calls select on click', () => {
    render(<ActivityItem entry={makeEntry()} />)
    const item = screen.getByRole('listitem')
    fireEvent.click(item)
    expect(mockSelect).toHaveBeenCalledWith('p1', 'song')
  })

  it('plays song on Enter', () => {
    render(<ActivityItem entry={makeEntry({ songTitle: 'Test Song' })} />)
    const item = screen.getByRole('listitem')
    fireEvent.keyDown(item, { key: 'Enter' })
    expect(mockPlayTrack).toHaveBeenCalledWith(
      expect.objectContaining({ publicId: 'song1', title: 'Test Song' }),
    )
  })

  it('does not play on Enter when no songId', () => {
    render(<ActivityItem entry={makeEntry({ songId: null })} />)
    const item = screen.getByRole('listitem')
    fireEvent.keyDown(item, { key: 'Enter' })
    expect(mockPlayTrack).not.toHaveBeenCalled()
  })
})
