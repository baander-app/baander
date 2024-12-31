import { BrowserRouter } from 'react-router-dom';

import '@fontsource/open-sans';

import { AppRoutes } from '@/routes';
import { ReactQueryDevtools } from '@tanstack/react-query-devtools';
import { MusicSourceProvider } from '@/providers/music-source-provider';
import { MantineProvider } from '@mantine/core';
import { Notifications } from '@mantine/notifications';
import { ModalsProvider } from '@mantine/modals';
import { DatesProvider } from '@mantine/dates';
import { ContextMenuProvider } from 'mantine-contextmenu';
import { HelmetProvider } from 'react-helmet-async';

function App() {
  return (
    <HelmetProvider>
      <MantineProvider>
        <ContextMenuProvider>
          <DatesProvider settings={{ locale: 'en' }}>
            <Notifications/>

            <ModalsProvider>
              <MusicSourceProvider>
                <BrowserRouter future={{ v7_startTransition: true }}>
                  <AppRoutes/>
                </BrowserRouter>

                <ReactQueryDevtools/>
              </MusicSourceProvider>
            </ModalsProvider>
          </DatesProvider>
        </ContextMenuProvider>
      </MantineProvider>
    </HelmetProvider>
  );
}

export default App;
