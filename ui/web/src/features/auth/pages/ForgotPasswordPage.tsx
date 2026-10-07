import { Navigate } from 'react-router-dom'
import { useTranslation } from '@/shared/i18n'
import { AuthPageLayout } from '../components/AuthPageLayout'
import { ForgotPasswordForm } from '../components/ForgotPasswordForm'
import { useAuthStore } from '../stores/auth-store'

export function ForgotPasswordPage() {
  const { t } = useTranslation()
  const isAuthenticated = useAuthStore((s) => s.isAuthenticated)

  if (isAuthenticated) {
    return <Navigate to="/" replace />
  }

  return (
    <AuthPageLayout title={t('auth.passwordReset.requestTitle')}>
      <ForgotPasswordForm />
    </AuthPageLayout>
  )
}
