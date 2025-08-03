import React, { useState } from 'react';
import { TableVirtuoso } from 'react-virtuoso';
import { Button, Dialog, Flex, Text } from '@radix-ui/themes';
import { JobStatus } from '@/modules/dashboard/queue-monitor/components/job-status.tsx';
import dayjs from 'dayjs';

import styles from './jobs-list.module.scss';
import { JobDetails } from '@/modules/dashboard/queue-monitor/components/job-details.tsx';
import { useQueueMetricsShowInfinite } from '@/libs/api-client/gen/endpoints/queue/queue.ts';
import { QueueMonitorResource } from '@/libs/api-client/gen/models';

export interface JobsList extends React.ComponentPropsWithoutRef<'div'> {

}

export function JobsList({ ...rest }: JobsList) {
  const { data: jobsData, fetchNextPage, hasNextPage } = useQueueMetricsShowInfinite();
  const [openJob, setOpenJob] = useState<QueueMonitorResource>();

  const getDuration = (start: string | null, end: string | null) => {
    if (start && end) {
      const startDate = dayjs(start);
      const endDate = dayjs(end);

      const diff = endDate.diff(startDate);
      const duration = dayjs.duration(diff);

      return duration.asSeconds() + 's';
    }

    return '';
  };

  return (
    <div {...rest}>
      <Dialog.Root>
        <TableVirtuoso
          className={styles.scrollList}
          data={jobsData?.pages?.flatMap(job => job.data)}
          totalCount={jobsData?.pages[0]?.meta?.total}
          // @ts-ignore
          components={TableComponents}
          useWindowScroll={true}
          endReached={() => {
            hasNextPage && fetchNextPage();
          }}
          fixedHeaderContent={() => (
            <tr>
              <td width="5%">Job</td>
              <td width="5%">Details</td>
              <td width="5%">Progress</td>
              <td width="5%">Duration</td>
              <td width="5%">Status</td>
              <td width="8%">Actions</td>
            </tr>
          )}
          itemContent={(_index, data: QueueMonitorResource) => {
            return (
              <React.Fragment key={data.id}>
                <td>
                  <Text>{data.name}</Text>
                </td>
                <td>
                  <Flex direction="column" gap="sm">
                    <Text size="2"><span className={styles.bold}>Queue</span>: {data.queue}</Text>
                    <Text size="2"><span className={styles.bold}>Attempt</span>: {data.attempt}</Text>
                  </Flex>
                </td>
                <td>
                  {data.progress && (<Text>{data.progress}%</Text>)}
                </td>
                <td>
                  {getDuration(data.started_at, data.finished_at)}
                </td>
                <td>
                  <JobStatus status={data.status}/>
                </td>
                <td>
                  <Flex gap="2" align="center">
                    <Dialog.Trigger>
                      <Button color="gray" onClick={() => setOpenJob(data)}>View</Button>
                    </Dialog.Trigger>
                    <Button color="blue">Retry</Button>
                    <Button color="red">Delete</Button>
                  </Flex>
                </td>
              </React.Fragment>
            );
          }}
        />

        <Dialog.Content>
          <Dialog.Title>{openJob?.name ?? 'Job'}</Dialog.Title>
          <Dialog.Description>See the details about the job</Dialog.Description>

          {openJob && <JobDetails job={openJob}/>}

          <Flex gap="3" mt="4" justify="end">
            <Dialog.Close>
              <Button onClick={() => setOpenJob(undefined)}>Close</Button>
            </Dialog.Close>
          </Flex>
        </Dialog.Content>
      </Dialog.Root>
    </div>
  );
}

interface ScrollerProps {
  style: React.CSSProperties;

  [key: string]: any;
}

const Scroller = React.forwardRef<HTMLDivElement, ScrollerProps>(({ style, ...props }, ref) => {
  // an alternative option to assign the ref is
  // <div ref={(r) => ref.current = r}>
  return <div className={styles.scrollbar} style={{ ...style }} ref={ref} {...props} />;
});
const TableComponents = {
  Scroller,
};
