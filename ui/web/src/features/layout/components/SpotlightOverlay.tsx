import styled from 'styled-components'
import { useState, useCallback, useEffect } from 'react'
import { useGetAlbumIndex, useGetArtistIndex, useGetSongIndex } from '@/shared/api-client/gen/endpoints'
import { usePlayerStore, type Track } from '@/features/player/stores/player-store'
import { useContextPanelStore } from '@/features/layout/stores/context-panel-store'
import {
  CommandDialog,
  CommandEmpty,
  CommandGroup,
  CommandInput,
  CommandItem,
  CommandList,
  CommandSeparator,
} from '@/shared/components/ui/command'

const IconSvg = styled.svg`
  color: var(--color-muted-foreground);
  flex-shrink: 0;
`

const ItemLabel = styled.span`
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
`

const ItemMeta = styled.span`
  margin-left: auto;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: 0.75rem;
  color: var(--color-muted-foreground);
`

function asString(val: unknown): string {
  return (val ?? '') as string
}

export function SpotlightOverlay() {
  const [open, setOpen] = useState(false)
  const [query, setQuery] = useState('')
  const playTrack = usePlayerStore((s) => s.playTrack)
  const setSelectedItem = useContextPanelStore((s) => s.setSelectedItem)

  // Listen for Cmd+K
  useEffect(() => {
    function handleKeyDown(e: KeyboardEvent) {
      if ((e.metaKey || e.ctrlKey) && e.key === 'k') {
        e.preventDefault()
        setOpen((o) => !o)
      }
    }
    document.addEventListener('keydown', handleKeyDown)
    return () => document.removeEventListener('keydown', handleKeyDown)
  }, [])

  // Debounce query
  const [debouncedQuery, setDebouncedQuery] = useState('')
  useEffect(() => {
    const timer = setTimeout(() => setDebouncedQuery(query), 200)
    return () => clearTimeout(timer)
  }, [query])

  const { data: albumsData } = useGetAlbumIndex({ q: debouncedQuery, limit: 8 })
  const { data: artistsData } = useGetArtistIndex({ q: debouncedQuery, limit: 5 })
  const { data: songsData } = useGetSongIndex({ q: debouncedQuery, limit: 8 })

  const albums = (albumsData as any)?.data ?? []
  const artists = (artistsData as any)?.data ?? []
  const songs = (songsData as any)?.data ?? []
  const hasResults = albums.length > 0 || artists.length > 0 || songs.length > 0

  const handleSelectAlbum = useCallback((album: any) => {
    setSelectedItem({ type: 'album', publicId: asString(album.publicId) })
    setOpen(false)
  }, [setSelectedItem])

  const handlePlaySong = useCallback((_song: any, songList: any[], index: number) => {
    const tracks: Track[] = songList.map((s: any) => ({
      publicId: asString(s.publicId),
      title: asString(s.title),
      artistName: asString(s.artistName),
      albumName: asString(s.albumName),
      duration: typeof s.length === 'number' ? s.length : (s.duration as number | undefined),
    }))
    playTrack(tracks[index], tracks)
    setOpen(false)
  }, [playTrack])

  return (
    <CommandDialog open={open} onOpenChange={setOpen}>
      <CommandInput
        placeholder="Search albums, artists, songs..."
        value={query}
        onValueChange={setQuery}
      />
      <CommandList>
        {!debouncedQuery && (
          <CommandEmpty>Start typing to search...</CommandEmpty>
        )}
        {debouncedQuery && !hasResults && (
          <CommandEmpty>No results for "{debouncedQuery}"</CommandEmpty>
        )}

        {artists.length > 0 && (
          <>
            <CommandGroup heading="Artists">
              {artists.map((artist: any, i: number) => (
                <CommandItem
                  key={asString(artist.publicId) ?? `artist-${i}`}
                  value={`artist-${asString(artist.name)}`}
                  onSelect={() => {
                    setSelectedItem({ type: 'artist', publicId: asString(artist.publicId) })
                    setOpen(false)
                  }}
                >
                  <ArtistIcon />
                  <ItemLabel>{asString(artist.name)}</ItemLabel>
                </CommandItem>
              ))}
            </CommandGroup>
            <CommandSeparator />
          </>
        )}

        {albums.length > 0 && (
          <>
            <CommandGroup heading="Albums">
              {albums.map((album: any, i: number) => (
                <CommandItem
                  key={asString(album.publicId) ?? `album-${i}`}
                  value={`album-${asString(album.title)}`}
                  onSelect={() => handleSelectAlbum(album)}
                >
                  <DiscIcon />
                  <ItemLabel>{asString(album.title)}</ItemLabel>
                  <ItemMeta>{asString(album.artistName)}</ItemMeta>
                </CommandItem>
              ))}
            </CommandGroup>
            <CommandSeparator />
          </>
        )}

        {songs.length > 0 && (
          <CommandGroup heading="Songs">
            {songs.map((song: any, i: number) => (
              <CommandItem
                key={asString(song.publicId) ?? `song-${i}`}
                value={`song-${asString(song.title)}`}
                onSelect={() => handlePlaySong(song, songs, i)}
              >
                <MusicIcon />
                <ItemLabel>{asString(song.title)}</ItemLabel>
                <ItemMeta>{asString(song.artistName)}</ItemMeta>
              </CommandItem>
            ))}
          </CommandGroup>
        )}
      </CommandList>
    </CommandDialog>
  )
}

function MusicIcon() {
  return (
    <IconSvg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round">
      <path d="M9 18V5l12-2v13" />
      <circle cx="6" cy="18" r="3" />
      <circle cx="18" cy="16" r="3" />
    </IconSvg>
  )
}

function DiscIcon() {
  return (
    <IconSvg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round">
      <circle cx="12" cy="12" r="10" />
      <circle cx="12" cy="12" r="3" />
    </IconSvg>
  )
}

function ArtistIcon() {
  return (
    <IconSvg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round">
      <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" />
      <circle cx="12" cy="7" r="4" />
    </IconSvg>
  )
}
