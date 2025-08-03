import { BrowserRouter } from 'react-router-dom';
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

// Instrument the main app component
const App = () => {
  const { toasts } = useAppSelector(state => state.notifications);
  const { theme } = useAppSelector(state => state.ui);
  const dispatch = useAppDispatch();

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
            <AppRoutes/>
          </BrowserRouter>

          <ReactQueryDevtools/>

          {/*<Toast.Root style={{ pointerEvents: 'none' }} />*/}
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
  )
}

export default App;