import { UnstyledButton, Group, Text } from '@mantine/core';
import styles from './user-button.module.scss';
import { useUserServiceUsersMeSuspense } from '@/api-client/queries/suspense.ts';
import { Suspense } from 'react';
import { Icon } from '@iconify/react';
import { useUserServiceUsersMe } from '@/api-client/queries';

export function UserButton() {
  const {data} = useUserServiceUsersMe();

  return (
    <UnstyledButton className={styles.user}>
        <Group>
          <div style={{flex: 1}}>
            <Text size="sm" fw={500}>
              {data?.name}
            </Text>

            <Text c="dimmed" size="xs">
              {data?.email}
            </Text>
          </div>

          <Icon icon="mdi:user" />
        </Group>
    </UnstyledButton>
  );
}