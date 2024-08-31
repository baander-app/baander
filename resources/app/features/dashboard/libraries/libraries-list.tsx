import { useLibraryServiceLibrariesIndex } from '@/api-client/queries';
import { Box, Button, Container, Table, Title } from '@mantine/core';
import { ReactNode, useEffect, useState } from 'react';
import { JobService } from '@/api-client/requests';
import { notifications } from '@mantine/notifications';

export function LibrariesList() {
  const {data} = useLibraryServiceLibrariesIndex();
  const [rows, setRows] = useState<ReactNode[]>([]);

  const startScanJob = (slug: string) => {
    JobService.jobLibraryScan({slug})
      .then(res => {
        if (typeof res !== 'string') {
          notifications.show({
            title: 'Library scan',
            message: res.message,
          });
        }
      });
  };

  useEffect(() => {
    if (data?.data) {
      const items = data.data.map(x => (
        <Table.Tr key={x.slug}>
          <Table.Td>{x.name}</Table.Td>
          <Table.Td>{x.path}</Table.Td>
          <Table.Td>{x.lastScan}</Table.Td>
          <Table.Td>{x.createdAt}</Table.Td>
          <Table.Td>{x.updatedAt}</Table.Td>
          <Table.Td>
            <Box>
              <Button
                onClick={() => startScanJob(x.slug)}
              >Scan</Button>
            </Box>
          </Table.Td>
        </Table.Tr>
      ));

      setRows(items);
    }
  }, [data?.data]);

  return (
    <Container fluid>
      <Title>Libraries - list</Title>

      <Table>
        <Table.Thead>
          <Table.Tr>
            <Table.Th>Name</Table.Th>
            <Table.Th>Path</Table.Th>
            <Table.Th>Last scan</Table.Th>
            <Table.Th>Created</Table.Th>
            <Table.Th>Updated</Table.Th>
            <Table.Th>Actions</Table.Th>
          </Table.Tr>
        </Table.Thead>
        <Table.Tbody>{rows}</Table.Tbody>
      </Table>
    </Container>
  );
}