import { useState } from 'react'
import { Link } from 'react-router-dom'
import styled from 'styled-components'
import { ContentText } from '@/shared/typography'
import { Button } from '@/shared/components/ui/button'
import { focusVisibleRing, textRole } from '@/shared/theme'
import { useQuery } from '@tanstack/react-query'
import {
  getPersonMovieCreditsQueryOptions,
  getTvSeriesCreditsQueryOptions,
  getTvEpisodeCreditsQueryOptions,
  usePersonArtist,
  type WorkPersonResource,
} from '@/shared/api-client/gen/endpoints'
import { creditLabel, retryPersonRead } from '../api/people-api'
import { PersonPortrait } from './PersonPortrait'
import { DirectorBrowseError, DirectorBrowseLoading } from './DirectorBrowseLayout'

const Section = styled.section`
  padding: var(--space-lg);
`
const Credits = styled.ul`
  list-style: none;
  padding: 0;
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(min(100%, 15rem), 1fr));
  gap: var(--space-md);
`
const PersonLink = styled(Link)`
  min-width: 0;
  overflow-wrap: anywhere;
  display: flex;
  align-items: center;
  gap: var(--space-sm);
  color: var(--color-foreground);
  text-decoration: none;
  ${focusVisibleRing}
`
const Portrait = styled(PersonPortrait)`
  flex-shrink: 0;
  width: 3rem;
  height: 4rem;
  object-fit: cover;
  border-radius: var(--radius-sm);
`
const Role = styled.span`
  display: block;
  ${textRole('caption')}
  color: var(--color-muted-foreground);
`
interface WorkPeopleProps {
  kind: 'movie' | 'series' | 'episode'
  publicId: string
}

export function WorkPeople({ kind, publicId }: WorkPeopleProps) {
  const [page, setPage] = useState(1)
  const params = { page, limit: 50 }
  const options = { query: { retry: retryPersonRead } }
  const queries = {
    movie: getPersonMovieCreditsQueryOptions,
    series: getTvSeriesCreditsQueryOptions,
    episode: getTvEpisodeCreditsQueryOptions,
  }
  const query = useQuery(queries[kind](publicId, params, options))
  const occurrences = new Map<string, number>()
  const credits = (query.data?.data as WorkPersonResource[] | undefined)?.map(credit => {
    const key = JSON.stringify([
      credit.personPublicId,
      credit.role.role,
      credit.role.job,
      credit.role.characterName,
      credit.role.department,
      credit.role.creditedName,
      credit.role.billingOrder,
    ])
    const occurrence = occurrences.get(key) ?? 0
    occurrences.set(key, occurrence + 1)
    return { credit, key: `${key}:${occurrence}` }
  }) ?? []
  return (
    <Section aria-label="Credits">
      <ContentText text="Credits" textRole="title" element="h2" />
      {query.isError ? (
        <DirectorBrowseError retry={query.refetch} />
      ) : query.isPending ? (
        <DirectorBrowseLoading />
      ) : !query.data?.data.length ? (
        <Role>No credits available.</Role>
      ) : (
        <>
          <Credits>
            {credits.map(({ credit, key }) => (
              <li key={key}>
                <PersonLink to={`/people/${credit.personPublicId}`}>
                  {credit.profileUrl ? <Portrait url={credit.profileUrl} /> : null}
                  <div>
                    <ContentText text={credit.name} textRole="body" element="span" />
                    <Role>{creditLabel(credit.role)}</Role>
                  </div>
                </PersonLink>
              </li>
            ))}
          </Credits>
          {query.data.meta.last_page > 1 ? (
            <nav aria-label="Credit pages">
              <Button
                variant="ghost"
                disabled={page <= 1}
                onClick={() => setPage(page - 1)}
              >
                Previous
              </Button>
              <Button
                variant="ghost"
                disabled={page >= query.data.meta.last_page}
                onClick={() => setPage(page + 1)}
              >
                Next
              </Button>
            </nav>
          ) : null}
        </>
      )}
    </Section>
  )
}

export function ArtistPersonLink({ publicId }: { publicId: string }) {
  const person = usePersonArtist(publicId, { query: { enabled: !!publicId, retry: retryPersonRead } })
  if (person.isError || !person.data) return null
  return <Link to={`/people/${person.data.data.publicId}`}>Person and credits</Link>
}
