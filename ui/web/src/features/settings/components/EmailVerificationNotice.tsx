import styled from 'styled-components'
import { ResendVerification } from '@/features/auth/components/ResendVerification'
import { useTranslation } from '@/shared/i18n'
import { textRole } from '@/shared/theme'

const Panel = styled.div`
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  border-radius: var(--radius-md);
  background-color: var(--color-secondary);
  padding: 0.5rem 0.75rem;
`

const Status = styled.p`
  ${textRole('label')}
  color: var(--color-muted-foreground);
`

const Message = styled.p`
  ${textRole('body')}
  color: var(--color-muted-foreground);
`

/** Shown under the email row while the signed-in user's address is unverified. */
export function EmailVerificationNotice() {
  const { t } = useTranslation()

  return (
    <Panel>
      <Status>
        {t('auth.emailVerification.notVerified')}
      </Status>
      <Message>
        {t('auth.emailVerification.notVerifiedHint')}
      </Message>
      <ResendVerification compact />
    </Panel>
  )
}
