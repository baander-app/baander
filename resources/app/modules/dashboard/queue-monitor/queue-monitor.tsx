import { Box, Container, Heading } from '@radix-ui/themes';
import { JobsList } from '@/app/modules/dashboard/queue-monitor/components/jobs-list.tsx';
import { Metrics } from '@/app/modules/dashboard/queue-monitor/components/metrics.tsx';


export function QueueMonitor() {

  return (
    <Container>
      <Heading mt="3">Queue monitor</Heading>

      <Heading align="center" size="2" weight="medium">Metrics</Heading>

      <Metrics />

      <Box mt="5">
        <JobsList/>
      </Box>
    </Container>
  );
}