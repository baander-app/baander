import { createBrowserRouter, createHashRouter, RouterProvider } from 'react-router-dom';
import { AuthenticatedClientProvider } from './shared/live/provider';
import { routes } from './features/layout/routes';
import { PreferenceSyncProvider } from './features/settings/hooks/use-preference-bootstrap';
import { ThemeProvider } from './shared/theme';

const createRouter = window.location.protocol === 'file:' ? createHashRouter : createBrowserRouter;
const router = createRouter(routes);

function App() {
  return (
    <AuthenticatedClientProvider freshnessEnabled>
      <PreferenceSyncProvider>
        <ThemeProvider>
          <RouterProvider router={router}/>
          {/* Toaster is rendered by AppShell — do NOT add a second one here */}
        </ThemeProvider>
      </PreferenceSyncProvider>
    </AuthenticatedClientProvider>
  );
}

export default App;
