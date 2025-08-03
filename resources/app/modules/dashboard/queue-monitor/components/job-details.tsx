import { Box, Container, Flex, Separator, Text } from '@radix-ui/themes';
import { Table } from '@radix-ui/themes';
import { JobStatus } from '@/modules/dashboard/queue-monitor/components/job-status.tsx';
import { ErrorBoundary } from 'react-error-boundary';
import ReactJson from '@microlink/react-json-view';
import { QueueMonitorResource } from '@/libs/api-client/gen/models';

interface ExceptionDetailsProps {
  job: QueueMonitorResource;
}

const ExceptionDetails = ({ job }: ExceptionDetailsProps) => {
  return (
    <Box p="2">
      <Flex direction="column" gap="2">
        <Text size="2" weight="bold">Exception:</Text>
        <Text size="3" color="red" weight="bold">{job.exception_class}</Text>

        {job.exception && (
          <Box mt="2">
            <ReactJson
              src={job.exception}
              indentWidth={2}
            />
          </Box>
        )}
      </Flex>
    </Box>
  );
};

export interface JobDetailsProps {
  job: QueueMonitorResource;
}

export function JobDetails({ job }: JobDetailsProps) {
  const tableData = {
    head: ['Property', 'Value'],
    body: [
      ['id', job.id],
      ['job_id', job.job_id],
      ['job_uuid', job.job_uuid],
      ['name', job.name],
      ['queue', job.queue],
      ['started_at', job.started_at],
      ['started_at_exact', job.started_at_exact],
      ['finished_at', job.finished_at],
      ['finished_at_exact', job.finished_at_exact],
      ['attempt', job.attempt],
      ['progress', job.progress],
      ['status', <JobStatus status={job.status}/>],
      ['retried', job.retried],
      ['queued_at', job.queued_at],
    ],
  };

  return (
    <Container>
      <Table.Root>
        <Table.Header>
          <Table.Row>
            <Table.ColumnHeaderCell>{tableData.head[0]}</Table.ColumnHeaderCell>
            <Table.ColumnHeaderCell>{tableData.head[1]}</Table.ColumnHeaderCell>
          </Table.Row>
        </Table.Header>
        <Table.Body>
          {tableData.body.map((row, index) => (
            <Table.Row key={index}>
              <Table.RowHeaderCell>{row[0]}</Table.RowHeaderCell>
              <Table.Cell>{row[1]}</Table.Cell>
            </Table.Row>
          ))}
        </Table.Body>
      </Table.Root>

      <Separator my="4" size="4" />

      <ErrorBoundary fallback={<Box p="2"><Text color="red">Something went wrong</Text></Box>}>
        {job.exception && (
          <ExceptionDetails job={job}/>
        )}
      </ErrorBoundary>
    </Container>
  );
}
