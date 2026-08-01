import styled from 'styled-components'
import { useLyricsCoverage, useLyricsSyncStatus, useBulkFetchLyrics } from '../hooks/use-lyrics-admin'
import { useAuthStore } from '@/features/auth/stores/auth-store'
import { Music, RefreshCw, CheckCircle2, XCircle } from 'lucide-react'
import { Button } from '@/shared/components/ui/button'

const Container = styled.div`
  display: flex;
  flex-direction: column;
  gap: 1.25rem;
  padding: 1.5rem;
`

const Grid = styled.div`
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 1rem;

  @media (max-width: 1024px) {
    grid-template-columns: 1fr;
  }
`

const Card = styled.div`
  border-radius: var(--radius-md);
  border: 1px solid var(--color-border);
  background: var(--color-card);
`

const CardHeader = styled.h3`
  border-bottom: 1px solid var(--color-border);
  padding: 0.75rem 1rem;
  font-size: 0.875rem;
  font-weight: 500;
`

const CardBody = styled.div`
  padding: 1rem;
`

const ContentStack = styled.div`
  display: flex;
  flex-direction: column;
  gap: 1rem;
`

const StatRow = styled.div`
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0.5rem 0;

  &:last-child {
    border-bottom: none;
  }
`

const StatLabel = styled.span`
  color: var(--color-muted-foreground);
  font-size: 0.875rem;
`

const StatValue = styled.span<{ $muted?: boolean }>`
  font-family: var(--font-mono);
  font-size: 0.875rem;
  ${({ $muted }) => $muted && 'color: var(--color-muted-foreground);'}
`

const StatIcon = styled.span`
  display: flex;
  align-items: center;
  gap: 0.375rem;
`

const Highlight = styled.div`
  display: flex;
  align-items: center;
  gap: 0.75rem;
`

const IconBox = styled.div`
  display: flex;
  height: 2.5rem;
  width: 2.5rem;
  align-items: center;
  justify-content: center;
  border-radius: var(--radius-lg);
  background: color-mix(in srgb, var(--color-primary) 10%, transparent);
`

const BigValue = styled.div`
  font-size: 1.5rem;
  font-weight: 600;
  font-variant-numeric: tabular-nums;
`

const SubLabel = styled.div`
  font-size: 0.6875rem;
  color: var(--color-muted-foreground);
  text-transform: uppercase;
  letter-spacing: 0.05em;
`

const BarTrack = styled.div`
  height: 0.5rem;
  width: 100%;
  border-radius: 9999px;
  background: var(--color-muted);
`

const BarFill = styled.div<{ $percentage: number; $color: string }>`
  height: 0.5rem;
  border-radius: 9999px;
  transition: all 200ms ease-out;
  background: ${({ $color }) => $color};
  width: ${({ $percentage }) => `${Math.max(0, Math.min(100, $percentage))}%`};
`

const SectionLabel = styled.div`
  margin-bottom: 0.5rem;
  font-size: 0.6875rem;
  font-weight: 500;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--color-muted-foreground);
`

const RowStack = styled.div`
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
`

const LoadingCard = styled(Card)`
  & > div:first-child {
    animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
  }

  @keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.5; }
  }
`

const LoadingBar = styled.div`
  height: 1rem;
  border-radius: var(--radius-md);
  background: var(--color-muted);
  animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;

  @keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.5; }
  }
`

const ActionArea = styled.div`
  border-top: 1px solid var(--color-border);
  padding-top: 1rem;
`

const FeedbackText = styled.p`
  margin-top: 0.5rem;
  font-size: 0.75rem;
  color: var(--color-muted-foreground);
`

const ErrorText = styled.p`
  margin-top: 0.5rem;
  font-size: 0.75rem;
  color: var(--color-destructive);
`

const EmptyText = styled.p`
  font-size: 0.875rem;
  color: var(--color-muted-foreground);
`

function CoverageBar({ percentage }: { percentage: number }) {
  const clamped = Math.max(0, Math.min(100, percentage))
  const color = clamped >= 80 ? '#10b981' : clamped >= 50 ? '#f59e0b' : '#ef4444'

  return (
    <BarTrack>
      <BarFill $percentage={clamped} $color={color} />
    </BarTrack>
  )
}

function Section({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <Card>
      <CardHeader>{title}</CardHeader>
      <CardBody>{children}</CardBody>
    </Card>
  )
}

function Row({ label, value, muted }: { label: string; value: React.ReactNode; muted?: boolean }) {
  return (
    <StatRow>
      <StatLabel>{label}</StatLabel>
      {muted ? (
        <StatValue $muted>{value}</StatValue>
      ) : (
        <StatValue>{value}</StatValue>
      )}
    </StatRow>
  )
}

export function LyricsAdminPage() {
  const { data: coverage, isLoading: coverageLoading } = useLyricsCoverage()
  const { data: syncStatus, isLoading: syncLoading } = useLyricsSyncStatus()
  const bulkFetch = useBulkFetchLyrics()
  const roles = useAuthStore((s) => s.user?.roles ?? [])
  const isSuperAdmin = roles.includes('ROLE_SUPER_ADMIN')

  if (coverageLoading || syncLoading) {
    return (
      <Container>
        <Grid>
          {Array.from({ length: 2 }).map((_, i) => (
            <LoadingCard key={i}>
              <div style={{ height: '2.5rem', background: 'var(--color-muted)', borderRadius: 'var(--radius-md) var(--radius-md) 0 0' }} />
              <CardBody>
                <div style={{ display: 'flex', flexDirection: 'column', gap: '0.75rem' }}>
                  {Array.from({ length: 4 }).map((_, j) => (
                    <LoadingBar key={j} />
                  ))}
                </div>
              </CardBody>
            </LoadingCard>
          ))}
        </Grid>
      </Container>
    )
  }

  return (
    <Container>
      <Grid>
        {/* Coverage */}
        <Section title="Coverage">
          {coverage ? (
            <ContentStack>
              <Highlight>
                <IconBox>
                  <Music size={18} strokeWidth={1.5} style={{ color: 'var(--color-primary)' }} />
                </IconBox>
                <div>
                  <BigValue>
                    {coverage.coveragePercentage.toFixed(1)}%
                  </BigValue>
                  <SubLabel>
                    Lyrics coverage
                  </SubLabel>
                </div>
              </Highlight>

              <CoverageBar percentage={coverage.coveragePercentage} />

              <RowStack>
                <Row label="Total tracks" value={coverage.totalTracks.toLocaleString()} />
                <Row
                  label="With lyrics"
                  value={
                    <StatIcon>
                      <CheckCircle2 size={12} style={{ color: '#10b981' }} />
                      {coverage.tracksWithLyrics.toLocaleString()}
                    </StatIcon>
                  }
                />
                <Row
                  label="Without lyrics"
                  value={
                    <StatIcon>
                      <XCircle size={12} style={{ color: '#ef4444' }} />
                      {coverage.tracksWithoutLyrics.toLocaleString()}
                    </StatIcon>
                  }
                />
              </RowStack>

              {coverage.bySource.length > 0 && (
                <div style={{ paddingTop: '0.5rem' }}>
                  <SectionLabel>By source</SectionLabel>
                  <RowStack>
                    {coverage.bySource.map((s) => (
                      <Row key={s.source} label={s.source} value={s.count.toLocaleString()} />
                    ))}
                  </RowStack>
                </div>
              )}
            </ContentStack>
          ) : (
            <EmptyText>No coverage data available.</EmptyText>
          )}
        </Section>

        {/* Sync Status + Bulk Fetch */}
        <Section title="Sync Status">
          {syncStatus ? (
            <ContentStack>
              <RowStack>
                <Row
                  label="Last sync"
                  value={
                    syncStatus.lastSyncAt
                      ? new Date(syncStatus.lastSyncAt).toLocaleString()
                      : 'Never'
                  }
                  muted={!syncStatus.lastSyncAt}
                />
                <Row label="Pending jobs" value={syncStatus.pendingJobs} muted={syncStatus.pendingJobs === 0} />
                <Row label="Completed jobs" value={syncStatus.completedJobs} />
                <Row label="Failed jobs" value={syncStatus.failedJobs} muted={syncStatus.failedJobs === 0} />
              </RowStack>

              {isSuperAdmin && (
                <ActionArea>
                  <Button
                    onClick={() => bulkFetch.mutate()}
                    disabled={bulkFetch.isPending}
                    size="sm"
                    variant="outline"
                    style={{ gap: '0.5rem' }}
                  >
                    <RefreshCw size={14} style={{ animation: bulkFetch.isPending ? 'spin 1s linear infinite' : 'none' }} />
                    Fetch Missing Lyrics
                  </Button>
                  {bulkFetch.data && (
                    <FeedbackText>
                      Enqueued {bulkFetch.data.jobsEnqueued} fetch jobs.
                    </FeedbackText>
                  )}
                  {bulkFetch.error && (
                    <ErrorText>
                      Failed to enqueue jobs.
                    </ErrorText>
                  )}
                </ActionArea>
              )}
            </ContentStack>
          ) : (
            <EmptyText>No sync data available.</EmptyText>
          )}
        </Section>
      </Grid>
    </Container>
  )
}
