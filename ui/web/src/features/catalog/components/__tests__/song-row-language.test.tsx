import type { ReactNode } from 'react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { cleanup, render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { SearchResults } from '../SearchResults'
import { AlbumTrackList } from '../AlbumTrackList'
import { VirtualSongRow } from '../VirtualSongRow'
import { SongListItem } from '../SongListItem'
import { SongRow } from '../SongRow'

const { searchSongs } = vi.hoisted(() => ({
  searchSongs: [
    { publicId: 'song-1', title: '続きを見る', albumLanguage: 'eng', artistName: 'Hikaru', available: true },
    { publicId: 'song-2', title: '直', albumLanguage: 'jpn', artistName: '周杰倫', available: true },
  ],
}))

vi.mock('@/features/player/stores/player-store', () => ({
  usePlayerStore: (selector: (state: { currentTrack: null; playTrack: () => void }) => unknown) =>
    selector({ currentTrack: null, playTrack: () => {} }),
}))
vi.mock('@/shared/api-client/gen/endpoints', () => ({
  useGetAlbumIndex: () => ({ data: { data: [] } }),
  useGetArtistIndex: () => ({ data: { data: [] } }),
  useGetSongIndex: () => ({ data: { data: searchSongs } }),
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
})

describe('song rows', () => {
  it('give the title its album language and leave the artist to its own text', () => {
    render(
      <VirtualSongRow
        song={{ publicId: 'song-2', title: '直', albumLanguage: 'jpn', artistName: '周杰倫', albumName: 'Ongaku', available: true }}
        index={0}
        isPlaying={false}
        isFocused={false}
        onPlay={() => {}}
        onClick={() => {}}
        height={32}
        translateY={0}
      />,
    )

    const title = screen.getByText('直')
    const artist = screen.getByText('周杰倫')
    expect(title).toHaveAttribute('lang', 'ja')
    expect(artist).toHaveAttribute('lang', 'en')
    expect(title).not.toContainElement(artist)
    expect(screen.getByText('Ongaku')).toHaveAttribute('lang', 'ja')
  })

  it('use the album language in a search result and let kana override a non-CJK stored language', () => {
    render(
      <MemoryRouter>
        <SearchResults query="song" />
      </MemoryRouter>,
    )

    expect(screen.getByText('直')).toHaveAttribute('lang', 'ja')
    expect(screen.getByText('周杰倫')).toHaveAttribute('lang', 'en')
    expect(screen.getByText('続きを見る')).toHaveAttribute('lang', 'ja')
    expect(screen.getByText('Hikaru')).toHaveAttribute('lang', 'en')
  })

  it('give album track titles the album language and artists none', () => {
    render(
      <AlbumTrackList
        songs={[{ publicId: 'song-2', title: '直', artistName: '坂本龍一', available: true, length: 200 }]}
        albumTitle="Ongaku"
        albumLanguage="jpn"
        albumPublicId="album-1"
      />,
    )

    expect(screen.getByText('直')).toHaveAttribute('lang', 'ja')
    expect(screen.getByText('坂本龍一')).toHaveAttribute('lang', 'en')
  })

  it('give table and grid song rows the album language for the title only', () => {
    render(
      <>
        <table>
          <tbody>
            <SongListItem
              index={0}
              title="直"
              language="jpn"
              artistName="周杰倫"
              onClick={() => {}}
              onDoubleClick={() => {}}
            />
          </tbody>
        </table>
        <SongRow title="骨" language="ja" artistName="王菲" />
      </>,
    )

    expect(screen.getByText('直')).toHaveAttribute('lang', 'ja')
    expect(screen.getByText('周杰倫')).toHaveAttribute('lang', 'en')
    expect(screen.getByText('骨')).toHaveAttribute('lang', 'ja')
    expect(screen.getByText('王菲')).toHaveAttribute('lang', 'en')
  })
})
