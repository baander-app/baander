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
  overflow-wrap: anywhere;
`

/** Shown under the email row while an email change waits for the link sent to the new address. */
export function PendingEmailNotice({ email }: { email: string }) {
  const { t } = useTranslation()

  return (
    <Panel>
      <Status>
        {t('auth.emailVerification.pendingChange')}
      </Status>
      <Message>
        {t('auth.emailVerification.pendingChangeHint', { email })}
      </Message>
      <ResendVerification compact />
    </Panel>
  )
}
