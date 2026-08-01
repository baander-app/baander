import styled from 'styled-components'
import { useState } from 'react'
import {
  useActivitySummary,
  useTopTracks,
  useTopArtists,
  useEngagement,
} from '../hooks/use-activity-admin'
import { Skeleton } from '@/shared/components/ui/skeleton'
import { StatDisplay, StatDisplaySkeleton } from '@/shared/components/stat-display'
import { formatDurationHuman } from '@/shared/utils/format-human'

const Container = styled.div`
  display: flex;
  flex-direction: column;
  gap: 1.5rem;
  padding: 1.5rem;
`

const FilterBar = styled.div`
  display: flex;
  justify-content: flex-end;
`

const FilterGroup = styled.div`
  display: flex;
  align-items: center;
  gap: 0.5rem;
`

const DateInput = styled.input`
  height: 2rem;
  border-radius: var(--radius-md);
  border: 1px solid var(--color-border);
  background: transparent;
  padding: 0 0.75rem;
  font-size: 0.8125rem;
  color: var(--color-foreground);

  &:focus {
    outline: none;
    box-shadow: 0 0 0 1px var(--color-primary);
  }
`

const DateSeparator = styled.span`
  font-size: 0.8125rem;
  color: var(--color-muted-foreground);
`

const SummaryGrid = styled.div`
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 1.5rem;

  @media (min-width: 1024px) {
    grid-template-columns: repeat(4, 1fr);
  }
`

const EngagementGrid = styled.div`
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 1.5rem;
`

const TablesGrid = styled.div`
  display: grid;
  grid-template-columns: 1fr;
  gap: 1.5rem;

  @media (min-width: 1024px) {
    grid-template-columns: 1fr 1fr;
  }
`

const SectionTitle = styled.h2`
  margin-bottom: 0.75rem;
  font-size: 0.6875rem;
  font-weight: 500;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--color-muted-foreground);
`

const SkeletonStack = styled.div`
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
`

const StyledTable = styled.table`
  width: 100%;
  font-size: 0.8125rem;
`

const TableHeadRow = styled.tr`
  border-bottom: 1px solid var(--color-border);
  text-align: left;
  font-size: 0.6875rem;
  font-weight: 500;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--color-muted-foreground);
`

const ThNum = styled.th`
  width: 1.5rem;
  padding-bottom: 0.5rem;
`

const Th = styled.th`
  padding-bottom: 0.5rem;
  padding-right: 0.75rem;
`

const ThRight = styled.th`
  padding-bottom: 0.5rem;
  text-align: right;
`

const TableRow = styled.tr`
  border-bottom: 1px solid color-mix(in srgb, var(--color-border) 50%, transparent);
  transition: background-color var(--duration-hover) ease-out;

  &:hover {
    background: color-mix(in srgb, var(--color-highlight) 20%, transparent);
  }
`

const TdNum = styled.td`
  padding: 0.375rem 0;
  font-variant-numeric: tabular-nums;
  color: var(--color-muted-foreground);
`

const TdBold = styled.td`
  padding: 0.375rem 0;
  padding-right: 0.75rem;
  font-weight: 500;
`

const TdMuted = styled.td`
  padding: 0.375rem 0;
  padding-right: 0.75rem;
  color: var(--color-muted-foreground);
`

const TdRight = styled.td`
  padding: 0.375rem 0;
  text-align: right;
  font-variant-numeric: tabular-nums;
`

const EmptyText = styled.p`
  font-size: 0.8125rem;
  color: var(--color-muted-foreground);
`

export function ActivityPage() {
  const [from, setFrom] = useState(() => {
    const d = new Date()
    d.setDate(d.getDate() - 30)
    return d.toISOString().slice(0, 10)
  })
  const [to, setTo] = useState(() => new Date().toISOString().slice(0, 10))

  const params = { from, to }
  const { data: summary, isLoading: summaryLoading } = useActivitySummary(params)
  const { data: tracks, isLoading: tracksLoading } = useTopTracks(params)
  const { data: artists, isLoading: artistsLoading } = useTopArtists(params)
  const { data: engagement, isLoading: engagementLoading } = useEngagement(params)

  return (
    <Container>
      <FilterBar>
        <FilterGroup>
          <DateInput
            type="date"
            value={from}
            onChange={(e) => setFrom(e.target.value)}
          />
          <DateSeparator>to</DateSeparator>
          <DateInput
            type="date"
            value={to}
            onChange={(e) => setTo(e.target.value)}
          />
        </FilterGroup>
      </FilterBar>

      {/* Summary stats */}
      <SummaryGrid>
        {summaryLoading ? (
          Array.from({ length: 4 }).map((_, i) => (
            <StatDisplaySkeleton key={i} />
          ))
        ) : summary ? (
          <>
            <StatDisplay label="Total Plays" value={summary.total_plays.toLocaleString()} />
            <StatDisplay label="Unique Tracks" value={summary.unique_tracks.toLocaleString()} />
            <StatDisplay label="Unique Artists" value={summary.unique_artists.toLocaleString()} />
            <StatDisplay
              label="Listening Time"
              value={formatDurationHuman(summary.total_listening_time)}
            />
          </>
        ) : null}
      </SummaryGrid>

      {/* Engagement row */}
      <EngagementGrid>
        {engagementLoading ? (
          Array.from({ length: 3 }).map((_, i) => <StatDisplaySkeleton key={i} />)
        ) : engagement ? (
          <>
            <StatDisplay label="Active Users" value={engagement.active_users.toLocaleString()} />
            <StatDisplay
              label="Avg Plays/User"
              value={engagement.avg_plays_per_user.toFixed(1)}
            />
            <StatDisplay
              label="Avg Session"
              value={formatDurationHuman(Math.round(engagement.avg_session_length))}
            />
          </>
        ) : null}
      </EngagementGrid>

      {/* Tables */}
      <TablesGrid>
        {/* Top Tracks */}
        <div>
          <SectionTitle>Top Tracks</SectionTitle>
          {tracksLoading ? (
            <SkeletonStack>
              {Array.from({ length: 5 }).map((_, i) => (
                <Skeleton key={i} style={{ height: '2rem', width: '100%' }} />
              ))}
            </SkeletonStack>
          ) : tracks && tracks.length > 0 ? (
            <StyledTable>
              <thead>
                <TableHeadRow>
                  <ThNum>#</ThNum>
                  <Th>Track</Th>
                  <Th>Artist</Th>
                  <ThRight>Plays</ThRight>
                </TableHeadRow>
              </thead>
              <tbody>
                {tracks.map((t, i) => (
                  <TableRow key={i}>
                    <TdNum>{i + 1}</TdNum>
                    <TdBold>{t.track_name}</TdBold>
                    <TdMuted>{t.artist_name ?? '—'}</TdMuted>
                    <TdRight>{t.play_count.toLocaleString()}</TdRight>
                  </TableRow>
                ))}
              </tbody>
            </StyledTable>
          ) : (
            <EmptyText>No play data in this period.</EmptyText>
          )}
        </div>

        {/* Top Artists */}
        <div>
          <SectionTitle>Top Artists</SectionTitle>
          {artistsLoading ? (
            <SkeletonStack>
              {Array.from({ length: 5 }).map((_, i) => (
                <Skeleton key={i} style={{ height: '2rem', width: '100%' }} />
              ))}
            </SkeletonStack>
          ) : artists && artists.length > 0 ? (
            <StyledTable>
              <thead>
                <TableHeadRow>
                  <ThNum>#</ThNum>
                  <Th>Artist</Th>
                  <ThRight>Plays</ThRight>
                </TableHeadRow>
              </thead>
              <tbody>
                {artists.map((a, i) => (
                  <TableRow key={i}>
                    <TdNum>{i + 1}</TdNum>
                    <TdBold>{a.artist_name}</TdBold>
                    <TdRight>{a.play_count.toLocaleString()}</TdRight>
                  </TableRow>
                ))}
              </tbody>
            </StyledTable>
          ) : (
            <EmptyText>No play data in this period.</EmptyText>
          )}
        </div>
      </TablesGrid>
    </Container>
  )
}
