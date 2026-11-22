import { type ReactNode, useEffect, useState } from 'react'
import { Navigate } from 'react-router-dom'
import { getAuthRegistration } from '@/shared/api-client/gen/endpoints'
import { useTranslation } from '@/shared/i18n'
import { AuthPageLayout } from '../components/AuthPageLayout'
import { FooterText, Form, Notice, TextLink } from '../components/auth-form-styles'
import { RegisterForm } from '../components/RegisterForm'
import { useAuthStore } from '../stores/auth-store'

/**
 * The sign-up page. It offers the form only while the server says self-registration is open,
 * and a registration the server refuses as closed switches it to the closed state. When the
 * state cannot be read, the form is offered and the server decides on submit.
 *
 * The state is a direct request, not a query: queries are admitted by the live coordinator,
 * and a signed-out page runs none.
 */
export function RegisterPage() {
  const { t } = useTranslation()
  const isAuthenticated = useAuthStore((s) => s.isAuthenticated)
  const [registrationOpen, setRegistrationOpen] = useState<boolean | null>(null)
  const [refusedAsClosed, setRefusedAsClosed] = useState(false)

  useEffect(() => {
    if (isAuthenticated) return
    const request = new AbortController()
    getAuthRegistration({ signal: request.signal })
      .then((response) => setRegistrationOpen(response.data.open))
      .catch(() => {
        if (!request.signal.aborted) setRegistrationOpen(true)
      })
    return () => request.abort()
  }, [isAuthenticated])

  if (isAuthenticated) {
    return <Navigate to="/" replace />
  }

  let content: ReactNode
  if (refusedAsClosed || registrationOpen === false) {
    content = (
      <Form as="div">
        <Notice role="status">
          {t('auth.registration.closed')}
        </Notice>
        <FooterText>
          <TextLink to="/login">
            {t('auth.registration.backToLogin')}
          </TextLink>
        </FooterText>
      </Form>
    )
  } else if (registrationOpen === null) {
    content = (
      <Form as="div">
        <Notice role="status">
          {t('common.loading')}
        </Notice>
      </Form>
    )
  } else {
    content = <RegisterForm onRegistrationClosed={() => setRefusedAsClosed(true)} />
  }

  return (
    <AuthPageLayout title={t('auth.registration.title')}>
      {content}
    </AuthPageLayout>
  )
}
