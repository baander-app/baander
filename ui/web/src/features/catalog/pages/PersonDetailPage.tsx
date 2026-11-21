import { Link, Navigate, useLocation, useParams, useSearchParams } from 'react-router-dom'
import styled from 'styled-components'
import { ContentText } from '@/shared/typography'
import { focusVisibleRing, textRole } from '@/shared/theme'
import { Button } from '@/shared/components/ui/button'
import {
  creditLabel,
  retryPersonRead,
  workPath,
} from '../api/people-api'
import { usePersonShow, usePersonCredits, type PersonResource, type PersonWorkResource, type PersonWorkResourceKind } from '@/shared/api-client/gen/endpoints'
import { PersonPortrait } from '../components/PersonPortrait'
import {
  DirectorBrowseEmpty,
  DirectorBrowseError,
  DirectorBrowseLayout,
  DirectorBrowseLoading,
} from '../components/DirectorBrowseLayout'

const Biography = styled.div`
  display: grid;
  grid-template-columns: minmax(6rem, 10rem) minmax(0, 1fr);
  gap: var(--space-lg);
  margin-bottom: var(--space-lg);
  @media (max-width: 480px) {
    grid-template-columns: 1fr;
  }
`
const ProfileImage = styled.div`
  max-width: 10rem;
`
const Prose = styled.p`
  ${textRole('body')}
  white-space: pre-line;
  overflow-wrap: anywhere;
`
const Facts = styled.dl`
  ${textRole('body-sm')}
  display: grid;
  grid-template-columns: max-content minmax(0, 1fr);
  gap: var(--space-sm) var(--space-md);
  dt {
    color: var(--color-muted-foreground);
  }
  dd {
    margin: 0;
    overflow-wrap: anywhere;
  }
`
const Filters = styled.nav`
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-sm);
  margin-bottom: var(--space-lg);
`
const WorkList = styled.ul`
  list-style: none;
  padding: 0;
  li {
    padding-block: var(--space-md);
    border-bottom: 1px solid var(--color-border);
  }
`
const WorkLink = styled(Link)`
  max-width: 100%;
  overflow-wrap: anywhere;
  display: inline-block;
  color: var(--color-foreground);
  ${focusVisibleRing}
`
const Roles = styled.p`
  ${textRole('body-sm')}
  color: var(--color-muted-foreground);
  margin-block: var(--space-sm) 0;
  overflow-wrap: anywhere;
`
const Paging = styled.nav`
  display: flex;
  align-items: center;
  justify-content: center;
  gap: var(--space-md);
  ${textRole('body-sm')}
`
const kinds: { kind: PersonWorkResourceKind; label: string; count: keyof PersonResource }[] = [
  { kind: 'movie', label: 'Movies', count: 'movieCount' },
  { kind: 'series', label: 'TV series', count: 'seriesCount' },
  { kind: 'episode', label: 'Episodes', count: 'episodeCount' },
  { kind: 'song', label: 'Songs', count: 'songCount' },
  { kind: 'album', label: 'Albums', count: 'albumCount' },
]

export function PersonDetailPage() {
  const { publicId = '' } = useParams<{ publicId: string }>()
  const [params, setParams] = useSearchParams()
  const location = useLocation()
  const requested = Number(params.get('page') ?? 1)
  const page = Number.isSafeInteger(requested) && requested > 0 ? requested : 1
  const q = params.get('q') ?? ''
  const profile = usePersonShow(publicId, { query: { enabled: !!publicId, retry: retryPersonRead } })
  const person = profile.data?.data
  const selected = kinds.find(item => item.kind === params.get('kind'))?.kind
    ?? kinds.find(item => Number(person?.[item.count]) > 0)?.kind
    ?? 'movie'
  const credits = usePersonCredits(publicId, {
    kind: selected,
    page,
    limit: 50,
    q,
  }, { query: { enabled: !!publicId, retry: retryPersonRead } })
  const missing = [profile.error, credits.error].some(error => (
    (error as { response?: { status?: number } } | null)?.response?.status === 404
  ))
  const change = (key: string, value: string) => {
    setParams(previous => {
      const next = new URLSearchParams(previous)
      next.delete('page')
      if (value) {
        next.set(key, value)
      } else {
        next.delete(key)
      }
      return next
    }, { state: location.state })
  }
  if (!credits.isError && credits.data?.meta.current_page === page && page > credits.data.meta.last_page) {
    const next = new URLSearchParams(params)
    next.set('page', String(credits.data.meta.last_page))
    return <Navigate to={{ search: next.toString() }} state={location.state} replace />
  }
  const state = location.state as { peopleBrowse?: string; directorBrowse?: { search: string } } | null
  const backPath = state?.directorBrowse ? '/movies/directors' : '/people'
  const backSearch = state?.directorBrowse?.search ?? state?.peopleBrowse ?? ''
  const facts = person ? [
    ['Born', person.birthDate],
    ['Died', person.deathDate ?? (person.isDeceased ? 'Date unknown' : null)],
    [person.isDeceased ? 'Age at death' : 'Age', person.age === null ? null : String(person.age)],
    ['Birthplace', person.birthplace],
    ['Sex', person.sex],
    ['Gender', person.gender],
    ['Also known as', person.aliases.join(', ') || null],
  ] : []

  return (
    <DirectorBrowseLayout
      title={missing ? 'Person not found' : profile.isError ? 'Person' : person?.name ?? 'Person'}
      navigation={(
        <Link to={{ pathname: backPath, search: backSearch }} state={location.state}>
          Back to {state?.directorBrowse ? 'Directors' : 'People'}
        </Link>
      )}
      query={q}
      searchLabel="Search this person’s credits"
      onSearch={value => change('q', value)}
    >
      {missing ? (
        <DirectorBrowseEmpty>This person is unavailable.</DirectorBrowseEmpty>
      ) : profile.isError || credits.isError ? (
        <DirectorBrowseError retry={() => Promise.all([profile.refetch(), credits.refetch()])} />
      ) : profile.isPending ? (
        <DirectorBrowseLoading />
      ) : person ? (
        <>
          <Biography>
            <ProfileImage>
              <PersonPortrait url={person.profileUrl} />
            </ProfileImage>
            <div>
              {person.biography ? <Prose>{person.biography}</Prose> : null}
              <Facts>
                {facts.filter(([, value]) => value).map(([label, value]) => (
                  <div key={label} style={{ display: 'contents' }}>
                    <dt>{label}</dt>
                    <dd>{value}</dd>
                  </div>
                ))}
              </Facts>
            </div>
          </Biography>
          <Filters aria-label="Credit media type">
            {kinds.map(({ kind, label, count }) => (
              <Button
                key={kind}
                variant={kind === selected ? 'secondary' : 'ghost'}
                aria-pressed={kind === selected}
                onClick={() => change('kind', kind)}
              >
                {label} ({person[count]})
              </Button>
            ))}
          </Filters>
          {credits.isPending ? (
            <DirectorBrowseLoading />
          ) : !credits.data?.data.length ? (
            <DirectorBrowseEmpty>{q ? 'No matching credits.' : 'No available credits in this category.'}</DirectorBrowseEmpty>
          ) : (
            <>
              <WorkList>
                {(credits.data.data as PersonWorkResource[]).map(work => (
                  <li key={`${work.kind}:${work.publicId}`}>
                    <WorkLink to={workPath(work)}>
                      <ContentText text={work.title} textRole="body" element="span" />
                    </WorkLink>
                    <Roles>{work.roles.map(creditLabel).join(' · ')}</Roles>
                  </li>
                ))}
              </WorkList>
              {credits.data.meta.last_page > 1 ? (
                <Paging aria-label="Credit pages">
                  <Button
                    variant="ghost"
                    disabled={page <= 1}
                    onClick={() => change('page', String(page - 1))}
                  >
                    Previous
                  </Button>
                  <span>{page} / {credits.data.meta.last_page}</span>
                  <Button
                    variant="ghost"
                    disabled={page >= credits.data.meta.last_page}
                    onClick={() => change('page', String(page + 1))}
                  >
                    Next
                  </Button>
                </Paging>
              ) : null}
            </>
          )}
        </>
      ) : null}
    </DirectorBrowseLayout>
  )
}
