import { useLocation } from 'react-router-dom'
import { useTranslation } from '@/shared/i18n'
import { AuthPageLayout } from '../components/AuthPageLayout'
import { OAuthConsent } from '../components/OAuthConsent'

/**
 * The authorization endpoint's page (`/oauth/authorize`), where clients send the browser. The
 * route requires sign-in; the sign-in guard keeps the full query through the login page, and
 * the query reaches the API unchanged.
 */
export function OAuthConsentPage() {
  const { t } = useTranslation()
  const { search } = useLocation()

  return (
    <AuthPageLayout title={t('auth.consent.title')}>
      <OAuthConsent key={search} query={search.replace(/^\?/, '')} />
    </AuthPageLayout>
  )
}
