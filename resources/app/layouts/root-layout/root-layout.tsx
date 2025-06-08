import { ReactNode } from 'react';
import styles from './root-layout.module.scss';
import { lazyImport } from '@/utils/lazy-import';
import { RootMenu } from '@/layouts/root-layout/components/root-menu';
import { NotificationArea } from '@/modules/notifications/notification-area.tsx';
import { ApmErrorBoundary } from '@/components/apm/apm-error-boundary.tsx';

const { InlinePlayer } = lazyImport(() => import('@/modules/library-music-player/inline-player.tsx'), 'InlinePlayer');

export function RootLayout(props: { children?: ReactNode }) {
  return (
    <div className={styles.shell}>
      <aside className={styles.sidebar}>
        <ApmErrorBoundary>
          <RootMenu />

          <NotificationArea />
        </ApmErrorBoundary>
      </aside>

      <main className={styles.main}>
        <div className={styles.content}>
          <div className={styles.page}>
            <ApmErrorBoundary>
              {props.children}
            </ApmErrorBoundary>
          </div>
        </div>
      </main>

      <footer className={styles.footer}>
        <ApmErrorBoundary>
          <InlinePlayer />
        </ApmErrorBoundary>
      </footer>
    </div>
  );
}
