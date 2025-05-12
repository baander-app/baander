import { ReactNode } from 'react';
import { Container, Heading } from '@radix-ui/themes';

export interface SettingsPageLayoutProps {
  title: string;
  children?: ReactNode;
}
export function SettingsPageLayout({title, children}: SettingsPageLayoutProps) {
  return (
    <Container>
      <Heading mt="2" mb="2">{title}</Heading>

      <main>{children}</main>
    </Container>
  )
}