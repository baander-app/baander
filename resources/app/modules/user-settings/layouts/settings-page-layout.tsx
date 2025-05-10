import { ReactNode } from 'react';
import { Container, Heading } from '@radix-ui/themes';

export interface SettingsPageLayoutProps {
  title: string;
  children?: ReactNode;
}
export function SettingsPageLayout({title, children}: SettingsPageLayoutProps) {
  return (
    <Container>
      <Heading>{title}</Heading>

      <main>{children}</main>
    </Container>
  )
}