import { Container, Heading } from '@radix-ui/themes';
import { CreateLibrary } from '@/modules/dashboard/libraries/components/create-library.tsx';

export function LibrariesNew() {
  return (
    <>
      <Container mt="3">
        <Heading>Create library</Heading>
        <CreateLibrary/>
      </Container>
    </>
  );
}