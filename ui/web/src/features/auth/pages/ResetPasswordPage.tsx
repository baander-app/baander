import { useEffect, useState } from 'react'
import { useLocation, useNavigate } from 'react-router-dom'
import { useTranslation } from '@/shared/i18n'
import { AuthPageLayout } from '../components/AuthPageLayout'
import { InvalidResetLink, ResetPasswordForm } from '../components/ResetPasswordForm'
import { readResetToken } from '../lib/reset-token'

/**
 * Opened from the reset email. The token arrives in the URL fragment; the page keeps it in
 * memory and removes it from the address bar and history entry.
 *
 * The page is open to signed-in users too: a reset ends every session, including theirs.
 */
export function ResetPasswordPage() {
  const { t } = useTranslation()
  const location = useLocation()
  const navigate = useNavigate()
  const [token] = useState(() => readResetToken(location.hash))

  useEffect(() => {
    if (location.hash === '') return

    navigate({ pathname: location.pathname, search: location.search }, { replace: true })
  }, [location.hash, location.pathname, location.search, navigate])

  return (
    <AuthPageLayout title={t('auth.passwordReset.resetTitle')}>
      {token === null ? <InvalidResetLink /> : <ResetPasswordForm token={token} />}
    </AuthPageLayout>
  )
}
