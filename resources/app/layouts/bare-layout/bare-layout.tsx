import { ReactNode } from 'react';
// import { Outlet } from 'react-router-dom';
// import styles from './bare-layout.module.scss';
import { lazyImport } from '@/utils/lazy-import.ts';
import { AppShell, Flex, Title } from '@mantine/core';
const {BaanderLogo} = lazyImport(() => import('@/components/branding/baander-logo/baander-logo.tsx'), 'BaanderLogo');

export function BareLayout(props: { children?: ReactNode }) {
  return (
    <AppShell>
      <AppShell.Header>
        <Flex ml="sm">
          <BaanderLogo />
          <Title ml="sm" fw="normal">Bånder</Title>
        </Flex>
      </AppShell.Header>
      <AppShell.Main h="100">
        {props.children}
      </AppShell.Main>
    </AppShell>
  );
}
