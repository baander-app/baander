import styled from 'styled-components'
import { Button } from '@/shared/components/ui/button'
import { useServerStats } from '../hooks/use-server-stats'
import { LiveHealthBar } from '../components/dashboard/LiveHealthBar'
import { CoroutinesSection } from '../components/diagnostics/CoroutinesSection'
import { DeveloperToolsCard } from '../components/diagnostics/DeveloperToolsCard'
import { RedisCard } from '../components/diagnostics/RedisCard'
import { SectionSkeleton } from '../components/diagnostics/SectionStates'
import { SpansSection } from '../components/diagnostics/SpansSection'
import { WorkerFailureNotice } from '../components/diagnostics/WorkerFailureNotice'
import { WorkerPoolsSection } from '../components/diagnostics/WorkerPoolsSection'
import { WorkersTable } from '../components/diagnostics/WorkersTable'

const Container = styled.div`
  display: flex;
  flex-direction: column;
  gap: var(--space-xl);
  padding: var(--space-lg);
`

const Section = styled.div`
  display: flex;
  flex-direction: column;
  gap: var(--space-md);
`

const FlexBetween = styled.div`
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-md);
`

const Grid = styled.div`
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: var(--space-md);

  @media (max-width: 1024px) {
    grid-template-columns: 1fr;
  }
`

const SectionTitle = styled.h2`
  font-size: 0.6875rem;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--color-muted-foreground);
  font-weight: 500;
`

const MutedSmall = styled.span`
  font-size: 0.75rem;
  color: var(--color-muted-foreground);
`

const ErrorText = styled.p`
  font-size: 0.875rem;
  color: var(--color-destructive);
`

export function ServerDiagnosticsPage() {
  const { data: stats, isLoading, error, refetch, dataUpdatedAt } = useServerStats()

  if (isLoading) {
    return (
      <Container aria-busy="true">
        <SectionSkeleton rows={6} />
      </Container>
    )
  }

  if (error || !stats) {
    return (
      <Container>
        <FlexBetween>
          <ErrorText>Failed to load server diagnostics.</ErrorText>
          <Button variant="ghost" size="sm" onClick={() => refetch()}>
            Retry
          </Button>
        </FlexBetween>
      </Container>
    )
  }

  return (
    <Container>
      <FlexBetween>
        <LiveHealthBar />
        <MutedSmall>Auto-refreshes every 5s</MutedSmall>
      </FlexBetween>

      <Grid>
        <DeveloperToolsCard />
      </Grid>

      <Section>
        <SectionTitle>Workers</SectionTitle>
        <WorkerFailureNotice
          title="Worker statistics incomplete"
          missingWorkers={stats.missing_workers}
          workerErrors={stats.worker_errors}
        />
        <WorkersTable workers={stats.workers} />
        <Grid>
          <RedisCard redis={stats.redis} />
        </Grid>
      </Section>

      <Section>
        <SectionTitle>Runtime</SectionTitle>
        <Grid>
          <CoroutinesSection />
          <WorkerPoolsSection />
        </Grid>
      </Section>

      <SpansSection />

      {dataUpdatedAt > 0 && (
        <MutedSmall>
          Last updated: {new Date(dataUpdatedAt).toLocaleTimeString()}
        </MutedSmall>
      )}
    </Container>
  )
}
