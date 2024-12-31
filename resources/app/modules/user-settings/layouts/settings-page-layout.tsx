import { ReactNode } from 'react';
import { Container, Title } from '@mantine/core';

export interface SettingsPageLayoutProps {
  title: string;
  children?: ReactNode;
}
export function SettingsPageLayout({title, children}: SettingsPageLayoutProps) {
  return (
    <Container fluid>
      <Title order={1}>{title}</Title>

      <main>{children}</main>
    </Container>
  )
}