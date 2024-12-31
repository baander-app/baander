import { useState } from 'react';
import { Button, Container, Modal, Table, Title } from '@mantine/core';
import { useUserTokenServiceUserTokenGetUserTokens } from '@/api-client/queries';
import { useDateFormatter } from '@/providers/dayjs-provider.tsx';
import { useDisclosure } from '@mantine/hooks';
import { PersonalAccessTokenViewResource } from '@/api-client/requests';
import { TokenDetail } from '@/modules/user-settings/features/tokens/token-detail.tsx';

export function Sessions() {
  const { data, isLoading } = useUserTokenServiceUserTokenGetUserTokens({ user: '1', perPage: 100 });
  const { formatDate } = useDateFormatter();
  const [openSession, setOpenSession] = useState<PersonalAccessTokenViewResource | undefined>();
  const [opened, { open, close }] = useDisclosure(false);

  const setViewSession = (session: PersonalAccessTokenViewResource | undefined) => {
    setOpenSession(session);
    if (session) {
      open();
    }
  }

  const closeViewSession = () => {
    setOpenSession(undefined);
    close();
  }

  return (
    <>
      <Container>
        <Title>Sessions</Title>

        <Table>
          <Table.Thead>
            <Table.Tr>
              <Table.Th>Name</Table.Th>
              <Table.Th>Client</Table.Th>
              <Table.Th>Created at</Table.Th>
              <Table.Th>Expires at</Table.Th>
              <Table.Th>Last used at</Table.Th>
              <Table.Th></Table.Th>
              <Table.Th></Table.Th>
            </Table.Tr>
          </Table.Thead>
          <Table.Tbody>
            {isLoading ? (
              <Table.Tr>
                <Table.Td>Loading...</Table.Td>
              </Table.Tr>
            ) : (
               data?.data.map((session) => (
                 <Table.Tr key={session.id} onClick={() => setViewSession(session)}>
                   <Table.Td>{session.name}</Table.Td>
                   <Table.Td>{session.clientName} {session.clientType}</Table.Td>
                   <Table.Td>{formatDate(session.createdAt)}</Table.Td>
                   <Table.Td>{formatDate(session.expiresAt)}</Table.Td>
                   <Table.Td>{formatDate(session.lastUsedAt)}</Table.Td>
                   <Table.Td>
                     <Button>View</Button>
                   </Table.Td>
                   <Table.Td>
                     <Button color="red">Revoke</Button>
                   </Table.Td>
                 </Table.Tr>
               ))
             )}
          </Table.Tbody>
        </Table>
      </Container>

      <Modal opened={opened} onClose={closeViewSession} title="Token">
        {openSession && <TokenDetail item={openSession} />}
      </Modal>
    </>
  );
}