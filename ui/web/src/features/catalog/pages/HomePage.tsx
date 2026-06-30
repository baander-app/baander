import { useEffect, useState, useMemo } from 'react'
import styled from 'styled-components'
import { useGetAlbumIndex, useGetActivityHistory, useGetPlaylistIndex } from '@/shared/api-client/gen/endpoints'
import { useForYouViewModel } from '@/features/catalog/hooks/use-for-you-view-model'
import { usePlayerStore } from '@/features/player/stores/player-store'
import { usePlayAlbum } from '../hooks/use-play-album'
import { Skeleton } from '@/shared/components/ui/skeleton'
import { DashboardSection } from '@/shared/components/dashboard-section'
import { HorizontalScrollRow } from '@/shared/components/horizontal-scroll-row'
import { useContextPanelStore } from '@/features/layout/stores/context-panel-store'
import { asAlbumsFromItems } from '@/features/catalog/utils/api-adapters'
import type { AlbumSummary } from '@/features/catalog/types'
import { getStarredStations, getStations, type RadioStation } from '@/features/radio/api/radio-api'
import { useRadioPlayback } from '@/features/radio/hooks/use-radio-playback'
import { useRadioStore } from '@/features/radio/stores/radio-store'
import { Radio } from 'lucide-react'
import { useImageBlob } from '@/shared/hooks/use-image-blob'

// ── Shared card styles ──────────────────────────────────

const CoverCard = styled.button`
  width: 9rem;
  flex-shrink: 0;
  overflow: hidden;
  border-radius: var(--radius-lg);
  background-color: var(--color-card);
  text-align: left;
  transition: all 150ms ease-out;
  border: none;
  cursor: pointer;
  padding: 0;

  &:hover {
    transform: translateY(-0.125rem);
    box-shadow: var(--shadow-lg);
  }
`

const CoverImageArea = styled.div`
  aspect-ratio: 1;
  background-color: var(--color-secondary);

  img {
    width: 100%;
    height: 100%;
    object-fit: cover;
  }
`

const PlaceholderIcon = styled.div`
  display: flex;
  width: 100%;
  height: 100%;
  align-items: center;
  justify-content: center;
  color: color-mix(in srgb, var(--color-muted-foreground) 20%, transparent);
`

const CardInfo = styled.div`
  padding: 0.375rem;
`

const CardTitle = styled.p`
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: 0.75rem;
  font-weight: 500;
  color: var(--color-foreground);
`

const CardSubtitle = styled.p`
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: 11px;
  color: var(--color-muted-foreground);
`

const CoverCardWithRing = styled(CoverCard)<{ $active: boolean }>`
  ${({ $active }) =>
    $active &&
    `
    box-shadow: 0 0 0 1px var(--color-primary);
  `}
`

// ── Playlist card styles ────────────────────────────────

const PlaylistCardButton = styled.button`
  display: flex;
  width: 10rem;
  flex-shrink: 0;
  align-items: center;
  gap: 0.75rem;
  border-radius: var(--radius-lg);
  background-color: var(--color-card);
  padding: 0.75rem;
  text-align: left;
  transition: all 150ms ease-out;
  border: none;
  cursor: pointer;

  &:hover {
    transform: translateY(-0.125rem);
    box-shadow: var(--shadow-lg);
  }
`

const PlaylistIconBox = styled.div`
  display: flex;
  height: 2.5rem;
  width: 2.5rem;
  flex-shrink: 0;
  align-items: center;
  justify-content: center;
  border-radius: var(--radius-md);
  background-color: var(--color-secondary);
  color: color-mix(in srgb, var(--color-muted-foreground) 40%, transparent);
`

const PlaylistInfo = styled.div`
  min-width: 0;
`

const PlaylistTitle = styled.p`
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: 0.75rem;
  font-weight: 500;
  color: var(--color-foreground);
`

const PlaylistSub = styled.p`
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: 11px;
  color: var(--color-muted-foreground);
`

// ── Page layout ─────────────────────────────────────────

const PageContainer = styled.div`
  display: flex;
  height: 100%;
  flex-direction: column;
`

const PageHeader = styled.div`
  padding: 1rem 1.5rem;
`

const PageTitle = styled.h1`
  font-size: 1.125rem;
  font-weight: 600;
  letter-spacing: -0.025em;
`

const ContentArea = styled.div`
  flex: 1;
  overflow-y: auto;
  padding: 0 1.5rem 1.5rem;
`

const SectionsContainer = styled.div`
  display: flex;
  flex-direction: column;
  gap: 2rem;
`

// ── Skeleton ────────────────────────────────────────────

const SkeletonRow = styled.div`
  display: flex;
  gap: 0.75rem;
`

const SkeletonCard = styled.div`
  width: 9rem;
  flex-shrink: 0;
`

// ── Empty state ─────────────────────────────────────────

const EmptyContainer = styled.div`
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 0.75rem;
  padding: 5rem 0;
`

const EmptyText = styled.p`
  font-size: 0.875rem;
  color: var(--color-muted-foreground);
`

const EmptySubText = styled.p`
  font-size: 0.75rem;
  color: color-mix(in srgb, var(--color-muted-foreground) 70%, transparent);
`

// ── Album Card ───────────────────────────────────────────

function AlbumCard({ album }: { album: AlbumSummary }) {
  const { src } = useImageBlob(album.coverImage?.url ?? null)
  const setSelectedItem = useContextPanelStore((s) => s.setSelectedItem)
  const artistName = album.artists.length > 0 ? album.artists[0].name : undefined

  return (
    <CoverCard
      type="button"
      onClick={() => setSelectedItem({ type: 'album', publicId: album.publicId })}
    >
      <CoverImageArea>
        {src ? (
          <img src={src} alt={album.title} loading="lazy" />
        ) : (
          <PlaceholderIcon>
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5">
              <circle cx="12" cy="12" r="10" />
              <circle cx="12" cy="12" r="3" />
            </svg>
          </PlaceholderIcon>
        )}
      </CoverImageArea>
      <CardInfo>
        <CardTitle>{album.title}</CardTitle>
        {artistName && <CardSubtitle>{artistName}</CardSubtitle>}
      </CardInfo>
    </CoverCard>
  )
}

// ── Recently Played Card ─────────────────────────────────

interface RecentItem {
  songTitle: string | null
  songPublicId: string | null
  albumPublicId: string | null
  albumTitle: string | null
  coverImage: { url: string; blurhash: string | null } | null
  artistName: string | null
}

function RecentlyPlayedCard({ item }: { item: RecentItem }) {
  const { src } = useImageBlob(item.coverImage?.url ?? null)
  const setSelectedItem = useContextPanelStore((s) => s.setSelectedItem)
  const playTrack = usePlayerStore((s) => s.playTrack)
  const { playAlbum } = usePlayAlbum()

  const handleClick = () => {
    if (item.albumPublicId) {
      setSelectedItem({ type: 'album', publicId: item.albumPublicId })
    }
  }

  const handleDoubleClick = () => {
    if (item.songPublicId && item.songTitle) {
      playTrack({
        publicId: item.songPublicId,
        title: item.songTitle,
        artistName: item.artistName ?? undefined,
        albumPublicId: item.albumPublicId ?? undefined,
      })
    } else if (item.albumPublicId && item.albumTitle) {
      playAlbum(item.albumPublicId, item.albumTitle)
    }
  }

  return (
    <CoverCard type="button" onClick={handleClick} onDoubleClick={handleDoubleClick}>
      <CoverImageArea>
        {src ? (
          <img src={src} alt={item.albumTitle ?? ''} loading="lazy" />
        ) : (
          <PlaceholderIcon>
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5">
              <circle cx="12" cy="12" r="10" />
              <circle cx="12" cy="12" r="3" />
            </svg>
          </PlaceholderIcon>
        )}
      </CoverImageArea>
      <CardInfo>
        <CardTitle>{item.songTitle ?? 'Unknown'}</CardTitle>
        {item.artistName && <CardSubtitle>{item.artistName}</CardSubtitle>}
      </CardInfo>
    </CoverCard>
  )
}

// ── Playlist Card ────────────────────────────────────────

interface PlaylistItem {
  publicId: string
  name: string
  isSmart?: boolean
  songCount?: number
}

function PlaylistCard({ playlist }: { playlist: PlaylistItem }) {
  const setSelectedItem = useContextPanelStore((s) => s.setSelectedItem)

  return (
    <PlaylistCardButton
      type="button"
      onClick={() => setSelectedItem({ type: 'playlist', publicId: playlist.publicId })}
    >
      <PlaylistIconBox>
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5">
          <path d="M9 18V5l12-2v13" />
          <circle cx="6" cy="18" r="3" />
          <circle cx="18" cy="16" r="3" />
        </svg>
      </PlaylistIconBox>
      <PlaylistInfo>
        <PlaylistTitle>{playlist.name}</PlaylistTitle>
        <PlaylistSub>
          {playlist.isSmart ? 'Smart' : `${playlist.songCount ?? 0} tracks`}
        </PlaylistSub>
      </PlaylistInfo>
    </PlaylistCardButton>
  )
}

// ── Radio Station Card ───────────────────────────────────

function RadioStationCard({ station }: { station: RadioStation }) {
  const { start } = useRadioPlayback()
  const activeStation = useRadioStore((s) => s.activeStation)
  const isPlaying = useRadioStore((s) => s.isPlaying)
  const isActive = activeStation?.id === station.id

  return (
    <CoverCardWithRing
      type="button"
      onClick={() => start(station)}
      $active={isActive && isPlaying}
    >
      <CoverImageArea>
        {station.logo ? (
          <img src={station.logo} alt="" loading="lazy" />
        ) : (
          <PlaceholderIcon>
            <Radio size="24" />
          </PlaceholderIcon>
        )}
      </CoverImageArea>
      <CardInfo>
        <CardTitle>{station.name}</CardTitle>
        <CardSubtitle>
          {station.country}{station.genres.length > 0 ? ` · ${station.genres[0]}` : ''}
        </CardSubtitle>
      </CardInfo>
    </CoverCardWithRing>
  )
}

// ── Skeleton ─────────────────────────────────────────────

function SectionSkeleton() {
  return (
    <div>
      <Skeleton style={{ marginBottom: '0.75rem', height: '1rem', width: '8rem' }} />
      <SkeletonRow>
        {Array.from({ length: 8 }).map((_, i) => (
          <SkeletonCard key={i}>
            <Skeleton style={{ aspectRatio: '1', borderRadius: '0.5rem' }} />
            <Skeleton style={{ marginTop: '0.375rem', height: '0.75rem', width: '7rem' }} />
          </SkeletonCard>
        ))}
      </SkeletonRow>
    </div>
  )
}

// ── For You Song Card ────────────────────────────────────

const ForYouCard = styled(CoverCard)``

// ── Home Page ────────────────────────────────────────────

export function HomePage() {
  const { data: albumData, isLoading: albumsLoading } = useGetAlbumIndex({ page: 1, limit: 20 })
  const { data: activityData, isLoading: activityLoading } = useGetActivityHistory({ limit: 10 })
  const { data: playlistData, isLoading: playlistsLoading } = useGetPlaylistIndex()

  const albums = useMemo(() => {
    const response = albumData as Record<string, unknown> | undefined
    const items = Array.isArray(response?.data) ? response.data : []
    return asAlbumsFromItems(items)
  }, [albumData])

  const recentlyAdded = albums.slice(0, 8)
  const allAlbums = albums.slice(0, 16)

  const recentlyPlayed = useMemo(() => {
    const response = activityData as Record<string, unknown> | undefined
    const items = Array.isArray(response?.data) ? (response.data as Record<string, unknown>[]) : []
    return items
      .filter((item) => item.songTitle || item.albumTitle)
      .map((item) => ({
        songTitle: (item.songTitle as string) ?? null,
        songPublicId: (item.songPublicId as string) ?? null,
        albumPublicId: (item.albumPublicId as string) ?? null,
        albumTitle: (item.albumTitle as string) ?? null,
        coverImage: item.coverImage as { url: string; blurhash: string | null } | null ?? null,
        artistName: (item.artistName as string) ?? null,
      })) as RecentItem[]
  }, [activityData])

  const playlists = useMemo(() => {
    const response = playlistData as Record<string, unknown> | undefined
    return (Array.isArray(response?.data) ? response.data : []) as PlaylistItem[]
  }, [playlistData])

  // Radio stations: load starred stations
  const [radioStations, setRadioStations] = useState<RadioStation[]>([])
  const [radioLoading, setRadioLoading] = useState(true)
  const { songs: forYouSongs, isLoading: forYouLoading } = useForYouViewModel(12)

  useEffect(() => {
    let cancelled = false
    ;(async () => {
      try {
        const starred = await getStarredStations()
        if (starred.length > 0) {
          const allStations = await getStations()
          const starredIds = new Set(starred.map((s) => s.stationId))
          if (!cancelled) setRadioStations(allStations.filter((s) => starredIds.has(s.id)))
        }
      } catch {
        // ignore
      } finally {
        if (!cancelled) setRadioLoading(false)
      }
    })()
    return () => { cancelled = true }
  }, [])

  const isLoading = albumsLoading || activityLoading || playlistsLoading || radioLoading || forYouLoading
  const hasContent = recentlyAdded.length > 0 || recentlyPlayed.length > 0 || playlists.length > 0 || radioStations.length > 0 || forYouSongs.length > 0

  return (
    <PageContainer>
      <PageHeader>
        <PageTitle>Home</PageTitle>
      </PageHeader>

      <ContentArea>
        {isLoading ? (
          <SectionsContainer>
            <SectionSkeleton />
            <SectionSkeleton />
            <SectionSkeleton />
          </SectionsContainer>
        ) : !hasContent ? (
          <EmptyHomeState />
        ) : (
          <SectionsContainer>
            {/* Radio Stations */}
            {radioStations.length > 0 && (
              <DashboardSection title="Radio Stations">
                <HorizontalScrollRow>
                  {radioStations.map((station) => (
                    <RadioStationCard key={station.id} station={station} />
                  ))}
                </HorizontalScrollRow>
              </DashboardSection>
            )}

            {/* For You */}
            {forYouSongs.length > 0 && (
              <DashboardSection title="For You">
                <HorizontalScrollRow>
                  {forYouSongs.map((song) => (
                    <ForYouCard
                      key={song.publicId}
                      type="button"
                      onClick={() => {
                        const { playTrack } = usePlayerStore.getState()
                        playTrack({
                          publicId: song.publicId,
                          title: song.title,
                          duration: song.duration ?? undefined,
                        })
                      }}
                    >
                      <CoverImageArea>
                        <PlaceholderIcon>
                          <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5">
                            <circle cx="12" cy="12" r="10" />
                            <circle cx="12" cy="12" r="3" />
                          </svg>
                        </PlaceholderIcon>
                      </CoverImageArea>
                      <CardInfo>
                        <CardTitle>{song.title}</CardTitle>
                        <CardSubtitle>{song.explanation}</CardSubtitle>
                      </CardInfo>
                    </ForYouCard>
                  ))}
                </HorizontalScrollRow>
              </DashboardSection>
            )}

            {/* Recently Played */}
            {recentlyPlayed.length > 0 && (
              <DashboardSection title="Recently Played">
                <HorizontalScrollRow>
                  {recentlyPlayed.map((item, i) => (
                    <RecentlyPlayedCard key={`recent-${item.albumPublicId ?? i}-${item.songPublicId ?? i}`} item={item} />
                  ))}
                </HorizontalScrollRow>
              </DashboardSection>
            )}

            {/* Recently Added */}
            {recentlyAdded.length > 0 && (
              <DashboardSection title="Recently Added">
                <HorizontalScrollRow>
                  {recentlyAdded.map((album) => (
                    <AlbumCard key={album.publicId} album={album} />
                  ))}
                </HorizontalScrollRow>
              </DashboardSection>
            )}

            {/* Playlists */}
            {playlists.length > 0 && (
              <DashboardSection title="Playlists">
                <HorizontalScrollRow>
                  {playlists.map((playlist) => (
                    <PlaylistCard key={playlist.publicId} playlist={playlist} />
                  ))}
                </HorizontalScrollRow>
              </DashboardSection>
            )}

            {/* All Albums */}
            {allAlbums.length > 0 && (
              <DashboardSection title="Albums">
                <HorizontalScrollRow>
                  {allAlbums.map((album) => (
                    <AlbumCard key={album.publicId} album={album} />
                  ))}
                </HorizontalScrollRow>
              </DashboardSection>
            )}
          </SectionsContainer>
        )}
      </ContentArea>
    </PageContainer>
  )
}

function EmptyHomeState() {
  return (
    <EmptyContainer>
      <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round" style={{ color: 'color-mix(in srgb, var(--color-muted-foreground) 30%, transparent)' }}>
        <path d="M3 9l9-7 9 7v11" />
        <path d="M9 21V9" />
        <circle cx="18" cy="6" r="3" />
        <circle cx="6" cy="18" r="3" />
        <circle cx="18" cy="18" r="3" />
      </svg>
      <EmptyText>Your library is empty</EmptyText>
      <EmptySubText>Add your music library to get started</EmptySubText>
    </EmptyContainer>
  )
}
