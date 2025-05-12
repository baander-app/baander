import { useState } from 'react';
import { Button, Container, Dialog, Flex, Inset, Text } from '@radix-ui/themes';
import { useUserTokenServiceGetApiUsersTokensByUser } from '@/api-client/queries';
import { useDateFormatter } from '@/providers/dayjs-provider.tsx';
import { PersonalAccessTokenViewResource } from '@/api-client/requests';
import { TokenDetail } from '@/modules/user-settings/features/tokens/token-detail.tsx';

export function Sessions() {
  const { data, isLoading } = useUserTokenServiceGetApiUsersTokensByUser({ user: '1', perPage: 100 });
  const { formatDate } = useDateFormatter();
  const [openSession, setOpenSession] = useState<PersonalAccessTokenViewResource | undefined>();

  const setViewSession = (session: PersonalAccessTokenViewResource | undefined) => {
    setOpenSession(session);
  }

  return (
    <>
      <Dialog.Root>

      <Container>
        <Text size="1">Sessions</Text>

        <table>
          <thead>
            <tr>
              <th>Name</th>
              <th>Client</th>
              <th>Created at</th>
              <th>Expires at</th>
              <th>Last used at</th>
              <th></th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            {isLoading ? (
              <tr>
                <td>Loading...</td>
              </tr>
            ) : (
               data?.data.map((session) => (
                 <Dialog.Trigger key={session.id}>
                   <tr key={session.id} onClick={() => setViewSession(session)}>
                     <td>{session.name}</td>
                     <td>{session.clientName} {session.clientType}</td>
                     <td>{formatDate(session.createdAt)}</td>
                     <td>{formatDate(session.expiresAt)}</td>
                     <td>{formatDate(session.lastUsedAt)}</td>
                     <td>
                       <Button>View</Button>
                     </td>
                     <td>
                       <Button color="red">Revoke</Button>
                     </td>
                   </tr>
                 </Dialog.Trigger>
               ))
             )}
          </tbody>
        </table>
      </Container>


        <Dialog.Content>
          <Dialog.Title>Token</Dialog.Title>

          <Inset side="x" my="5">
            {openSession && <TokenDetail item={openSession} />}
          </Inset>

          <Flex gap="3" justify="end">
            <Dialog.Close>
              <Button variant="soft" color="gray">
                Close
              </Button>
            </Dialog.Close>
          </Flex>
        </Dialog.Content>

      </Dialog.Root>
    </>
  );
}