import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, fireEvent } from '@testing-library/react'
import { ListRow, type ListSongData } from '../ListRow'
import { useSelectionStore } from '../../stores/selection-store'
import { useListColumnStore } from '../../stores/list-column-store'
import { usePlayerStore } from '@/features/player/stores/player-store'

// Mock SongContextMenu to just render children
vi.mock('../menus/SongContextMenu', () => ({
  SongContextMenu: ({ children }: any) => children,
}))

const baseSong: ListSongData = {
  publicId: 'song-1',
  title: 'Test Song',
  artistName: 'Test Artist',
  albumName: 'Test Album',
  year: 2024,
  duration: 245,
  index: 1,
}

const allSongs: ListSongData[] = [
  baseSong,
  { ...baseSong, publicId: 'song-2', title: 'Second Song', index: 2 },
]

const defaultStyle = { height: '32px', transform: 'translateY(0px)' }

describe('ListRow', () => {
  beforeEach(() => {
    useSelectionStore.getState().clear()
    useListColumnStore.setState({
      visibleColumns: ['#', 'title', 'artist', 'album', 'year', 'duration'],
      columnOrder: ['#', 'title', 'artist', 'album', 'year', 'genre', 'duration', 'bitrate', 'format', 'createdAt'],
    })
  })

  it('renders song data in visible columns', () => {
    render(<ListRow song={baseSong} allSongs={allSongs} style={defaultStyle} />)

    expect(screen.getByText('Test Song')).toBeInTheDocument()
    expect(screen.getByText('Test Artist')).toBeInTheDocument()
    expect(screen.getByText('Test Album')).toBeInTheDocument()
    expect(screen.getByText('2024')).toBeInTheDocument()
    expect(screen.getByText('4:05')).toBeInTheDocument()
  })

  it('renders index number', () => {
    render(<ListRow song={baseSong} allSongs={allSongs} style={defaultStyle} />)

    expect(screen.getByText('1')).toBeInTheDocument()
  })

  it('click selects the song', () => {
    render(<ListRow song={baseSong} allSongs={allSongs} style={defaultStyle} />)

    fireEvent.click(screen.getByText('Test Song'))

    const state = useSelectionStore.getState()
    expect(state.selectedId).toBe('song-1')
    expect(state.selectedType).toBe('song')
  })

  it('Enter plays the song with full queue context', () => {
    render(<ListRow song={baseSong} allSongs={allSongs} style={defaultStyle} />)

    const row = screen.getByRole('row')
    fireEvent.keyDown(row, { key: 'Enter' })

    const playerState = usePlayerStore.getState()
    expect(playerState.currentTrack?.publicId).toBe('song-1')
    expect(playerState.queue).toHaveLength(2)
  })

  it('applies selected styling when selected', () => {
    useSelectionStore.getState().select('song-1', 'song')

    render(<ListRow song={baseSong} allSongs={allSongs} style={defaultStyle} />)

    const row = screen.getByRole('row')
    // styled-components generates hashed class names; verify visual styling via computed style
    const style = getComputedStyle(row)
    expect(style.borderLeftColor).not.toBe('')
  })

  it('formats duration using formatDuration', () => {
    const song = { ...baseSong, duration: 0 }
    render(<ListRow song={song} allSongs={allSongs} style={defaultStyle} />)

    expect(screen.getByText('0:00')).toBeInTheDocument()
  })

  it('shows dash for missing duration', () => {
    const song = { ...baseSong, duration: undefined }
    render(<ListRow song={song} allSongs={allSongs} style={defaultStyle} />)

    expect(screen.getByText('—')).toBeInTheDocument()
  })
})
