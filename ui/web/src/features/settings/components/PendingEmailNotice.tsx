import styled from 'styled-components'
import { ResendVerification } from '@/features/auth/components/ResendVerification'
import { useTranslation } from '@/shared/i18n'
import { NoticeMessage, NoticePanel, NoticeStatus } from './verification-notice-styles'

/** The hint names the new address, which can be longer than the row is wide. */
const AddressMessage = styled(NoticeMessage)`
  overflow-wrap: anywhere;
`

/** Shown under the email row while an email change waits for the link sent to the new address. */
export function PendingEmailNotice({ email }: { email: string }) {
  const { t } = useTranslation()

  return (
    <NoticePanel>
      <NoticeStatus>
        {t('auth.emailVerification.pendingChange')}
      </NoticeStatus>
      <AddressMessage>
        {t('auth.emailVerification.pendingChangeHint', { email })}
      </AddressMessage>
      <ResendVerification compact />
    </NoticePanel>
  )
}
