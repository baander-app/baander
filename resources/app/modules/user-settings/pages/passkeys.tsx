import { Button, Container, Dialog, Heading, Inset } from '@radix-ui/themes';
import { CreatePasskey } from '@/modules/user-settings/features/passkeys/create-passkey.tsx';

export function Passkeys() {

  return (
    <>
      <Dialog.Root>
        <Container>
          <Heading>Passkeys</Heading>

          <Dialog.Trigger>
            <Button>Create</Button>
          </Dialog.Trigger>

        </Container>

        <Dialog.Content>
          <Dialog.Title>Create passkey</Dialog.Title>

          <Inset side="x" my="5">
            <CreatePasskey/>
          </Inset>


          <Dialog.Close>
            <Button variant="soft" color="gray">
              Close
            </Button>
          </Dialog.Close>
        </Dialog.Content>
      </Dialog.Root>
    </>
  );
}