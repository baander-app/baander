import { createBrowserRouter, createHashRouter, RouterProvider } from 'react-router-dom';
import { AuthenticatedClientProvider } from './shared/live/provider';
import { routes } from './features/layout/routes';
import { PreferenceSyncProvider } from './features/settings/hooks/use-preference-bootstrap';
import { Toaster } from './shared/components/ui/sonner';
import { ThemeProvider } from './shared/theme';

const createRouter = window.location.protocol === 'file:' ? createHashRouter : createBrowserRouter;
const router = createRouter(routes);

function App() {
  return (
    <AuthenticatedClientProvider freshnessEnabled>
      <PreferenceSyncProvider>
        <ThemeProvider>
          {/* The only Toaster: mounted at the root so toasts show on every route, including admin and OAuth pages. */}
          <Toaster />
          <RouterProvider router={router}/>
        </ThemeProvider>
      </PreferenceSyncProvider>
    </AuthenticatedClientProvider>
  );
}

export default App;
