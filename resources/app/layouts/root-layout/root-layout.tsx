import { ReactNode } from 'react';
import styles from './root-layout.module.scss';
import { lazyImport } from '@/utils/lazy-import';
import { RootMenu } from '@/layouts/root-layout/components/root-menu';
import { NotificationArea } from '@/modules/notifications/notification-area.tsx';

const { InlinePlayer } = lazyImport(() => import('@/modules/library-music-player/inline-player/inline-player'), 'InlinePlayer');

export function RootLayout(props: { children?: ReactNode }) {
  return (
    <div className={styles.shell}>
      <aside className={styles.sidebar}>
        <RootMenu />

        <NotificationArea />
      </aside>

      <main className={styles.main}>
        <div className={styles.content}>
          <div className={styles.page}>
            {props.children}
          </div>
        </div>
      </main>

      <footer className={styles.footer}>
        <InlinePlayer />
      </footer>
    </div>
  );
}
