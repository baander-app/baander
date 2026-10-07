import { useTranslation } from '@/shared/i18n'
import { AuthPageLayout } from '../components/AuthPageLayout'
import { InvalidResetLink, ResetPasswordForm } from '../components/ResetPasswordForm'
import { useFragmentToken } from '../hooks/use-fragment-token'

/**
 * Opened from the reset email. The token arrives in the URL fragment; the page keeps it in
 * memory and removes it from the address bar and history entry.
 *
 * The page is open to signed-in users too: a reset ends every session, including theirs.
 */
export function ResetPasswordPage() {
  const { t } = useTranslation()
  const token = useFragmentToken()

  return (
    <AuthPageLayout title={t('auth.passwordReset.resetTitle')}>
      {token === null ? <InvalidResetLink /> : <ResetPasswordForm token={token} />}
    </AuthPageLayout>
  )
}
