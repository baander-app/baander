import { Box, Container, Title } from '@mantine/core';
import { UserTable } from '@/features/ui-users/user-table/user-table.tsx';

export function UsersList() {


  return (
    <Container fluid>
      <Title>Users</Title>

      <Box mt="lg">
        <UserTable/>
      </Box>
    </Container>
  );
}