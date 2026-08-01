import { type FormEvent, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useAuthStore } from '../stores/auth-store';
import { useTranslation } from '@/shared/i18n';
import { Input } from '@/shared/components/ui/input';
import { Button } from '@/shared/components/ui/button';
import { parseApiError } from '../lib/parse-api-error';
import styled from 'styled-components';

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

const Honeypot = styled.div`
  position: absolute;
  left: -9999px;
  opacity: 0;
  height: 0;
  width: 0;
  overflow: hidden;
`;

const StyledButton = styled(Button)`
  width: 100%;
`;

export function LoginForm() {
  const {t} = useTranslation();
  const navigate = useNavigate();
  const login = useAuthStore((s) => s.login);
  const isLoading = useAuthStore((s) => s.isLoading);

  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [totpCode, setTotpCode] = useState('');
  const [showTotp, setShowTotp] = useState(false);
  const [honeypot, setHoneypot] = useState('');
  const [error, setError] = useState<string | null>(null);

  const handleSubmit = async (e: FormEvent) => {
    e.preventDefault();
    setError(null);

    if (honeypot) return;

    try {
      await login(email, password, showTotp ? totpCode : undefined, honeypot);
      navigate('/');
    } catch (err: unknown) {
      const parsed = parseApiError(err, t('common.error'));
      if (parsed.code === 'AUTH_TOTP_REQUIRED') {
        setShowTotp(true);
      } else {
        setError(parsed.message);
      }
    }
  };

  return (
    <Form onSubmit={handleSubmit}>
      {error && (
        <ErrorAlert>
          {error}
        </ErrorAlert>
      )}

      <FieldWrapper>
        <Label htmlFor="email">
          {t('auth.email')}
        </Label>
        <Input
          id="email"
          type="email"
          required
          value={email}
          onChange={(e) => setEmail(e.target.value)}
          autoComplete="email"
        />
      </FieldWrapper>

      <FieldWrapper>
        <Label htmlFor="password">
          {t('auth.password')}
        </Label>
        <Input
          id="password"
          type="password"
          required
          value={password}
          onChange={(e) => setPassword(e.target.value)}
          autoComplete="current-password"
        />
      </FieldWrapper>

      {showTotp && (
        <FieldWrapper>
          <Label htmlFor="totp">
            {t('auth.totpCode')}
          </Label>
          <Input
            id="totp"
            type="text"
            inputMode="numeric"
            required
            value={totpCode}
            onChange={(e) => setTotpCode(e.target.value)}
            autoFocus
          />
        </FieldWrapper>
      )}

      {/* Honeypot — hidden from humans, bots auto-fill it */}
      <Honeypot aria-hidden="true">
        <label htmlFor="username">Username</label>
        <input
          id="username"
          name="username"
          type="text"
          tabIndex={-1}
          autoComplete="off"
          onChange={(e) => setHoneypot(e.target.value)}
        />
      </Honeypot>

      <StyledButton type="submit" disabled={isLoading}>
        {isLoading ? t('common.loading') : t('auth.login')}
      </StyledButton>
    </Form>
  );
}
