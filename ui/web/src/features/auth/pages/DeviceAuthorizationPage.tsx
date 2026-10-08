import { useSearchParams } from 'react-router-dom'
import { useTranslation } from '@/shared/i18n'
import { AuthPageLayout } from '../components/AuthPageLayout'
import { DeviceAuthorization } from '../components/DeviceAuthorization'

/**
 * The device flow's verification URI (`/device`, or `/device?user_code=` from the complete
 * URI). The route requires sign-in; the sign-in guard keeps the code through the login page.
 */
export function DeviceAuthorizationPage() {
  const { t } = useTranslation()
  const [searchParams] = useSearchParams()

  return (
    <AuthPageLayout title={t('auth.device.title')}>
      <DeviceAuthorization initialCode={searchParams.get('user_code') ?? ''} />
    </AuthPageLayout>
  )
}
