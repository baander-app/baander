import { Box, Button, Container, Heading } from '@radix-ui/themes';
import { ReactNode, useEffect, useState } from 'react';
import { useAppDispatch } from '@/store/hooks.ts';
import { createNotification } from '@/store/notifications/notifications-slice.ts';
import { useLibrariesIndex } from '@/libs/api-client/gen/endpoints/library/library.ts';
import { useJobLibraryScan } from '@/libs/api-client/gen/endpoints/job/job.ts';

export function LibrariesList() {
  const { data } = useLibrariesIndex();
  const [rows, setRows] = useState<ReactNode[]>([]);
  const dispatch = useAppDispatch();

  const mutation = useJobLibraryScan({
    mutation: {
      onSuccess: (data) => {
        dispatch(createNotification({
          type: 'info',
          title: 'Library scan',
          message: data.message,
          toast: true,
        }));
      },
    },
  });

  const startScanJob = (slug: string) => {
    mutation.mutate({ slug });
  };

  useEffect(() => {
    if (data?.data) {
      const items = data.data.map(x => (
        <tr key={x.slug}>
          <td>{x.name} ({x.type})</td>
          <td>{x.path}</td>
          <td>{x.lastScan}</td>
          <td>{x.createdAt}</td>
          <td>{x.updatedAt}</td>
          <td>
            <Box>
              <Button
                onClick={() => startScanJob(x.slug)}
              >Scan</Button>
            </Box>
          </td>
        </tr>
      ));

      setRows(items);
    }
  }, [data?.data]);

  return (
    <Container mt="3">
      <Heading>Libraries - list</Heading>

      <Box mt="4">
        <table>
          <thead>
          <tr>
            <th>Name</th>
            <th>Path</th>
            <th>Last scan</th>
            <th>Created</th>
            <th>Updated</th>
            <th>Actions</th>
          </tr>
          </thead>
          <tbody>{rows}</tbody>
        </table>
      </Box>
    </Container>
  );
}