import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useSubscriptions } from '../hooks/use-radio-stations'
import styled, { css } from 'styled-components'
import { Globe, Check } from 'lucide-react'
import { getAvailableCountries, subscribeCountry, unsubscribeCountry, type CountrySubscription } from '@/features/radio/api/radio-api'
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
  const queryClient = useQueryClient()
  const countriesQuery = useQuery({
    queryKey: ['radio', 'countries'],
    queryFn: getAvailableCountries,
    retry: false,
  })
  const subscriptionsQuery = useSubscriptions()
  const countries = countriesQuery.data ?? []
  const subscriptions = subscriptionsQuery.data ?? []
  const loading = countriesQuery.isPending || subscriptionsQuery.isPending
  const [filter, setFilter] = useState('')
  const subscribedCodes = new Set(
    subscriptions.filter(Boolean).map((s) => s.countryCode.toUpperCase()),
  )

  const toggle = useMutation({
    mutationFn: async (code: string) => {
      const sub = subscriptions.find(
        (item) => item.countryCode.toUpperCase() === code.toUpperCase(),
      )
      if (sub) {
        await unsubscribeCountry(sub.sourceId, code)
        return {
          code,
          subscription: null,
        }
      }
      return {
        code,
        subscription: await subscribeCountry(null, code),
      }
    },
    onSuccess: ({ code, subscription }) => {
      queryClient.setQueryData<CountrySubscription[]>(
        ['radio', 'subscriptions'],
        (previous = []) => {
          const remaining = previous.filter(
            (item) => item.countryCode.toUpperCase() !== code.toUpperCase(),
          )
          return subscription ? [...remaining, subscription] : remaining
        },
      )
    },
    onError: () => toast.error('Failed to update subscription'),
  })

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
          const isToggling = toggle.isPending

          return (
            <CountryButton
              key={country.code}
              onClick={() => toggle.mutate(country.code)}
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
