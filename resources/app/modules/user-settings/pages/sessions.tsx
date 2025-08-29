import { useState } from 'react';
import { Button, Container, Dialog, Flex, Heading, Inset } from '@radix-ui/themes';
import { useDateFormatter } from '@/providers/dayjs-provider.tsx';
import { TokenDetail } from '@/modules/user-settings/features/tokens/token-detail.tsx';
import { PersonalAccessTokenViewResource } from '@/libs/api-client/gen/models';
import { revokeAllTokensExceptCurrent } from '@/services/auth/auth.service.ts';
import { useAppDispatch } from '@/store/hooks';
import { createNotification } from '@/store/notifications/notifications-slice.ts';
import { useUserTokensIndex } from '@/libs/api-client/gen/endpoints/user-token/user-token.ts';

export function Sessions() {
  const { data, isLoading, refetch } = useUserTokensIndex('1');
  const { formatDate } = useDateFormatter();
  const [openSession, setOpenSession] = useState<PersonalAccessTokenViewResource | undefined>();
  const dispatch = useAppDispatch();

  const setViewSession = (session: PersonalAccessTokenViewResource | undefined) => {
    setOpenSession(session);
  };

  const revokeAll = () => {
    revokeAllTokensExceptCurrent()
      .then(() => {
        dispatch(createNotification({
          type: 'success',
          message: 'All tokens revoked',
        }));

        refetch();
      }).catch((err) => {
      dispatch(createNotification({
        type: 'error',
        title: 'Failed to revoke all tokens',
        message: err.message,
      }));
    });
  };

  return (
    <>
      <Dialog.Root>

        <Container>
          <Flex justify="between" mt="2" mb="2">
            <Heading>Sessions</Heading>

            <Button onClick={() => revokeAll()}>Revoke all</Button>
          </Flex>

          <table>
            <thead>
            <tr>
              <th>Name</th>
              <th>IP</th>
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
               data?.map((session) => (
                 <Dialog.Trigger key={session.id}>
                   <tr key={session.id} onClick={() => setViewSession(session)}>
                     <td>{session.name}</td>
                     <td>{session.ipAddress}</td>
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
            {openSession && <TokenDetail item={openSession}/>}
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
