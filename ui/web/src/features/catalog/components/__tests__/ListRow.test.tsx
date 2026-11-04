import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import type { ReactNode } from 'react'
import { render, screen, fireEvent } from '@testing-library/react'
import { registerPlaybackIntentHandler } from '@/features/session/services/playback-execution'
import { ListRow, type ListSongData } from '../ListRow'
import { useSelectionStore } from '../../stores/selection-store'
import { useListColumnStore } from '../../stores/list-column-store'
import { usePlayerStore } from '@/features/player/stores/player-store'

// Mock SongContextMenu to just render children
vi.mock('../menus/SongContextMenu', () => ({
  SongContextMenu: ({ children }: { children: ReactNode }) => children,
}))

const baseSong: ListSongData = {
  publicId: 'song-1',
  title: 'Test Song',
  available: true,
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
  const playback = vi.fn(async () => {})
  let unregister: () => void
  afterEach(() => unregister())
  beforeEach(() => {
    playback.mockClear()
    unregister = registerPlaybackIntentHandler(playback)
    usePlayerStore.getState().runtimeActions.clearQueue()
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
    expect(screen.getByText('04:05')).toBeInTheDocument()
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

    expect(playback).toHaveBeenCalledExactlyOnceWith({ action: 'load', media: { kind: 'song', publicId: 'song-1' },
      queue: ['song-1', 'song-2'], currentTrackIndex: 0, position: 0, desiredState: 'playing' })
    expect(usePlayerStore.getState().currentTrack).toBeNull()
  })

  it('keeps an unavailable song visible and selectable while Enter cannot play it', () => {
    usePlayerStore.getState().runtimeActions.clearQueue()
    const missing = { ...baseSong, available: false }
    render(<ListRow song={missing} allSongs={[missing]} style={defaultStyle} />)

    expect(screen.getByText('· Unavailable')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('row'))
    expect(useSelectionStore.getState().selectedId).toBe(missing.publicId)
    fireEvent.keyDown(screen.getByRole('row'), { key: 'Enter' })
    expect(usePlayerStore.getState().currentTrack).toBeNull()
    expect(playback).not.toHaveBeenCalled()
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

    expect(screen.getByText('00:00')).toBeInTheDocument()
  })

  it('shows dash for missing duration', () => {
    const song = { ...baseSong, duration: undefined }
    render(<ListRow song={song} allSongs={allSongs} style={defaultStyle} />)

    expect(screen.getByText('—')).toBeInTheDocument()
  })

  it('renders title and artist as separate elements, each with its own language', () => {
    const song = { ...baseSong, title: '直', albumLanguage: 'jpn', artistName: '周杰倫', albumName: 'Ongaku' }
    render(<ListRow song={song} allSongs={[song]} style={defaultStyle} />)

    const title = screen.getByText('直')
    const artist = screen.getByText('周杰倫')
    expect(title).toHaveAttribute('lang', 'ja')
    expect(artist).toHaveAttribute('lang', 'en')
    expect(title).not.toContainElement(artist)
    expect(screen.getByText('Ongaku')).toHaveAttribute('lang', 'ja')
  })
})
