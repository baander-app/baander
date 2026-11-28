import { Link, Navigate, useParams, useSearchParams } from 'react-router-dom'
import { Play } from 'lucide-react'
import styled from 'styled-components'
import { Button } from '@/shared/components/ui/button'
import { Skeleton } from '@/shared/components/ui/skeleton'
import { ProgressBar } from '@/shared/components/progress-bar'
import { ContentText } from '@/shared/typography'
import { textRole, focusVisibleRing } from '@/shared/theme'
import {
  useTvSeriesShow,
  useTvSeriesEpisodes,
  useTvEpisodeShow,
  type TvEpisodeResource,
  type TvSeriesResource,
} from '@/shared/api-client/gen/endpoints'
import { retryPersonRead } from '@/features/catalog/api/people-api'
import { WorkPeople } from '@/features/catalog/components/WorkPeople'
import { DirectorBrowseError, DirectorBrowseEmpty } from '@/features/catalog/components/DirectorBrowseLayout'
import { VideoArtwork } from '@/features/catalog/components/VideoArtwork'
import { VideoActionMenu } from '@/features/catalog/components/VideoActionMenu'
import { MetadataAttribution } from '@/features/catalog/components/MetadataAttribution'
import { ExtrasRow } from '@/features/catalog/components/ExtrasRow'
import { MoviePlayerOverlay } from '@/features/catalog/components/MoviePlayerOverlay'
import { ActiveVideoOverlay } from '@/features/catalog/components/ActiveVideoOverlay'
import { useVideoWatched } from '@/features/catalog/components/use-video-watched'
import { useVideoDetailPlayback } from '@/features/catalog/components/use-video-detail-playback'
import { EpisodeRow } from '../components/EpisodeRow'
import { SeasonTabs, UNSEASONED } from '../components/SeasonTabs'

const Container = styled.section`
  height: 100%;
  overflow-y: auto;
  display: grid;
  align-content: start;
  gap: var(--space-xl);
  padding: var(--space-lg);
`

const BackLink = styled(Link)`
  width: fit-content;
  ${textRole('body-sm')}
  color: var(--color-accent-text);
  ${focusVisibleRing}
`

const Header = styled.header`
  display: flex;
  align-items: flex-end;
  flex-wrap: wrap;
  gap: var(--space-lg);
  overflow-wrap: anywhere;
`

const Poster = styled(VideoArtwork)`
  width: clamp(7rem, 25vw, 12rem);
  flex: 0 0 auto;
`

const Still = styled(VideoArtwork)`
  width: min(100%, 20rem);
  flex: 0 0 auto;
`

const Copy = styled.div`
  min-width: min(100%, 13rem);
  flex: 1;
  display: grid;
  gap: var(--space-md);
`

const EpisodeList = styled.ul`
  list-style: none;
  padding: 0;
  display: grid;
  gap: 2px;
`

const EpisodeMetadata = styled.p`
  ${textRole('mono-sm')}
  color: var(--color-muted-foreground);
`

const PlaybackError = styled.p`
  ${textRole('body')}
  color: var(--color-destructive);
`

const Pager = styled.nav`
  display: flex;
  align-items: center;
  gap: var(--space-md);
  margin-block-start: var(--space-md);
`

const Loading = styled.div`
  display: grid;
  gap: var(--space-md);
`

function DetailLoading({ episode = false }: { episode?: boolean }) {
  return (
    <Loading role="status" aria-label="Loading title">
      <Header>
        <Skeleton style={{
          width: episode ? 'min(100%, 20rem)' : 'clamp(7rem, 25vw, 12rem)',
          aspectRatio: episode ? '16 / 9' : '2 / 3',
        }} />
        <Copy>
          <Skeleton style={{ width: 'min(100%, 12rem)', height: '2rem' }} />
          <Skeleton style={{ width: 'min(100%, 32rem)', height: '3rem' }} />
        </Copy>
      </Header>
      {episode ? null : <EpisodeLoading />}
    </Loading>
  )
}

function EpisodeLoading() {
  return (
    <Loading role="status" aria-label="Loading episodes">
      {[0, 1, 2].map(index => <Skeleton key={index} style={{ height: '3rem' }} />)}
    </Loading>
  )
}

function seriesPath(id: string) {
  return `/tv/series/${id}`
}

function episodePlay(episode: TvEpisodeResource) {
  const video = episode.videos.find(item => item.publicId === episode.defaultVideoPublicId)
  return video ? {
    titleKind: 'episode' as const,
    titleId: episode.publicId,
    videoId: video.publicId,
    startSeconds: video.positionSeconds ?? 0,
    title: episode.name,
    duration: video.duration,
  } : null
}

function SeriesDetail({ series }: { series: TvSeriesResource }) {
  const [params, setParams] = useSearchParams()
  const seasons = [...(series.seasons ?? [])].sort((a, b) => a.number - b.number)
  const unseasonedCount = series.unseasonedCount ?? 0
  const requested = params.get('season')
  const requestedSeason = seasons.find(season => season.publicId === requested)
  const defaultSeason = seasons.find(season => season.number > 0)?.publicId
    ?? (unseasonedCount > 0 ? UNSEASONED : seasons[0]?.publicId ?? UNSEASONED)
  const selected = requestedSeason?.publicId
    ?? (requested === UNSEASONED && unseasonedCount > 0 ? UNSEASONED : defaultSeason)
  const selectedSeason = seasons.find(season => season.publicId === selected)
  const owned = selectedSeason?.ownedCount ?? unseasonedCount
  const requestedPage = Number(params.get('page') ?? 1)
  const page = Number.isSafeInteger(requestedPage) && requestedPage > 0 ? requestedPage : 1
  const watched = useVideoWatched(series.publicId)
  const playback = useVideoDetailPlayback()
  const episodes = useTvSeriesEpisodes(series.publicId, {
    page,
    limit: 50,
    ...(selected === UNSEASONED ? { unseasoned: true } : { season: selected }),
  }, { query: { enabled: owned > 0, retry: retryPersonRead } })
  const results = episodes.data
  const rows: TvEpisodeResource[] | undefined = results?.data
  const canMarkSeries = seasons.some(season => season.ownedCount > 0) || unseasonedCount > 0

  function changePage(value: number) {
    const next = new URLSearchParams(params)
    next.set('page', String(value))
    setParams(next)
  }

  function changeSeason(season: string) {
    const next = new URLSearchParams(params)
    next.set('season', season)
    next.delete('page')
    setParams(next)
  }

  if (owned > 0 && !episodes.isError && results && page > results.meta.last_page) {
    const next = new URLSearchParams(params)
    next.set('page', String(results.meta.last_page))
    return <Navigate to={{ search: next.toString() }} replace />
  }

  function renderEpisodes() {
    if (owned === 0) return <DirectorBrowseEmpty>No available episodes.</DirectorBrowseEmpty>
    if (episodes.isError) return <DirectorBrowseError retry={episodes.refetch} />
    if (episodes.isPending) return <EpisodeLoading />
    if (!results || !rows?.length) return <DirectorBrowseEmpty>No available episodes.</DirectorBrowseEmpty>

    return (
      <>
        <EpisodeList>
          {rows.map(episode => {
            const target = { kind: 'episode' as const, publicId: episode.publicId }
            const pending = watched.pending
            const matchesPending = pending?.kind === 'series'
              || (pending?.kind === 'season' && pending.publicId === episode.seasonPublicId)
              || (pending?.kind === 'episode' && pending.publicId === episode.publicId)
            const isWatched = matchesPending ? pending.data.watched : episode.watched
            return (
              <EpisodeRow
                key={episode.publicId}
                episode={episode}
                watched={isWatched}
                actions={watched.actions(target)}
                onPlay={() => {
                  const selection = episodePlay(episode)
                  if (selection) playback.play(selection)
                }}
              />
            )
          })}
        </EpisodeList>
        {results.meta.last_page > 1 ? (
          <Pager aria-label="Episode pages">
            <Button variant="ghost" disabled={page <= 1} onClick={() => changePage(page - 1)}>Previous</Button>
            <Button variant="ghost" disabled={page >= results.meta.last_page} onClick={() => changePage(page + 1)}>Next</Button>
          </Pager>
        ) : null}
      </>
    )
  }

  return (
    <Container>
      <BackLink to={`/tv/shows?library=${series.libraryPublicId}`}>Back to TV shows</BackLink>
      <Header>
        <Poster source={series.posterUrl} />
        <Copy>
          <VideoActionMenu
            label={`Options for ${series.name}`}
            actions={watched.actions({ kind: 'series', publicId: series.publicId }, !canMarkSeries)}
          >
            <ContentText text={series.name} textRole="title-lg" element="h1" />
          </VideoActionMenu>
          {series.overview ? <ContentText text={series.overview} textRole="body" element="p" /> : null}
          <MetadataAttribution provider={series.metadataSource} />
        </Copy>
      </Header>
      {playback.error ? <PlaybackError role="alert">{playback.error}</PlaybackError> : null}
      {seasons.length || unseasonedCount ? (
        <SeasonTabs
          seasons={seasons}
          unseasonedCount={unseasonedCount}
          selected={selected}
          onChange={changeSeason}
          actions={season => watched.actions({ kind: 'season', publicId: season.publicId }, season.ownedCount === 0)}
        >
          {renderEpisodes()}
        </SeasonTabs>
      ) : <DirectorBrowseEmpty>No available episodes.</DirectorBrowseEmpty>}
      <ExtrasRow extras={series.extras ?? []} onPlay={playback.play} />
      <WorkPeople kind="series" publicId={series.publicId} />
      <ActiveVideoOverlay active={playback.active} onClose={playback.close} />
    </Container>
  )
}

export function TVSeriesDetailPage() {
  const { publicId = '' } = useParams<{ publicId: string }>()
  const series = useTvSeriesShow(publicId, { query: { retry: retryPersonRead } })
  if (series.isError) return <Container><DirectorBrowseError retry={series.refetch} /></Container>
  if (series.isPending) return <Container><DetailLoading /></Container>
  return <SeriesDetail key={publicId} series={series.data.data} />
}

function EpisodeDetail({ episode }: { episode: TvEpisodeResource }) {
  const watched = useVideoWatched(episode.seriesPublicId)
  const playback = useVideoDetailPlayback()
  const selection = episodePlay(episode)
  const isWatched = watched.pending?.data.watched ?? episode.watched
  const episodeNumber = episode.episodeNumber ?? episode.absoluteNumber

  return (
    <Container>
      <BackLink to={seriesPath(episode.seriesPublicId)}>
        <ContentText text={episode.seriesName} textRole="body-sm" />
      </BackLink>
      <Header>
        <Still source={episode.stillUrl} wide />
        <Copy>
          <VideoActionMenu
            label={`Options for ${episode.name}`}
            actions={watched.actions({ kind: 'episode', publicId: episode.publicId })}
          >
            <ContentText text={episode.name} textRole="title-lg" element="h1" />
          </VideoActionMenu>
          <EpisodeMetadata>
            {episode.seasonNumber === null ? null : `Season ${episode.seasonNumber} · `}
            {episodeNumber === null ? episode.airDate : `Episode ${episodeNumber}`}
          </EpisodeMetadata>
          {episode.overview ? <ContentText text={episode.overview} textRole="body" element="p" /> : null}
          {isWatched ? <EpisodeMetadata>Watched</EpisodeMetadata> : null}
          {!isWatched && episode.progress ? <ProgressBar value={episode.progress * 100} label="Progress" /> : null}
          {selection ? (
            <div>
              <Button onClick={() => playback.play(selection)}>
                <Play size={18} aria-hidden="true" />
                {selection.startSeconds > 0 ? 'Resume' : 'Play'}
              </Button>
            </div>
          ) : null}
          <MetadataAttribution provider={episode.metadataSource} />
        </Copy>
      </Header>
      {playback.error ? <PlaybackError role="alert">{playback.error}</PlaybackError> : null}
      <WorkPeople kind="episode" publicId={episode.publicId} />
      {playback.active ? (
        <MoviePlayerOverlay
          title={playback.active.title}
          videoId={playback.active.videoId}
          sourceDuration={playback.active.duration ?? undefined}
          startSeconds={playback.active.startSeconds}
          onClose={playback.close}
        />
      ) : null}
    </Container>
  )
}

export function TVEpisodeDetailPage() {
  const { publicId = '' } = useParams<{ publicId: string }>()
  const episode = useTvEpisodeShow(publicId, { query: { retry: retryPersonRead } })
  if (episode.isError) return <Container><DirectorBrowseError retry={episode.refetch} /></Container>
  if (episode.isPending) return <Container><DetailLoading episode /></Container>
  return <EpisodeDetail key={publicId} episode={episode.data.data} />
}
