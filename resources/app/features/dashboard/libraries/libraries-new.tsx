import { Box, Button, Container, Table, Title } from '@mantine/core';
import { CreateLibrary } from '@/features/dashboard/libraries/components/create-library.tsx';

export function LibrariesNew() {
  return (
    <>
      <Title>Create library</Title>

      <Container>

        <CreateLibrary />
      </Container>
    </>
  )
}