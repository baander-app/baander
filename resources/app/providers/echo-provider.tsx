import React, { useContext, useEffect, useState } from 'react';
import Pusher from 'pusher-js';
import { notifications } from '@mantine/notifications';
import { Token } from '@/services/auth/token.ts';
import Echo from 'laravel-echo';
import { PusherPrivateChannel } from 'laravel-echo/dist/channel/pusher-private-channel';
import { PusherConnectionState } from '@/providers/echo-provider.types.ts';

interface EchoContextType {
  echo: Echo | undefined;
  connectionState: string;
  playerStateChannel: PusherPrivateChannel | undefined;
}

export const EchoContext = React.createContext<EchoContextType>({
  echo: undefined,
  connectionState: '',
  playerStateChannel: undefined,
});
EchoContext.displayName = 'EchoContext';

export function EchoContextProvider({ children }: { children: React.ReactNode }) {
  const [echo, setEcho] = useState<Echo>();
  const [connectionState, setConnectionState] = useState<PusherConnectionState>('initialized');
  const [playerStateChannel, setPlayerStateChannel] = useState<Channel | undefined>(undefined);

  useEffect(() => {
    window.Pusher = Pusher;

    const token = Token.get()?.accessToken.token;

    const instance = new Echo({
      broadcaster: 'reverb',
      key: import.meta.env.VITE_REVERB_APP_KEY,
      wsHost: import.meta.env.VITE_REVERB_HOST,
      wsPort: import.meta.env.VITE_REVERB_PORT ?? 80,
      wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
      forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
      enabledTransports: ['ws', 'wss'],
      cluster: 'Redis',
      auth: {
        headers: {
          Authorization: `Bearer ${token}`,
        },
      },
    });

    setEcho(instance);
  }, []);

  useEffect(() => {
    if (echo) {
      echo
        .private('notifications')
        .listen('LibraryScanCompleted', (data: { notification: { title: string, body: string } }) => {
          notifications.show({
            title: data.notification.title,
            message: data.notification.body,
          });
        });

      const token = Token.get()?.accessToken.token;

      const psc = echo.private(`playerState.${token}`);
      setPlayerStateChannel(psc);

      echo.connector.pusher.connection.bind('connected', () => {
        const state = echo.connector.pusher.connection.state;

        setConnectionState(state);
      });
    }
  }, [echo]);

  return (
    <EchoContext.Provider
      value={{
        echo,
        connectionState,
        playerStateChannel,
      }}>
      {children}
    </EchoContext.Provider>
  );
}

export function useEcho() {
  return useContext(EchoContext);
}