import React, { useContext, useEffect, useState } from 'react';
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import { notifications } from '@mantine/notifications';
import { Token } from '@/services/auth/token.ts';

interface EchoContextType {
  echo?: Echo;
}

export const EchoContext = React.createContext<EchoContextType>({});
EchoContext.displayName = 'EchoContext';

export function EchoContextProvider({children}: { children: React.ReactNode }) {
  const [echo, setEcho] = useState<Echo>();

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
          Authorization: `Bearer ${token}`
        }
      }
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
    }
  }, [echo]);

  return (
    <EchoContext.Provider value={{echo}}>
      {children}
    </EchoContext.Provider>
  );
}

export function useEcho(): Echo {
  return useContext(EchoContext).echo!;
}