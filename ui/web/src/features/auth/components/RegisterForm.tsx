import { useState, type FormEvent } from 'react'
import { useNavigate, Link } from 'react-router-dom'
import { useAuthStore } from '../stores/auth-store'
import { useTranslation } from '@/shared/i18n'
import { Input } from '@/shared/components/ui/input'
import { Button } from '@/shared/components/ui/button'
import { parseApiError } from '../lib/parse-api-error'
import styled from 'styled-components'

const Form = styled.form`
  display: flex;
  flex-direction: column;
  gap: 1rem;
`;

const ErrorAlert = styled.div`
  border-radius: var(--radius-md);
  background-color: color-mix(in srgb, var(--color-destructive) 10%, transparent);
  padding: 0.75rem;
  font-size: 0.875rem;
  color: var(--color-destructive);
`;

const FieldWrapper = styled.div``;

const Label = styled.label`
  display: block;
  margin-bottom: 0.375rem;
  font-size: 0.75rem;
  font-weight: 500;
  color: var(--color-muted-foreground);
`;

const StyledButton = styled(Button)`
  width: 100%;
`;

const FooterText = styled.p`
  text-align: center;
  font-size: 0.75rem;
  color: var(--color-muted-foreground);
`;

const StyledLink = styled(Link)`
  color: var(--color-primary);
  text-decoration: none;

  &:hover {
    text-decoration: underline;
  }
`;

export function RegisterForm() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const register = useAuthStore((s) => s.register)
  const isLoading = useAuthStore((s) => s.isLoading)

  const [name, setName] = useState('')
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [confirmPassword, setConfirmPassword] = useState('')
  const [error, setError] = useState<string | null>(null)

  const handleSubmit = async (e: FormEvent) => {
    e.preventDefault()
    setError(null)

    if (password !== confirmPassword) {
      setError(t('auth.passwordMismatch'))
      return
    }

    try {
      await register(email, password, name || undefined)
      navigate('/login')
    } catch (err: unknown) {
      const parsed = parseApiError(err, t('common.error'))
      setError(parsed.message)
    }
  }

  return (
    <Form onSubmit={handleSubmit}>
      {error && (
        <ErrorAlert>
          {error}
        </ErrorAlert>
      )}

      <FieldWrapper>
        <Label htmlFor="reg-name">
          {t('auth.name')}
        </Label>
        <Input
          id="reg-name"
          type="text"
          value={name}
          onChange={(e) => setName(e.target.value)}
          autoComplete="name"
        />
      </FieldWrapper>

      <FieldWrapper>
        <Label htmlFor="reg-email">
          {t('auth.email')}
        </Label>
        <Input
          id="reg-email"
          type="email"
          required
          value={email}
          onChange={(e) => setEmail(e.target.value)}
          autoComplete="email"
        />
      </FieldWrapper>

      <FieldWrapper>
        <Label htmlFor="reg-password">
          {t('auth.password')}
        </Label>
        <Input
          id="reg-password"
          type="password"
          required
          value={password}
          onChange={(e) => setPassword(e.target.value)}
          autoComplete="new-password"
        />
      </FieldWrapper>

      <FieldWrapper>
        <Label htmlFor="reg-confirm-password">
          {t('auth.confirmPassword')}
        </Label>
        <Input
          id="reg-confirm-password"
          type="password"
          required
          value={confirmPassword}
          onChange={(e) => setConfirmPassword(e.target.value)}
          autoComplete="new-password"
        />
      </FieldWrapper>

      <StyledButton type="submit" disabled={isLoading}>
        {isLoading ? t('common.loading') : t('auth.register')}
      </StyledButton>

      <FooterText>
        Already have an account?{' '}
        <StyledLink to="/login">
          {t('auth.login')}
        </StyledLink>
      </FooterText>
    </Form>
  )
}
