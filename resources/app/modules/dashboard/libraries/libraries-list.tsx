import { Box, Button, Container, Heading } from '@radix-ui/themes';
import { ReactNode, useEffect, useState } from 'react';
import { JobService } from '@/api-client/requests';
import { useLibraryServiceGetApiLibraries } from '@/api-client/queries';
import { useAppDispatch } from '@/store/hooks.ts';
import { createNotification } from '@/store/notifications/notifications-slice.ts';

export function LibrariesList() {
  const {data} = useLibraryServiceGetApiLibraries();
  const [rows, setRows] = useState<ReactNode[]>([]);
  const dispatch = useAppDispatch();

  const startScanJob = (slug: string) => {
    JobService.postApiJobsScanLibraryBySlug({slug})
      .then(res => {
        if (typeof res !== 'string') {
          dispatch(createNotification({
            type: 'info',
            title: 'Library scan',
            message: res.message,
            toast: true,
          }));
        }
      });
  };

  useEffect(() => {
    if (data?.data) {
      const items = data.data.map(x => (
        <tr key={x.slug}>
          <td>{x.name}</td>
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