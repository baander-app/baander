import { ReactNode } from 'react';
import { AppShell, Group, Text } from '@mantine/core';
import { DashboardMenu } from '@/layouts/dashboard-layout/dashboard-menu.tsx';
import { NavLink } from '@/components/nav-link.tsx';
import { Icon } from '@iconify/react';


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