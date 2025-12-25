import { Box, Button, Container, Flex, Heading, Text } from '@radix-ui/themes';
import { useAppDispatch } from '@/app/store/hooks.ts';
import { useCallback } from 'react';
import { useTestMode } from '@/app/providers/test-mode-provider.tsx';
import { CreateNotification } from '@/app/modules/notifications/models.ts';
import { createNotification } from '@/app/store/notifications/notifications-slice.ts';
import { Token } from '@/app/services/auth/token.ts';

export function DevPanel() {
  const dispatch = useAppDispatch();
  const { isTestMode, toggleTestMode } = useTestMode();

  const copyAccessToken = useCallback(() => {
    const token = Token.get();
    if (token?.access_token) {
      navigator.clipboard.writeText(token.access_token);
    }
  }, []);

  const copyRefreshToken = useCallback(() => {
    const token = Token.get();
    if (token?.refresh_token) {
      navigator.clipboard.writeText(token.refresh_token);
    }
  }, []);

  const copyStreamToken = useCallback(() => {
    const token = Token.getStreamToken();
    if (token) {
      navigator.clipboard.writeText(token.token);
    }
  }, []);

  const addTestNotification = () => {
    const notifications: CreateNotification[] = [
      {
        type: 'info',
        message: 'Test notification',
      },
      {
        title: 'Test title',
        type: 'success',
        message: 'Test success notification',
        toast: true,
      },
      {
        type: 'warning',
        message: 'Test warning notification',
      },
      {
        type: 'error',
        message: 'Test error notification',
      },
    ];

    notifications.forEach((notification) => {
      dispatch(createNotification(notification));
    });
  };

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