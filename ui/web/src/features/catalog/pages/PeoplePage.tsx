import { Link, Navigate, useLocation, useSearchParams } from 'react-router-dom'
import styled from 'styled-components'
import { ContentText } from '@/shared/typography'
import { focusVisibleRing } from '@/shared/theme'
import { usePersonIndex, type PersonResource } from '@/shared/api-client/gen/endpoints'
import { retryPersonRead } from '../api/people-api'
import { PersonPortrait } from '../components/PersonPortrait'
import { PaginatedGrid } from '../components/PaginatedGrid'
import {
  DirectorBrowseEmpty,
  DirectorBrowseError,
  DirectorBrowseLayout,
  DirectorBrowseLoading,
} from '../components/DirectorBrowseLayout'

const Card = styled(Link)`
  min-width: 0;
  overflow-wrap: anywhere;
  display: flex;
  flex-direction: column;
  gap: var(--space-sm);
  padding: var(--space-md);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
  color: var(--color-foreground);
  text-decoration: none;
  ${focusVisibleRing}
`
export function PeoplePage() {
  const [params, setParams] = useSearchParams()
  const location = useLocation()
  const requested = Number(params.get('page') ?? 1)
  const page = Number.isSafeInteger(requested) && requested > 0 ? requested : 1
  const q = params.get('q') ?? ''
  const people = usePersonIndex({ page, limit: 50, q }, { query: { retry: retryPersonRead } })
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
    })
  }
  if (!people.isError && people.data?.meta.current_page === page && page > people.data.meta.last_page) {
    const next = new URLSearchParams(params)
    next.set('page', String(people.data.meta.last_page))
    return <Navigate to={{ search: next.toString() }} replace />
  }
  return (
    <DirectorBrowseLayout
      title="People"
      query={q}
      searchLabel="Search people"
      onSearch={value => change('q', value)}
    >
      {people.isError ? (
        <DirectorBrowseError retry={people.refetch} />
      ) : people.isPending ? (
        <DirectorBrowseLoading />
      ) : !people.data?.data.length ? (
        <DirectorBrowseEmpty>{q ? 'No people found.' : 'No people with available credits yet.'}</DirectorBrowseEmpty>
      ) : (
        <PaginatedGrid
          pagination={{
            currentPage: page,
            lastPage: people.data.meta.last_page,
            total: people.data.meta.total,
          }}
          onPageChange={value => change('page', String(value))}
        >
          {(people.data.data as PersonResource[]).map(person => (
            <Card
              key={person.publicId}
              to={`/people/${person.publicId}`}
              state={{ peopleBrowse: location.search }}
            >
              <PersonPortrait url={person.profileUrl} />
              <ContentText text={person.name} textRole="body" element="h2" />
            </Card>
          ))}
        </PaginatedGrid>
      )}
    </DirectorBrowseLayout>
  )
}
