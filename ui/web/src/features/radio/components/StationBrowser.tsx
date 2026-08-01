import { useState, useEffect } from 'react'
import styled, { css } from 'styled-components'
import { Search } from 'lucide-react'
import type { RadioStation, CountrySubscription } from '@/features/radio/api/radio-api'
import {
  useStations,
  useSubscriptions,
  useStarredStations,
  useStarStation,
  useUnstarStation,
} from '@/features/radio/hooks/use-radio-stations'
import { StationCard } from './StationCard'
import { Input } from '@/shared/components/ui/input'
import { Skeleton } from '@/shared/components/ui/skeleton'

const Container = styled.div`
  display: flex;
  flex-direction: column;
  gap: 1rem;
`

const FilterRow = styled.div`
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.5rem;
`

const SearchWrapper = styled.div`
  position: relative;
  flex: 1;
  max-width: 300px;
`

const SearchIcon = styled(Search)`
  position: absolute;
  left: 0.625rem;
  top: 50%;
  transform: translateY(-50%);
  color: var(--color-muted-foreground);
`

const SearchInput = styled(Input)`
  height: 2rem;
  padding-left: 2rem;
  font-size: 0.875rem;
`

const CountryFilters = styled.div`
  display: flex;
  flex-wrap: wrap;
  gap: 0.25rem;
`

const CountryButton = styled.button<{ $active: boolean }>`
  border-radius: 9999px;
  padding: 0.25rem 0.625rem;
  font-size: 0.75rem;
  border: none;
  cursor: pointer;
  transition: background-color 0.15s, color 0.15s;

  ${({ $active }) =>
    $active
      ? css`
          background-color: var(--color-primary);
          color: var(--color-primary-foreground);
        `
      : css`
          background-color: var(--color-muted);
          color: var(--color-muted-foreground);

          &:hover {
            background-color: var(--color-accent);
          }
        `}
`

const SkeletonList = styled.div`
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
`

const ResultsList = styled.div`
  display: flex;
  flex-direction: column;
  gap: 0.125rem;
`

const EmptyMessage = styled.p`
  padding: 2rem 0;
  text-align: center;
  font-size: 0.875rem;
  color: var(--color-muted-foreground);
`

export function StationBrowser() {
  const [query, setQuery] = useState('')
  const [selectedCountry, setSelectedCountry] = useState<string>('')
  const [debouncedQuery, setDebouncedQuery] = useState('')
  const [debouncedCountry, setDebouncedCountry] = useState('')

  // Debounce search + country changes so typing doesn't spam requests (300ms).
  useEffect(() => {
    const timer = setTimeout(() => {
      setDebouncedQuery(query)
      setDebouncedCountry(selectedCountry)
    }, 300)
    return () => clearTimeout(timer)
  }, [query, selectedCountry])

  const hasFilter = !!(debouncedQuery || debouncedCountry)
  const hasInput = !!(query || selectedCountry)

  const stationsQuery = useStations(debouncedCountry, debouncedQuery, hasFilter)
  const { data: subscriptions = [] } = useSubscriptions()
  const { data: starred = [] } = useStarredStations()
  const starMutation = useStarStation()
  const unstarMutation = useUnstarStation()

  const stations: RadioStation[] = stationsQuery.data ?? []
  const starredIds = new Set(starred.map((s) => s.stationId))
  // Match original timing: skeletons show only once the debounced filter is
  // active and a fetch is in flight (i.e. after the 300ms debounce elapses),
  // not during the debounce window itself.
  const loading = hasFilter && (stationsQuery.isLoading || stationsQuery.isFetching)

  const handleStar = (stationId: string) => {
    starMutation.mutate(stationId)
  }

  const handleUnstar = (stationId: string) => {
    unstarMutation.mutate(stationId)
  }

  const subscribedCountries = [...new Set(subscriptions.map((s: CountrySubscription) => s.countryCode.toUpperCase()))]

  return (
    <Container>
      {/* Filters */}
      <FilterRow>
        <SearchWrapper>
          <SearchIcon size={14} />
          <SearchInput
            placeholder="Search stations..."
            value={query}
            onChange={(e) => setQuery(e.target.value)}
          />
        </SearchWrapper>

        <CountryFilters>
          <CountryButton
            onClick={() => setSelectedCountry('')}
            $active={selectedCountry === ''}
          >
            All
          </CountryButton>
          {subscribedCountries.map((code) => (
            <CountryButton
              key={code}
              onClick={() => setSelectedCountry(code)}
              $active={selectedCountry === code}
            >
              {code}
            </CountryButton>
          ))}
        </CountryFilters>
      </FilterRow>

      {/* Results */}
      {loading ? (
        <SkeletonList>
          {Array.from({ length: 8 }).map((_, i) => (
            <Skeleton key={i} style={{ height: '3.5rem', borderRadius: 'var(--radius-md)' }} />
          ))}
        </SkeletonList>
      ) : stations.length > 0 ? (
        <ResultsList>
          {stations.map((station) => (
            <StationCard
              key={station.id}
              station={station}
              isStarred={starredIds.has(station.id)}
              onStar={handleStar}
              onUnstar={handleUnstar}
            />
          ))}
        </ResultsList>
      ) : hasInput ? (
        <EmptyMessage>No stations found.</EmptyMessage>
      ) : (
        <EmptyMessage>
          Search or select a country to browse stations.
        </EmptyMessage>
      )}
    </Container>
  )
}
