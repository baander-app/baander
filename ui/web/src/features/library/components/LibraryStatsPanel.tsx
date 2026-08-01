import styled from 'styled-components'
import type { LibraryStats } from '../api/library-api'
import { formatFileSize, formatDuration } from '../utils/format'

interface LibraryStatsPanelProps {
  stats: LibraryStats | undefined
  isLoading: boolean
}

const StatsGrid = styled.div`
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 0.75rem;

  @media (min-width: 640px) {
    grid-template-columns: repeat(3, 1fr);
  }

  @media (min-width: 1024px) {
    grid-template-columns: repeat(6, 1fr);
  }
`

const StatCard = styled.div`
  display: flex;
  align-items: center;
  gap: 0.75rem;
  border-radius: var(--radius-md);
  border: 1px solid var(--color-border);
  background-color: var(--color-card);
  padding: 0.625rem 0.75rem;

  & > div {
    p:first-child {
      font-size: 0.75rem;
      color: var(--color-muted-foreground);
    }
    p:last-child {
      font-size: 0.875rem;
      font-weight: 500;
    }
  }
`

const SkeletonCard = styled.div`
  height: 4rem;
  animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
  border-radius: var(--radius-md);
  border: 1px solid var(--color-border);
  background-color: var(--color-muted);

  @keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.5; }
  }
`

const EmptyText = styled.p`
  font-size: 0.875rem;
  color: var(--color-muted-foreground);
`

function StatItem({ label, value }: { label: string; value: string | number }) {
  return (
    <StatCard>
      <div>
        <p>{label}</p>
        <p>{value}</p>
      </div>
    </StatCard>
  )
}

export function LibraryStatsPanel({ stats, isLoading }: LibraryStatsPanelProps) {
  if (isLoading) {
    return (
      <StatsGrid>
        {Array.from({ length: 6 }).map((_, i) => (
          <SkeletonCard key={i} />
        ))}
      </StatsGrid>
    )
  }

  if (!stats) {
    return (
      <EmptyText>No stats available yet. Run a scan first.</EmptyText>
    )
  }

  return (
    <StatsGrid>
      <StatItem label="Songs" value={stats.songs.toLocaleString()} />
      <StatItem label="Albums" value={stats.albums.toLocaleString()} />
      <StatItem label="Artists" value={stats.artists.toLocaleString()} />
      <StatItem label="Genres" value={stats.genres.toLocaleString()} />
      <StatItem label="Total Size" value={formatFileSize(stats.totalSize)} />
      <StatItem label="Duration" value={formatDuration(stats.totalDuration)} />
    </StatsGrid>
  )
}
