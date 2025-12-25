import { Box, Container, Heading } from '@radix-ui/themes';
import { UserTable } from '@/app/ui/users/user-table/user-table.tsx';

export function UsersList() {


  return (
    <Container>
      <Heading>Users</Heading>

      <Box mt="lg">
        <UserTable/>
      </Box>
    </Container>
  );
}