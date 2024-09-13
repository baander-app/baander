import { Container, Table, Title } from '@mantine/core';
import { useUserTokenServiceUserTokenGetUserTokens } from '@/api-client/queries';
import dayjs from 'dayjs';


export function Sessions() {
  const { data, isLoading } = useUserTokenServiceUserTokenGetUserTokens({ user: '1', perPage: 100 });

  return (
    <Container>
      <Title>Sessions</Title>

      <Table>
        <Table.Thead>
          <Table.Tr>
            <Table.Th>Name</Table.Th>
            <Table.Th>Client</Table.Th>
            <Table.Th>Created at</Table.Th>
            <Table.Th>Expires at</Table.Th>
            <Table.Th></Table.Th>
            <Table.Th></Table.Th>
          </Table.Tr>
        </Table.Thead>
        <Table.Tbody>
          {isLoading ? (
            <Table.Tr>
              <Table.Td >Loading...</Table.Td>
            </Table.Tr>
          ) : (
             data?.data.map((session) => (
               <Table.Tr key={session.id}>
                 <Table.Td>{session.name}</Table.Td>
                 <Table.Td>{session.clientName} {session.clientType}</Table.Td>
                 <Table.Td>{dayjs(session.createdAt).format()}</Table.Td>
                 <Table.Td>{dayjs(session.expiresAt).format()}</Table.Td>
                 <Table.Td>
                   {/* Add additional action buttons or links as needed */}
                 </Table.Td>
                 <Table.Td></Table.Td>
               </Table.Tr>
             ))
           )}
        </Table.Tbody>
      </Table>
    </Container>
  );
}