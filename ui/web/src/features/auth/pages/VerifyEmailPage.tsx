import { useTranslation } from '@/shared/i18n'
import { AuthPageLayout } from '../components/AuthPageLayout'
import { VerifyEmail } from '../components/VerifyEmail'
import { useFragmentToken } from '../hooks/use-fragment-token'

/**
 * Opened from the verification email. The token arrives in the URL fragment; the page keeps it
 * in memory, removes it from the address bar and history entry, and redeems it once.
 *
 * The page works signed in or signed out: the token alone identifies the address.
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
