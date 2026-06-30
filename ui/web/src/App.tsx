import { createBrowserRouter, RouterProvider } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { routes } from './features/layout/routes';
import { PreferenceSyncProvider } from './features/settings/hooks/use-preference-bootstrap';
import { ThemeProvider } from './shared/theme';

const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      staleTime: 5 * 60 * 1000,
      retry: 1,
    },
  },
});

const router = createBrowserRouter(routes);

function App() {
  return (
    <QueryClientProvider client={queryClient}>
      <PreferenceSyncProvider>
        <ThemeProvider>
          <RouterProvider router={router}/>
          {/* Toaster is rendered by AppShell — do NOT add a second one here */}
        </ThemeProvider>
      </PreferenceSyncProvider>
    </QueryClientProvider>
  );
}

export default App;
