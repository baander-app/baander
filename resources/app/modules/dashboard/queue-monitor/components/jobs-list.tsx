import React, { useState } from 'react';
import { useQueueServiceQueueMetricsShowInfinite } from '@/api-client/queries/infiniteQueries.ts';
import { TableVirtuoso } from 'react-virtuoso';
import { Box, Button, ButtonGroup, Flex, Modal, Table, Text } from '@mantine/core';
import { QueueMonitorResource } from '@/api-client/requests';
import { JobStatus } from '@/modules/dashboard/queue-monitor/components/job-status.tsx';
import { TableProps } from '@mantine/core/lib/components/Table/Table';
import { useDisclosure } from '@mantine/hooks';
import dayjs from 'dayjs';

import styles from './jobs-list.module.scss';
import { JobDetails } from '@/modules/dashboard/queue-monitor/components/job-details.tsx';

export interface JobsList extends React.ComponentPropsWithoutRef<'div'> {

}
export function JobsList({...rest}: JobsList) {
  const { data: jobsData, fetchNextPage, hasNextPage } = useQueueServiceQueueMetricsShowInfinite();
  const [openJob, setOpenJob] = useState<QueueMonitorResource>();
  const [showJobDetails, jobDetailHandlers] = useDisclosure(false);

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

  const openModal = (job: QueueMonitorResource) => {
    setOpenJob(job);
    jobDetailHandlers.open();
  }

  const closeModal = () => {
    jobDetailHandlers.close();
    setOpenJob(undefined);
  }

  return (
    <div {...rest}>
      <TableVirtuoso
        className={styles.scrollList}
        data={jobsData?.pages?.flatMap(job => job.data)}
        totalCount={jobsData?.pages[0].total}
        // @ts-ignore
        components={TableComponents}
        useWindowScroll={true}
        endReached={() => {
          hasNextPage && fetchNextPage();
        }}
        fixedHeaderContent={() => (
          <Table.Tr>
            <Table.Td w="5%">Job</Table.Td>
            <Table.Td w="5%">Details</Table.Td>
            <Table.Td w="5%">Progress</Table.Td>
            <Table.Td w="5%">Duration</Table.Td>
            <Table.Td w="5%">Status</Table.Td>
            <Table.Td w="8%">Actions</Table.Td>
          </Table.Tr>
        )}
        itemContent={(_index, data: QueueMonitorResource) => {
          return (
            <React.Fragment key={data.id}>
              <Table.Td>
                <Text>{data.name}</Text>
              </Table.Td>
              <Table.Td>
                <Flex direction="column" gap="sm">
                  <Box>
                    <Text fz="xs"><span className={styles.bold}>Queue</span>: {data.queue}</Text>
                    <Text fz="xs"><span className={styles.bold}>Attempt</span>: {data.attempt}</Text>
                  </Box>
                </Flex>
              </Table.Td>
              <Table.Td>
                {data.progress && (<Text>{data.progress}%</Text>)}
              </Table.Td>
              <Table.Td>
                {getDuration(data.started_at, data.finished_at)}
              </Table.Td>
              <Table.Td>
                <JobStatus status={data.status}/>
              </Table.Td>
              <Table.Td>
                <ButtonGroup>
                  <Button color="gray.2" onClick={() => openModal(data)}>View</Button>
                  <Button color="blue.2">Retry</Button>
                  <Button color="red">Delete</Button>
                </ButtonGroup>
              </Table.Td>
            </React.Fragment>
          );
        }}
      />

      <Modal
        opened={showJobDetails}
        onClose={() => closeModal()}
        fullScreen
        title={openJob && <Text fw="bold">Job details | {openJob.name}</Text>}
      >
        {(showJobDetails && openJob) && (
          <JobDetails job={openJob} />
        )}
      </Modal>
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
  Table: (props: TableProps) => <Table {...props} style={{ borderCollapse: 'separate' }}/>,
  TableHead: Table.Thead,
  TableRow: Table.Tr,
  // @ts-ignore
  TableBody: React.forwardRef((props, ref) => <Table.Tbody {...props} ref={ref}/>),
}