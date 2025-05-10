
import styles from './user-button.module.scss';
import { SocketConnection } from '@/ui/socket-connection/socket-connection.tsx';
import { Button, Text } from '@radix-ui/themes';
import { useUserServiceGetApiUsersMe } from '@/api-client/queries';

export function UserButton() {
  const {data} = useUserServiceGetApiUsersMe();

  return (
    <Button className={styles.user} variant="ghost" size="1">
      <Text size="2" weight="medium">
        {data?.name}
      </Text>

      <SocketConnection />
    </Button>
  );
}