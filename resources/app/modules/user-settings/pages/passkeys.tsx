import { Button, Container, Modal, Title } from '@mantine/core';
import { CreatePasskey } from '@/modules/user-settings/features/passkeys/create-passkey.tsx';
import { useDisclosure } from '@mantine/hooks';

export function Passkeys() {
  const [createPasskeyOpen, passkeyModalHandlers] = useDisclosure();

  return (
    <>
      <Container fluid>
        <Title>Passkeys</Title>

        <Button onClick={() => passkeyModalHandlers.open()}>Create</Button>

      </Container>

      <Modal opened={createPasskeyOpen} onClose={() => passkeyModalHandlers.close()}>
        <Modal.Body>
          <CreatePasskey/>
        </Modal.Body>
      </Modal>
    </>
  );
}