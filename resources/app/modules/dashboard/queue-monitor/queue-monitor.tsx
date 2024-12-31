import { Box, Container, Title } from '@mantine/core';
import { JobsList } from '@/modules/dashboard/queue-monitor/components/jobs-list.tsx';
import { Metrics } from '@/modules/dashboard/queue-monitor/components/metrics.tsx';


export function QueueMonitor() {

  return (
    <Container fluid>
      <Title>Queue monitor</Title>

      <Title ta="center" size="h2" fz="md">Metrics</Title>
      <Metrics />

      <Box mt="md">
        <JobsList/>
      </Box>
    </Container>
  );
}