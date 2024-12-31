import { ReactNode } from 'react';
import { AppShell } from '@mantine/core';
import { lazyImport } from '@/utils/lazy-import.ts';

const { DashboardMenu } = lazyImport(() => import('@/layouts/dashboard-layout/components/dashboard-menu.tsx'), 'DashboardMenu');

export interface DashboardLayoutProps {
  children?: ReactNode;
}
export function DashboardLayout({ children }: DashboardLayoutProps) {
  return (
    <AppShell
      layout="alt"
      padding="sm"
      navbar={{ width: 200, breakpoint: 'sm'}}
    >
      <AppShell.Navbar>
        <DashboardMenu />
      </AppShell.Navbar>

      <AppShell.Main>
        {children}
      </AppShell.Main>
    </AppShell>
  )
}