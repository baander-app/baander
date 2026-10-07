import type { ReactNode } from 'react'
import styled from 'styled-components'

const PageWrapper = styled.div`
  display: flex;
  min-height: 100vh;
  align-items: center;
  justify-content: center;
  background-color: var(--color-background);
`;

const Card = styled.div`
  width: 100%;
  max-width: 24rem;
  display: flex;
  flex-direction: column;
  gap: 1.5rem;
  border-radius: var(--radius-lg);
  background-color: var(--color-card);
  padding: 1.5rem;
`;

const Title = styled.h1`
  text-align: center;
  font-size: 1.125rem;
  font-weight: 600;
  letter-spacing: -0.025em;
`;

interface AuthPageLayoutProps {
  title: string
  children: ReactNode
}

/** The centered card shared by the signed-out account pages. */
export function AuthPageLayout({ title, children }: AuthPageLayoutProps) {
  return (
    <PageWrapper>
      <Card>
        <Title>{title}</Title>
        {children}
      </Card>
    </PageWrapper>
  )
}
