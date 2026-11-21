import { Link, Navigate, useParams, useSearchParams } from 'react-router-dom'
import styled from 'styled-components'
import { ContentText } from '@/shared/typography'
import { Button } from '@/shared/components/ui/button'
import { textRole, focusVisibleRing } from '@/shared/theme'
import { useTvSeriesShow, useTvSeriesEpisodes, useTvEpisodeShow, type TvEpisodeResource } from '@/shared/api-client/gen/endpoints'
import { retryPersonRead } from '@/features/catalog/api/people-api'
import { WorkPeople } from '@/features/catalog/components/WorkPeople'
import { DirectorBrowseError, DirectorBrowseLoading } from '@/features/catalog/components/DirectorBrowseLayout'

const Container = styled.section`
  height: 100%;
  overflow-y: auto;
`
const Header = styled.header`
  overflow-wrap: anywhere;
  padding: var(--space-lg);
  p { ${textRole('body')} white-space: pre-line; }
`
const Episodes = styled.section`
  overflow-wrap: anywhere;
  padding: var(--space-lg);
  p { ${textRole('body')} }
  ul { list-style: none; padding: 0; }
  li { margin-block: var(--space-md); }
  a { color: var(--color-foreground); ${focusVisibleRing} }
`
export function TVSeriesDetailPage() {
  const { publicId = '' } = useParams<{ publicId: string }>()
  const [params, setParams] = useSearchParams()
  const requested = Number(params.get('page') ?? 1)
  const page = Number.isSafeInteger(requested) && requested > 0 ? requested : 1
  const series = useTvSeriesShow(publicId, { query: { retry: retryPersonRead } })
  const episodes = useTvSeriesEpisodes(publicId, { page, limit: 50 }, { query: { retry: retryPersonRead } })
  if (!episodes.isError && episodes.data?.meta.current_page === page && page > episodes.data.meta.last_page) {
    const next = new URLSearchParams(params)
    next.set('page', String(episodes.data.meta.last_page))
    return <Navigate to={{ search: next.toString() }} replace />
  }
  return (
    <Container>
      <Header>
        <Link to="/tv">Back to TV Shows</Link>
        {series.isError ? <DirectorBrowseError retry={series.refetch} /> : series.isPending ? <DirectorBrowseLoading /> : (
          <>
            <ContentText text={series.data.data.name} textRole="title-lg" element="h1" />
            {series.data.data.overview ? <p>{series.data.data.overview}</p> : null}
          </>
        )}
      </Header>
      {!series.isError && series.data ? (
        <>
          <WorkPeople key={publicId} kind="series" publicId={publicId} />
          <Episodes>
            <ContentText text="Episodes" textRole="title" element="h2" />
            {episodes.isError ? (
              <DirectorBrowseError retry={episodes.refetch} />
            ) : episodes.isPending ? (
              <DirectorBrowseLoading />
            ) : !episodes.data?.data.length ? (
              <p>No episodes registered yet.</p>
            ) : (
              <>
                <ul>
                  {(episodes.data.data as TvEpisodeResource[]).map(episode => (
                    <li key={episode.publicId}>
                      <Link to={`/tv/episodes/${episode.publicId}`}>
                        <ContentText
                          text={`S${episode.seasonNumber} E${episode.episodeNumber} · ${episode.name}`}
                          textRole="body"
                          element="span"
                        />
                      </Link>
                    </li>
                  ))}
                </ul>
                {episodes.data.meta.last_page > 1 ? (
                  <nav aria-label="Episode pages">
                    <Button
                      variant="ghost"
                      disabled={page <= 1}
                      onClick={() => setParams({ page: String(page - 1) })}
                    >
                      Previous
                    </Button>
                    <Button
                      variant="ghost"
                      disabled={page >= episodes.data.meta.last_page}
                      onClick={() => setParams({ page: String(page + 1) })}
                    >
                      Next
                    </Button>
                  </nav>
                ) : null}
              </>
            )}
          </Episodes>
        </>
      ) : null}
    </Container>
  )
}

export function TVEpisodeDetailPage() {
  const { publicId = '' } = useParams<{ publicId: string }>()
  const episode = useTvEpisodeShow(publicId, { query: { retry: retryPersonRead } })
  return (
    <Container>
      <Header>
        {episode.isError ? <DirectorBrowseError retry={episode.refetch} /> : episode.isPending ? <DirectorBrowseLoading /> : (
          <>
            <Link to={`/tv/series/${episode.data.data.seriesPublicId}`}>
              <ContentText text={episode.data.data.seriesName} textRole="body" element="span" />
            </Link>
            <ContentText text={episode.data.data.name} textRole="title-lg" element="h1" />
            <p>Season {episode.data.data.seasonNumber} · Episode {episode.data.data.episodeNumber}</p>
            {episode.data.data.airDate ? <p>Aired {episode.data.data.airDate}</p> : null}
            {episode.data.data.overview ? <p>{episode.data.data.overview}</p> : null}
          </>
        )}
      </Header>
      {!episode.isError && episode.data ? <WorkPeople key={publicId} kind="episode" publicId={publicId} /> : null}
    </Container>
  )
}
