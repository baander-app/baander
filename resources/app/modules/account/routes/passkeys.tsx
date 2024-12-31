import { Button, Container, Modal, Title } from '@mantine/core';
import { CreatePasskey } from '@/modules/account/passkeys/create-passkey.tsx';
import { useDisclosure } from '@mantine/hooks';

export function Passkeys() {
  const [createPasskeyOpen, passkeyModalHandlers] = useDisclosure();

  return (
    <Container fluid>
      <Title>Passkeys</Title>

      <Button onClick={() => passkeyModalHandlers.open()}>Create</Button>

      <CreatePasskey />
    </Container>
  )
}