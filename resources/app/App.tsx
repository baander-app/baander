import { BrowserRouter } from 'react-router-dom';

import '@fontsource-variable/inter';
import '@fontsource-variable/source-code-pro';

import { AppRoutes }           from '@/routes';
// import { ReactQueryDevtools } from '@tanstack/react-query-devtools';
import { MusicSourceProvider } from '@/providers/music-source-provider';
import { HelmetProvider }      from 'react-helmet-async';
import { Theme }               from '@radix-ui/themes';


function App() {
  return (
    <HelmetProvider>
      <Theme
        accentColor="red"
        panelBackground="solid"
        radius="full"
      >
        <MusicSourceProvider>
          <BrowserRouter future={{v7_startTransition: true, v7_relativeSplatPath: true}}>
            <AppRoutes/>
          </BrowserRouter>

          {/*<ReactQueryDevtools/>*/}
        </MusicSourceProvider>
      </Theme>
    </HelmetProvider>
  );
}

export default App;
