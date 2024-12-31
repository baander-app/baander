import { QueueMonitorResource } from '@/api-client/requests';
import { Accordion, Box, Table, TableData, Text } from '@mantine/core';
import { JobStatus } from '@/features/dashboard/queue-monitor/components/job-status.tsx';

import styles from './job-details.module.scss';

interface ExceptionDetailsProps {
  job: QueueMonitorResource;
}

function ExceptionDetails({ job }: ExceptionDetailsProps) {

  const accordionItems = [
    { title: 'Message', value: job.exception_message },
    { title: 'Detailed', value: job.exception },
  ].map((item) => (
    <Accordion.Item key={item.title} value={item.title}>
      <Accordion.Control>{item.title}</Accordion.Control>
      <Accordion.Panel>
        <pre className={styles.exceptionText}>{item.value}</pre>
      </Accordion.Panel>
    </Accordion.Item>
  ));

  return (
    <Box>
      <Text size="md">Exception:</Text>
      <Text fw="bold" c="red.8">{job.exception_class}</Text>

      <Accordion>
        {accordionItems}
      </Accordion>
    </Box>
  );
}

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

      {job.exception && (
        <ExceptionDetails job={job}/>
      )}
    </>
  );
}