import { Container, Paper, Title, Button, Group, Text } from '@mantine/core';
import { useAppSelector } from '@/store/hooks.ts';
import { useCallback } from 'react';
import { selectAccessToken, selectRefreshToken, selectStreamToken } from '@/store/users/auth-slice.ts';
import { Icon } from '@iconify/react';

export function DevPanel() {
  const accessToken = useAppSelector(selectAccessToken);
  const refreshToken = useAppSelector(selectRefreshToken);
  const streamToken = useAppSelector(selectStreamToken);

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

  return (
    <Container>
      <Title>Dev Panel</Title>

      <Paper mt="md">
        <Text>Current tokens</Text>

        <Group>
          <Button onClick={() => copyAccessToken()} leftSection={<Icon icon="mdi:clipboard"/>}>Access token</Button>
          <Button onClick={() => copyRefreshToken()} leftSection={<Icon icon="mdi:clipboard"/>}>Refresh token</Button>
          <Button onClick={() => copyStreamToken()} leftSection={<Icon icon="mdi:clipboard"/>}>Stream token</Button>
        </Group>
      </Paper>
    </Container>
  );
}