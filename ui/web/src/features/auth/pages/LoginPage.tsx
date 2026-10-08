import { Navigate, useLocation } from 'react-router-dom'
import { useTranslation } from '@/shared/i18n'
import { LoginForm } from '../components/LoginForm'
import { Notice } from '../components/auth-form-styles'
import type { PasswordResetDoneState } from '../components/ResetPasswordForm'
import { returnPathFrom } from '../lib/return-to'
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

const Header = styled.div`
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.75rem;
`;

const Logo = styled.img`
  height: 3.5rem;
  width: 3.5rem;
`;

const Title = styled.h1`
  font-size: 1.125rem;
  font-weight: 600;
  letter-spacing: -0.025em;
`;

function isPasswordResetDone(state: unknown): state is PasswordResetDoneState {
  return typeof state === 'object' && state !== null && 'passwordReset' in state && state.passwordReset === true
}

export function LoginPage() {
  const { t } = useTranslation()
  const location = useLocation()
  const isAuthenticated = useAuthStore((s) => s.isAuthenticated)

  if (isAuthenticated) {
    return <Navigate to={returnPathFrom(location.state)} replace />
  }

  return (
    <PageWrapper>
      <Card>
        <Header>
          <Logo src="/logo.svg" alt="Bånder" />
          <Title>Bånder</Title>
        </Header>
        {isPasswordResetDone(location.state) && (
          <Notice role="status">
            {t('auth.passwordReset.success')}
          </Notice>
        )}
        <LoginForm />
      </Card>
    </PageWrapper>
  )
}
