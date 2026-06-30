import styled from 'styled-components'
import { useState } from 'react'
import { StatusOverview } from '../components/monitor/StatusOverview'
import { JobTable } from '../components/monitor/JobTable'
import { JobDetailPanel } from '../components/monitor/JobDetailPanel'
import { AnalyticsSection } from '../components/monitor/AnalyticsSection'
import { TransportHealth } from '../components/monitor/TransportHealth'

const Container = styled.div`
  display: flex;
  flex-direction: column;
  gap: 1.25rem;
  padding: 1.5rem;
`

const Grid = styled.div`
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 1.5rem;

  @media (max-width: 1024px) {
    grid-template-columns: 1fr;
  }
`

export function JobMonitorPage() {
  const [selectedJobId, setSelectedJobId] = useState<string | null>(null)

  return (
    <Container>
      <StatusOverview />

      <JobTable onJobSelect={setSelectedJobId} />

      <Grid>
        <AnalyticsSection />
        <TransportHealth />
      </Grid>

      <JobDetailPanel
        jobId={selectedJobId}
        onClose={() => setSelectedJobId(null)}
      />
    </Container>
  )
}
