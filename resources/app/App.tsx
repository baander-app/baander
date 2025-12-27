import { BrowserRouter, HashRouter } from 'react-router-dom';
import { AppRoutes } from '@/app/routes';
import { ReactQueryDevtools } from '@tanstack/react-query-devtools';
import { HelmetProvider } from 'react-helmet-async';
import { Button, Text, Theme } from '@radix-ui/themes';
import { Toast } from 'radix-ui';
import { useAppDispatch, useAppSelector } from '@/app/store/hooks.ts';
import styles from './app.module.scss';
import { removeToast } from '@/app/store/notifications/notifications-slice.ts';
import { useEffect } from 'react';
import CloseIcon from '~icons/ion/close';
import { initializeGlobalAudioProcessor } from '@/app/modules/library-music-player/store';
import { isWeb } from '@/app/utils/platform.ts';
import { DeepLinkProvider } from '@/app/providers/deep-link-provider.tsx';
import { useAudioProcessorSettingsSubscription, useTheme } from '@/app/store/settings';
import { ErrorBoundary } from '@/app/components/error-boundary';

const isFileProtocol = window.location.protocol === 'file:';
const Router = isFileProtocol ? HashRouter : BrowserRouter;

const App = () => {
  const { toasts } = useAppSelector(state => state.notifications);
  const theme = useTheme();
  const dispatch = useAppDispatch();

  const dispatchRemoveToast = (id: string) => {
    dispatch(removeToast({ id }));
  };

  useEffect(() => {
    if (isWeb()) {
      Notification.requestPermission();
    }

    initializeGlobalAudioProcessor().catch(console.error);
  }, []);

  // Subscribe to settings changes to keep audio processor in sync
  useAudioProcessorSettingsSubscription();

  return (
    <ErrorBoundary name="App" onError={(error) => console.error('Global error caught:', error)}>
      <HelmetProvider>
        <Theme
          accentColor="red"
          panelBackground="solid"
          radius="full"
          appearance={theme}
        >
          <Router>
            <DeepLinkProvider>
              <AppRoutes/>
            </DeepLinkProvider>
          </Router>

          <ReactQueryDevtools/>

          {/*<Toast.Root style={{ pointerEvents: 'none' }} />*/}

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
                  <CloseIcon/>
                </Button>
              </Toast.Action>
            </Toast.Root>
          ))}

          <Toast.Viewport className={styles.ToastViewport}/>
        </Theme>
      </HelmetProvider>
    </ErrorBoundary>
  );
};

export default App;
