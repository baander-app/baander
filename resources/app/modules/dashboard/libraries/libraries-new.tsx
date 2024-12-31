import { Container, Title } from '@mantine/core';
import { CreateLibrary } from '@/modules/dashboard/libraries/components/create-library.tsx';

export function LibrariesNew() {
  return (
    <>
      <Title>Create library</Title>

      <Container>

        <CreateLibrary/>
      </Container>
    </>
  );
}