import { useState, useEffect, useCallback } from 'react'
import styled, { css } from 'styled-components'
import { Search } from 'lucide-react'
import { getStations, type RadioStation } from '@/features/radio/api/radio-api'
import { getSubscriptions, type CountrySubscription } from '@/features/radio/api/radio-api'
import { getStarredStations, starStation, unstarStation, type StarredStation } from '@/features/radio/api/radio-api'
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
  const [stations, setStations] = useState<RadioStation[]>([])
  const [subscriptions, setSubscriptions] = useState<CountrySubscription[]>([])
  const [starred, setStarred] = useState<StarredStation[]>([])
  const [loading, setLoading] = useState(false)
  const [query, setQuery] = useState('')
  const [selectedCountry, setSelectedCountry] = useState<string>('')

  const starredIds = new Set(starred.map((s) => s.stationId))

  const loadStarred = useCallback(async () => {
    try {
      const data = await getStarredStations()
      setStarred(data)
    } catch {
      // ignore
    }
  }, [])

  useEffect(() => {
    const loadInitial = async () => {
      setLoading(true)
      try {
        const [subsData] = await Promise.all([
          getSubscriptions(),
          loadStarred(),
        ])
        setSubscriptions(subsData)
      } catch {
        // ignore
      } finally {
        setLoading(false)
      }
    }
    loadInitial()
  }, [loadStarred])

  useEffect(() => {
    if (!query && !selectedCountry) {
      setStations([])
      return
    }

    const timer = setTimeout(async () => {
      setLoading(true)
      try {
        const data = await getStations(selectedCountry || undefined, query || undefined)
        setStations(data)
      } catch {
        setStations([])
      } finally {
        setLoading(false)
      }
    }, 300)

    return () => clearTimeout(timer)
  }, [query, selectedCountry])

  const handleStar = async (stationId: string) => {
    try {
      const result = await starStation(stationId)
      setStarred((prev) => [...prev, result])
    } catch {
      // ignore
    }
  }

  const handleUnstar = async (stationId: string) => {
    try {
      await unstarStation(stationId)
      setStarred((prev) => prev.filter((s) => s.stationId !== stationId))
    } catch {
      // ignore
    }
  }

  const subscribedCountries = [...new Set(subscriptions.map((s) => s.countryCode.toUpperCase()))]

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
      ) : query || selectedCountry ? (
        <EmptyMessage>No stations found.</EmptyMessage>
      ) : (
        <EmptyMessage>
          Search or select a country to browse stations.
        </EmptyMessage>
      )}
    </Container>
  )
}
