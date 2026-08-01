import { useState, useEffect, useCallback } from 'react'
import styled, { css } from 'styled-components'
import { Globe, Check } from 'lucide-react'
import { getAvailableCountries, getSubscriptions, subscribeCountry, unsubscribeCountry, type CountryInfo, type CountrySubscription } from '@/features/radio/api/radio-api'
import { Input } from '@/shared/components/ui/input'
import { Skeleton } from '@/shared/components/ui/skeleton'
import { toast } from 'sonner'

const CountryGrid = styled.div`
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 0.5rem;

  @media (min-width: 640px) {
    grid-template-columns: repeat(3, 1fr);
  }

  @media (min-width: 768px) {
    grid-template-columns: repeat(4, 1fr);
  }

  @media (min-width: 1024px) {
    grid-template-columns: repeat(5, 1fr);
  }
`

const Container = styled.div`
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
`

const FilterInput = styled(Input)`
  max-width: 20rem;
`

const CountryButton = styled.button<{ $subscribed: boolean }>`
  display: flex;
  align-items: center;
  gap: 0.5rem;
  border-radius: var(--radius-md);
  border: 1px solid;
  padding: 0.75rem;
  text-align: left;
  cursor: pointer;
  transition: background-color 0.15s, border-color 0.15s;

  ${({ $subscribed }) =>
    $subscribed
      ? css`
          border-color: color-mix(in srgb, var(--color-primary) 30%, transparent);
          background-color: color-mix(in srgb, var(--color-primary) 5%, transparent);

          &:hover {
            background-color: color-mix(in srgb, var(--color-primary) 10%, transparent);
          }
        `
      : css`
          border-color: var(--color-border);

          &:hover {
            background-color: var(--color-muted);
          }
        `}
`

const CountryInfo = styled.div`
  min-width: 0;
  flex: 1;
`

const CountryName = styled.p`
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: 0.875rem;
  font-weight: 500;
`

const StationCount = styled.p`
  font-size: 0.75rem;
  color: var(--color-muted-foreground);
`

const EmptyMessage = styled.p`
  padding: 2rem 0;
  text-align: center;
  font-size: 0.875rem;
  color: var(--color-muted-foreground);
`

export function CountryPicker() {
  const [countries, setCountries] = useState<CountryInfo[]>([])
  const [subscriptions, setSubscriptions] = useState<CountrySubscription[]>([])
  const [loading, setLoading] = useState(true)
  const [subscribing, setSubscribing] = useState<string | null>(null)
  const [filter, setFilter] = useState('')

  const loadData = useCallback(async () => {
    try {
      const [countriesData, subsData] = await Promise.all([
        getAvailableCountries(),
        getSubscriptions(),
      ])
      setCountries(countriesData)
      setSubscriptions(subsData)
    } catch {
      // Error handling — show empty state
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    loadData()
  }, [loadData])

  const subscribedCodes = new Set(subscriptions.filter(Boolean).map((s) => s.countryCode.toUpperCase()))

  const handleToggle = async (code: string) => {
    setSubscribing(code)
    try {
      if (subscribedCodes.has(code.toUpperCase())) {
        const sub = subscriptions.find((s) => s.countryCode.toUpperCase() === code.toUpperCase())
        if (sub) {
          await unsubscribeCountry(sub.sourceId, code)
          setSubscriptions((prev) => prev.filter((s) => s.countryCode.toUpperCase() !== code.toUpperCase()))
        }
      } else {
        const newSub = await subscribeCountry(null, code)
        setSubscriptions((prev) => [...prev, newSub])
      }
    } catch (error) {
      toast.error('Failed to update subscription')
      console.error('Country toggle failed:', error)
    } finally {
      setSubscribing(null)
    }
  }

  const filtered = filter
    ? countries.filter((c) =>
        c.name.toLowerCase().includes(filter.toLowerCase()) ||
        c.code.toLowerCase().includes(filter.toLowerCase())
      )
    : countries

  const sorted = [...filtered].sort((a, b) => {
    const aSub = subscribedCodes.has(a.code.toUpperCase()) ? 0 : 1
    const bSub = subscribedCodes.has(b.code.toUpperCase()) ? 0 : 1
    if (aSub !== bSub) return aSub - bSub
    return b.station_count - a.station_count
  })

  if (loading) {
    return (
      <CountryGrid>
        {Array.from({ length: 12 }).map((_, i) => (
          <Skeleton key={i} style={{ height: '5rem', borderRadius: 'var(--radius-md)' }} />
        ))}
      </CountryGrid>
    )
  }

  return (
    <Container>
      <FilterInput
        placeholder="Filter countries..."
        value={filter}
        onChange={(e) => setFilter(e.target.value)}
      />

      <CountryGrid>
        {sorted.map((country) => {
          const isSubscribed = subscribedCodes.has(country.code.toUpperCase())
          const isToggling = subscribing === country.code

          return (
            <CountryButton
              key={country.code}
              onClick={() => handleToggle(country.code)}
              disabled={isToggling}
              $subscribed={isSubscribed}
            >
              <Globe size={16} style={{ color: isSubscribed ? 'var(--color-primary)' : 'var(--color-muted-foreground)' }} />
              <CountryInfo>
                <CountryName>{country.name}</CountryName>
                <StationCount>
                  {country.station_count} station{country.station_count !== 1 ? 's' : ''}
                </StationCount>
              </CountryInfo>
              {isSubscribed && <Check size={14} style={{ flexShrink: 0, color: 'var(--color-primary)' }} />}
            </CountryButton>
          )
        })}
      </CountryGrid>

      {sorted.length === 0 && (
        <EmptyMessage>
          {filter ? 'No countries match your filter.' : 'No countries available.'}
        </EmptyMessage>
      )}
    </Container>
  )
}
