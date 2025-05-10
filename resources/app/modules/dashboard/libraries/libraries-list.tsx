import { Box, Button, Container, Heading } from '@radix-ui/themes';
import { ReactNode, useEffect, useState } from 'react';
import { JobService } from '@/api-client/requests';
import { useToast } from '@/providers/toast-provider.tsx';
import { useLibraryServiceGetApiLibraries } from '@/api-client/queries';

export function LibrariesList() {
  const {data} = useLibraryServiceGetApiLibraries();
  const [rows, setRows] = useState<ReactNode[]>([]);
  const {showToast} = useToast();

  const startScanJob = (slug: string) => {
    JobService.postApiJobsScanLibraryBySlug({slug})
      .then(res => {
        if (typeof res !== 'string') {
          showToast({
            title: 'Library scan',
            content: res.message,
          });
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