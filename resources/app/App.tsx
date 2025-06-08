
import { BrowserRouter } from 'react-router-dom';

import '@fontsource-variable/inter';
import '@fontsource-variable/source-code-pro';

import { AppRoutes } from '@/routes';
import { ReactQueryDevtools } from '@tanstack/react-query-devtools';
import { MusicSourceProvider } from '@/providers/music-source-provider';
import { HelmetProvider } from 'react-helmet-async';
import { Button, Text, Theme } from '@radix-ui/themes';
import { Toast } from 'radix-ui';
import { useAppDispatch, useAppSelector } from '@/store/hooks.ts';
import styles from './app.module.scss';
import { removeToast } from '@/store/notifications/notifications-slice.ts';
import { Iconify } from './ui/icons/iconify';
import { useEffect } from 'react';
import { apm } from '@/services/apm.ts';
import { useApmRouteTracking } from '@/hooks/use-apm-route-tracking';
import { ApmErrorBoundary } from '@/components/apm/apm-error-boundary';
import { withApmInstrumentation } from '@/components/apm/with-apm-instrumentation';

// Create a wrapper component that uses the hook
function AppWithRouteTracking() {
  useApmRouteTracking();

  return (
    <ApmErrorBoundary>
      <AppRoutes />
    </ApmErrorBoundary>
  );
}

// Instrument the main app component
const InstrumentedApp = withApmInstrumentation(function App() {
  const { toasts } = useAppSelector(state => state.notifications);
  const { theme } = useAppSelector(state => state.ui);
  const { user } = useAppSelector(state => state.auth);
  const dispatch = useAppDispatch();

  useEffect(() => {
    if (user) {
      apm.setUserContext({
        email: user.email,
        username: user.name,
      });
    } else {
      apm.setUserContext({
        email: undefined,
        username: undefined,
      })
    }
  }, [user]);

  const dispatchRemoveToast = (id: string) => {
    dispatch(removeToast({ id }));
  };

  return (
    <HelmetProvider>
      <Theme
        accentColor="red"
        panelBackground="solid"
        radius="full"
        appearance={theme}
      >
        <MusicSourceProvider>
          <BrowserRouter>
            <AppWithRouteTracking />
          </BrowserRouter>

          <ReactQueryDevtools/>

          <Toast.Root></Toast.Root>
        </MusicSourceProvider>

        {toasts.map((toast) => (
          <Toast.Root
            key={toast.id}
            duration={toast.duration}
            className={styles.ToastRoot}
            onOpenChange={(isOpen) => {
              if (!isOpen) dispatchRemoveToast(toast.id);
            }}
          >
            {toast.title && (<Toast.Title className={styles.ToastTitle}>{toast.title}</Toast.Title>)}

            <Toast.Description className={styles.ToastDescription}>
              <Text>{toast.message}</Text>
            </Toast.Description>

            <Toast.Action
              className={styles.ToastAction}
              altText="Clear"
              asChild
            >
              <Button variant="ghost">
                <Iconify icon="ion:close"/>
              </Button>
            </Toast.Action>
          </Toast.Root>
        ))}

        <Toast.Viewport className={styles.ToastViewport}/>
      </Theme>
    </HelmetProvider>
  );
}, 'App');

export default InstrumentedApp;