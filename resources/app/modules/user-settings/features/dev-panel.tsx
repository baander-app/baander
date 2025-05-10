import { Button, Container, Flex, Heading, Text } from '@radix-ui/themes';
import { useAppSelector } from '@/store/hooks.ts';
import { useCallback } from 'react';
import { selectAccessToken, selectRefreshToken, selectStreamToken } from '@/store/users/auth-slice.ts';
import { useTestMode } from '@/providers/test-mode-provider.tsx';

export function DevPanel() {
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

  return (
    <Container>
      <Heading>Dev Panel</Heading>

      <Flex mt="md">
        <Text>Current tokens</Text>

        <Flex>
          <Button onClick={() => copyAccessToken()}>Access token</Button>
          <Button onClick={() => copyRefreshToken()}>Refresh token</Button>
          <Button onClick={() => copyStreamToken()}>Stream token</Button>
        </Flex>
      </Flex>

      <Flex>
        <Text>Test mode</Text>

        <Button onClick={() => toggleTestMode()}>
          {isTestMode ? 'Disable' : 'Enable'}
        </Button>
      </Flex>
    </Container>
  );
}