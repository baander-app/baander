import styled, { css } from 'styled-components'
import { Link } from 'react-router-dom'
import { useDashboardSummary } from '../hooks/use-dashboard-summary'
import { useTranscodeSessions } from '../hooks/use-transcode-admin'
import { useRecommendationFreshness } from '../hooks/use-recommendations-admin'
import { useMediaStorageStats } from '../hooks/use-media-admin'
import { ActiveOperationsFeed } from '../components/dashboard/ActiveOperationsFeed'
import { AlertHistory } from '../components/dashboard/AlertHistory'
import { TrendSparkline } from '../components/dashboard/TrendSparkline'
import { QuickActions } from '../components/dashboard/QuickActions'
import { StatCard } from '@/shared/components/stat-card'
import { formatBytes } from '@/shared/utils/format-human'
import { Activity, AlertTriangle, HardDrive, ArrowRight } from 'lucide-react'

const Container = styled.div`
  display: flex;
  flex-direction: column;
  gap: 1.5rem;
  padding: 1.5rem;
`

const FlexEnd = styled.div`
  display: flex;
  align-items: center;
  justify-content: space-between;
`

const StatsGrid = styled.div`
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 1rem;

  @media (max-width: 1024px) {
    grid-template-columns: repeat(2, 1fr);
  }

  @media (max-width: 640px) {
    grid-template-columns: 1fr;
  }
`

const SectionTitle = styled.h2`
  font-size: 0.6875rem;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--color-muted-foreground);
  font-weight: 500;
  margin-bottom: 0.75rem;
`

const AnomalyGrid = styled.div`
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 0.75rem;

  @media (max-width: 640px) {
    grid-template-columns: 1fr;
  }
`

const AnomalyCard = styled(Link)<{ $warning?: boolean }>`
  display: block;
  border-radius: var(--radius-lg);
  border: 1px solid var(--color-border);
  padding: 1rem;
  transition: background-color var(--duration-hover) ease-out;

  &:hover {
    background: color-mix(in srgb, var(--color-highlight) 20%, transparent);
  }

  ${({ $warning }) => $warning && css`
    border-color: color-mix(in srgb, #f59e0b 50%, transparent);
    background: color-mix(in srgb, #f59e0b 5%, transparent);

    &:hover {
      background: color-mix(in srgb, #f59e0b 15%, transparent);
    }
  `}

  &:not(${({ $warning }) => $warning ? '&' : '.'}) {
    background-color: var(--color-card);
  }
`

AnomalyCard.defaultProps = { $warning: false }

const CardHeader = styled.div`
  display: flex;
  align-items: center;
  gap: 0.5rem;
`

const CardTitle = styled.span`
  font-size: 0.875rem;
  font-weight: 500;
`

const CardArrow = styled(ArrowRight)`
  margin-left: auto;
  color: transparent;
  transition: color var(--duration-hover) ease-out;
  ${AnomalyCard}:hover & {
    color: var(--color-muted-foreground);
  }
`

const CardValue = styled.div`
  margin-top: 0.5rem;

  span {
    font-size: 1.25rem;
    font-weight: 600;
  }
`

const CardDesc = styled.p`
  margin-top: 0.25rem;
  font-size: 0.75rem;
  color: var(--color-muted-foreground);
`

const TwoColGrid = styled.div`
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 1.5rem;

  @media (max-width: 1024px) {
    grid-template-columns: 1fr;
  }
`

const TrendGrid = styled.div`
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 1.5rem;

  @media (max-width: 640px) {
    grid-template-columns: 1fr;
  }
`

const TrendLabel = styled.span`
  font-size: 0.6875rem;
  color: var(--color-muted-foreground);
`

const TrendChart = styled.div`
  margin-top: 0.25rem;
`

const MutedText = styled.span`
  font-size: 0.6875rem;
  color: var(--color-muted-foreground);
`

export function AdminDashboardPage() {
  const { summary } = useDashboardSummary()
  const { data: failedTranscodes } = useTranscodeSessions('failed')
  const { data: freshness } = useRecommendationFreshness()
  const { data: storageStats } = useMediaStorageStats()

  const failedCount = Array.isArray(failedTranscodes) ? failedTranscodes.filter((s: { status: string }) => s.status === 'failed').length : 0
  const staleRecs = freshness?.avg_age_seconds != null ? freshness.avg_age_seconds > 86400 : false

  return (
    <Container>
      {/* Quick Actions */}
      <FlexEnd>
        <QuickActions />
      </FlexEnd>

      {/* Summary row */}
      <StatsGrid>
        <StatCard label="Uptime" value={summary.uptime} sub={`PID ${summary.pid}`} to="/admin?tab=diagnostics" />
        <StatCard label="Memory" value={summary.memoryUsage} sub={`Peak ${summary.memoryPeak}`} to="/admin?tab=diagnostics" />
        <StatCard label="Redis" value={summary.redisConnected ? 'Connected' : 'Disconnected'} ok={summary.redisConnected} to="/admin?tab=diagnostics" />
        <StatCard label="Jobs" value={String(summary.totalJobs)} sub={`${summary.pendingJobs} pending`} to="/admin?tab=jobs" />
      </StatsGrid>

      {/* Anomaly cards */}
      <section>
        <SectionTitle>Anomalies</SectionTitle>
        <AnomalyGrid>
          <AnomalyCard to="/admin/media?tab=transcode" $warning={failedCount > 0}>
            <CardHeader>
              <Activity size={15} strokeWidth={1.5} style={{ color: failedCount > 0 ? '#f59e0b' : 'var(--color-muted-foreground)' }} />
              <CardTitle>Failed Transcodes</CardTitle>
              <CardArrow size={14} />
            </CardHeader>
            <CardValue><span>{failedCount}</span></CardValue>
            <CardDesc>{failedCount > 0 ? `${failedCount} failed session(s)` : 'No failed sessions'}</CardDesc>
          </AnomalyCard>

          <AnomalyCard to="/admin/analytics?tab=recommendations" $warning={staleRecs}>
            <CardHeader>
              <AlertTriangle size={15} strokeWidth={1.5} style={{ color: staleRecs ? '#f59e0b' : 'var(--color-muted-foreground)' }} />
              <CardTitle>Recommendations</CardTitle>
              <CardArrow size={14} />
            </CardHeader>
            <CardValue><span>{freshness ? (staleRecs ? 'Stale' : 'Fresh') : '—'}</span></CardValue>
            <CardDesc>{freshness?.avg_age_seconds != null ? `Avg age: ${Math.round(freshness.avg_age_seconds / 3600)}h` : 'Loading...'}</CardDesc>
          </AnomalyCard>

          <AnomalyCard to="/admin/media">
            <CardHeader>
              <HardDrive size={15} strokeWidth={1.5} style={{ color: 'var(--color-muted-foreground)' }} />
              <CardTitle>Storage</CardTitle>
              <CardArrow size={14} />
            </CardHeader>
            <CardValue><span>{storageStats?.totalSize != null ? formatBytes(storageStats.totalSize) : '—'}</span></CardValue>
            <CardDesc>{storageStats?.totalImages != null ? `${storageStats.totalImages.toLocaleString()} images` : 'Loading...'}</CardDesc>
          </AnomalyCard>
        </AnomalyGrid>
      </section>

      {/* Live Health Row */}
      <section>
        <SectionTitle>System Health</SectionTitle>
        <MutedText>Health checks unavailable</MutedText>
      </section>

      {/* Active Operations + Alert History */}
      <TwoColGrid>
        <section>
          <SectionTitle>Active Operations</SectionTitle>
          <ActiveOperationsFeed />
        </section>
        <section>
          <SectionTitle>Recent Alerts</SectionTitle>
          <AlertHistory />
        </section>
      </TwoColGrid>

      {/* Trend Sparklines */}
      <section>
        <SectionTitle>Trends (24h)</SectionTitle>
        <TrendGrid>
          <div><TrendLabel>Jobs Completed</TrendLabel><TrendChart><TrendSparkline data={[]} /></TrendChart></div>
          <div><TrendLabel>Memory Usage</TrendLabel><TrendChart><TrendSparkline data={[]} color="#f59e0b" /></TrendChart></div>
          <div><TrendLabel>Play Count</TrendLabel><TrendChart><TrendSparkline data={[]} color="#8b5cf6" /></TrendChart></div>
        </TrendGrid>
      </section>
    </Container>
  )
}
