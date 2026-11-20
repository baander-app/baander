import { ResendVerification } from '@/features/auth/components/ResendVerification'
import { useTranslation } from '@/shared/i18n'
import { NoticeMessage, NoticePanel, NoticeStatus } from './verification-notice-styles'

/** Shown under the email row while the signed-in user's address is unverified. */
export function EmailVerificationNotice() {
  const { t } = useTranslation()

  return (
    <NoticePanel>
      <NoticeStatus>
        {t('auth.emailVerification.notVerified')}
      </NoticeStatus>
      <NoticeMessage>
        {t('auth.emailVerification.notVerifiedHint')}
      </NoticeMessage>
      <ResendVerification compact />
    </NoticePanel>
  )
}
