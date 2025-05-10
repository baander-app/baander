import { Box, Button, Container, Flex, Heading, Text } from '@radix-ui/themes';
import { useAppDispatch, useAppSelector } from '@/store/hooks.ts';
import { useCallback } from 'react';
import { selectAccessToken, selectRefreshToken, selectStreamToken } from '@/store/users/auth-slice.ts';
import { useTestMode } from '@/providers/test-mode-provider.tsx';
import { CreateNotification } from '@/modules/notifications/models.ts';
import { createNotification } from '@/store/notifications/notifications-slice.ts';

export function DevPanel() {
  const dispatch = useAppDispatch();

  const accessToken = useAppSelector(selectAccessToken);
  const refreshToken = useAppSelector(selectRefreshToken);
  const streamToken = useAppSelector(selectStreamToken);
  const { isTestMode, toggleTestMode } = useTestMode();

  const copyAccessToken = useCallback(() => {
    if (accessToken && accessToken.token) {
      navigator.clipboard.writeText(accessToken.token);
    }
  }, [accessToken]);

  const copyRefreshToken = useCallback(() => {
    if (refreshToken && refreshToken.token) {
      navigator.clipboard.writeText(refreshToken.token);
    }
  }, [refreshToken]);

  const copyStreamToken = useCallback(() => {
    if (streamToken && streamToken.token) {
      navigator.clipboard.writeText(streamToken.token);
    }
  }, [streamToken]);

  const addTestNotification = () => {
    const notifications: CreateNotification[] = [
      {
        type: 'info',
        message: 'Test notification',
      },
      {
        type: 'success',
        message: 'Test success notification',
      },
      {
        type: 'warning',
        message: 'Test warning notification',
      },
      {
        type: 'error',
        message: 'Test error notification',
      }
    ]

    notifications.forEach((notification) => {
      dispatch(createNotification(notification));
    })
  }

  return (
    <Container>
      <Heading>Dev Panel</Heading>

      <Flex mt="2" direction="column">
        <Text>Current tokens</Text>

        <Flex gap="2" mt="2">
          <Button onClick={() => copyAccessToken()}>Access token</Button>
          <Button onClick={() => copyRefreshToken()}>Refresh token</Button>
          <Button onClick={() => copyStreamToken()}>Stream token</Button>
        </Flex>
      </Flex>

      <Flex direction="column" mt="2">
        <Text>Test mode</Text>

        <Box mt="2">
          <Button onClick={() => toggleTestMode()}>
            {isTestMode ? 'Disable' : 'Enable'}
          </Button>
        </Box>
      </Flex>

      <Flex direction="column" mt="2">
        <Text weight="bold">Add test notifications</Text>

        <Box mt="2">
          <Button onClick={() => addTestNotification()}>Add</Button>
        </Box>
      </Flex>
    </Container>
  );
}