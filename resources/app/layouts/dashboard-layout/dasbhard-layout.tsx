import { ReactNode } from 'react';
import styles from '../root-layout/root-layout.module.scss';
import { lazyImport } from '@/utils/lazy-import';

const { DashboardMenu } = lazyImport(() => import('@/layouts/dashboard-layout/components/dashboard-menu.tsx'), 'DashboardMenu');
const { InlinePlayer } = lazyImport(() => import('@/modules/library-music-player/inline-player/inline-player'), 'InlinePlayer');

export interface DashboardLayoutProps {
  children?: ReactNode;
}
export function DashboardLayout({ children }: DashboardLayoutProps) {
  return (
    <div className={styles.shell}>
      <aside className={styles.sidebar}>
        <DashboardMenu />
      </aside>

      <main className={styles.main}>
        <div className={styles.content}>
          <div className={styles.page}>
            {children}
          </div>
        </div>
      </main>

      <footer className={styles.footer}>
        <InlinePlayer />
      </footer>
    </div>
  );
}
