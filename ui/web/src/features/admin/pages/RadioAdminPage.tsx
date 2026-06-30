import styled from 'styled-components'
import { useState, useMemo } from 'react'
import { Radio } from 'lucide-react'
import { useRadioStations, useRadioCountries } from '../hooks/use-radio-admin'
import { Skeleton } from '@/shared/components/ui/skeleton'
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/shared/components/ui/select'
import { Input } from '@/shared/components/ui/input'
import { StatDisplay, StatDisplaySkeleton } from '@/shared/components/stat-display'
import { AdminPageHeader } from '../components/layout/AdminPageHeader'
import { formatRelativeTime } from '@/shared/utils/format-relative-time'

const Container = styled.div`
  display: flex;
  flex-direction: column;
  gap: 1.5rem;
  padding: 1.5rem;
`

const StatsBar = styled.div`
  display: flex;
  gap: 1.5rem;
`

const FilterRow = styled.div`
  display: flex;
  gap: 0.75rem;
`

const TableWrapper = styled.div`
  overflow-x: auto;
`

const StyledTable = styled.table`
  width: 100%;
  font-size: 0.8125rem;
`

const HeadRow = styled.tr`
  border-bottom: 1px solid var(--color-border);
  text-align: left;
  font-size: 0.6875rem;
  font-weight: 500;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--color-muted-foreground);
`

const Th = styled.th`
  padding-bottom: 0.5rem;
  padding-right: 1rem;
`

const ThLast = styled.th`
  padding-bottom: 0.5rem;
`

const BodyRow = styled.tr`
  border-bottom: 1px solid color-mix(in srgb, var(--color-border) 50%, transparent);
  transition: background-color var(--duration-hover) ease-out;

  &:hover {
    background: color-mix(in srgb, var(--color-highlight) 20%, transparent);
  }
`

const Td = styled.td`
  padding: 0.5rem 0;
  padding-right: 1rem;
`

const TdLast = styled.td`
  padding: 0.5rem 0;
`

const TdBold = styled.td`
  padding: 0.5rem 0;
  padding-right: 1rem;
  font-weight: 500;
`

const TdNum = styled.td`
  padding: 0.5rem 0;
  padding-right: 1rem;
  font-variant-numeric: tabular-nums;
  color: var(--color-muted-foreground);
`

const TdMono = styled.td`
  padding: 0.5rem 0;
  padding-right: 1rem;
  font-family: var(--font-mono);
  font-variant-numeric: tabular-nums;
  color: var(--color-muted-foreground);
`

const TdMuted = styled.td`
  padding: 0.5rem 0;
  color: var(--color-muted-foreground);
`

const EmptyText = styled.p`
  font-size: 0.8125rem;
  color: var(--color-muted-foreground);
`

const SkeletonStack = styled.div`
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
`

export function RadioAdminPage() {
  const [countryFilter, setCountryFilter] = useState<string>('')
  const [search, setSearch] = useState('')
  const { data: stations, isLoading } = useRadioStations({
    country: countryFilter || undefined,
    q: search || undefined,
  })
  const { data: countries } = useRadioCountries()

  const stats = useMemo(() => {
    if (!stations) return null
    return {
      total: stations.length,
      withLogo: stations.filter((s) => s.logo).length,
      countries: new Set(stations.map((s) => s.country)).size,
    }
  }, [stations])

  return (
    <Container>
      <AdminPageHeader
        title="Radio"
        subtitle="Station catalog and source management"
        icon={Radio}
      />

      {/* Stats bar */}
      <StatsBar>
        {stats ? (
          <>
            <StatDisplay label="Stations" value={stats.total.toLocaleString()} />
            <StatDisplay label="Countries" value={stats.countries.toLocaleString()} />
            <StatDisplay label="With Logo" value={stats.withLogo.toLocaleString()} />
          </>
        ) : isLoading ? (
          <>
            <StatDisplaySkeleton />
            <StatDisplaySkeleton />
            <StatDisplaySkeleton />
          </>
        ) : null}
      </StatsBar>

      {/* Filters */}
      <FilterRow>
        <Input
          type="text"
          placeholder="Search stations..."
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          style={{ height: '2rem', width: '14rem' }}
        />
        <Select value={countryFilter || '_all'} onValueChange={(v) => setCountryFilter(v === '_all' ? '' : v)}>
          <SelectTrigger style={{ height: '2rem', width: '11rem' }}>
            <SelectValue placeholder="All countries" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="_all">All countries</SelectItem>
            {countries?.map((c) => (
              <SelectItem key={c.code} value={c.code}>
                {c.name}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </FilterRow>

      {/* Station table */}
      {isLoading ? (
        <SkeletonStack>
          {Array.from({ length: 8 }).map((_, i) => (
            <Skeleton key={i} style={{ height: '2.25rem', width: '100%' }} />
          ))}
        </SkeletonStack>
      ) : stations && stations.length > 0 ? (
        <TableWrapper>
          <StyledTable>
            <thead>
              <HeadRow>
                <Th>Name</Th>
                <Th>Country</Th>
                <Th>Genre</Th>
                <Th>Bitrate</Th>
                <Th>Streams</Th>
                <ThLast>Checked</ThLast>
              </HeadRow>
            </thead>
            <tbody>
              {stations.map((station) => (
                <BodyRow key={station.id}>
                  <TdBold>{station.name}</TdBold>
                  <TdNum>{station.country}</TdNum>
                  <TdMuted>
                    {station.genres.slice(0, 2).join(', ') || '—'}
                  </TdMuted>
                  <TdMono>
                    {station.streams[0]?.bitrate
                      ? `${station.streams[0].bitrate}k`
                      : '—'}
                  </TdMono>
                  <TdNum>{station.streams.length}</TdNum>
                  <TdLast style={{ color: 'var(--color-muted-foreground)' }}>
                    {station.lastCheckedAt
                      ? formatRelativeTime(station.lastCheckedAt)
                      : '—'}
                  </TdLast>
                </BodyRow>
              ))}
            </tbody>
          </StyledTable>
        </TableWrapper>
      ) : (
        <EmptyText>
          {search || countryFilter ? 'No stations match filters.' : 'No radio stations configured.'}
        </EmptyText>
      )}
    </Container>
  )
}
