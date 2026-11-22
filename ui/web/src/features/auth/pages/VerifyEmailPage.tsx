import { useTranslation } from '@/shared/i18n'
import { AuthPageLayout } from '../components/AuthPageLayout'
import { VerifyEmail } from '../components/VerifyEmail'
import { useFragmentToken } from '../hooks/use-fragment-token'

/**
 * Opened from the verification email. The token arrives in the URL fragment; the page keeps it
 * in memory, removes it from the address bar and history entry, and redeems it once.
 *
 * The route is public so the page can explain itself, but only a signed-in session of the
 * account the link was sent for redeems the token. Signed out, the page asks the user to sign
 * in, and sign-in returns here with the token.
 */
export function VerifyEmailPage() {
  const { t } = useTranslation()
  const token = useFragmentToken()

  return (
    <AuthPageLayout title={t('auth.emailVerification.title')}>
      <VerifyEmail token={token} />
    </AuthPageLayout>
  )
}
