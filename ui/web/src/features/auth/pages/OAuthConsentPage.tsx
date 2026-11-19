import { useLocation } from 'react-router-dom'
import { useTranslation } from '@/shared/i18n'
import { AuthPageLayout } from '../components/AuthPageLayout'
import { OAuthConsent } from '../components/OAuthConsent'

/**
 * The authorization endpoint's page (`/oauth/authorize`), where clients send the browser after
 * pushing their authorization request. The URL names that request by `client_id` and
 * `request_uri`. The route requires sign-in; the sign-in guard keeps the query through the
 * login page, and the consent step sends only those two parameters to the API.
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
