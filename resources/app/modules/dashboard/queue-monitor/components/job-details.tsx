import { QueueMonitorResource } from '@/api-client/requests';
import { Box, Table, TableData, Text } from '@mantine/core';
import { JobStatus } from '@/modules/dashboard/queue-monitor/components/job-status.tsx';

import { ErrorBoundary } from 'react-error-boundary';
import ReactJson from '@microlink/react-json-view';

interface ExceptionDetailsProps {
  job: QueueMonitorResource;
}

const ExceptionDetails = ({ job }: ExceptionDetailsProps) => {

  return (
    <Box>
      <Text size="md">Exception:</Text>
      <Text fw="bold" c="red.8">{job.exception_class}</Text>

      {job.exception && (
        <ReactJson
          src={job.exception}
          indentWidth={2}
        />
      )}
    </Box>
  );
};

export interface JobDetailsProps {
  job: QueueMonitorResource;
}

export function JobDetails({ job }: JobDetailsProps) {
  const tableData: TableData = {
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
    <>
      <Table data={tableData}/>

      <hr/>


      <ErrorBoundary fallback={<div>Something went wrong</div>}>
        {job.exception && (
          <ExceptionDetails job={job}/>
        )}
      </ErrorBoundary>
    </>
  );
}