import { Navigate } from 'react-router-dom'
import { RegisterForm } from '../components/RegisterForm'
import { useAuthStore } from '../stores/auth-store'
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

export function RegisterPage() {
  const isAuthenticated = useAuthStore((s) => s.isAuthenticated)

  if (isAuthenticated) {
    return <Navigate to="/" replace />
  }

  return (
    <PageWrapper>
      <Card>
        <Title>Create account</Title>
        <RegisterForm />
      </Card>
    </PageWrapper>
  )
}
