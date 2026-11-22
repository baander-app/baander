import { type ReactNode, useState } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { useAuthStore } from '@/features/auth/stores/auth-store'
import { getGetAuthMeQueryKey, putAuthMeEmail } from '@/shared/api-client/gen/endpoints'
import { useTranslation } from '@/shared/i18n'
import { Input } from '@/shared/components/ui/input'
import { StepUpDialog, type StepUpCredentials } from './StepUpDialog'
import { FieldLabel } from './settings-form-styles'

interface ChangeEmailDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  children: ReactNode
}

/** Asks for a new address and the step-up prompt; the account keeps its address until the link sent to the new one is opened. */
export function ChangeEmailDialog({ open, onOpenChange, children }: ChangeEmailDialogProps) {
  const { t } = useTranslation()
  const user = useAuthStore((s) => s.user)
  const [email, setEmail] = useState(user?.email ?? '')
  const queryClient = useQueryClient()

  const changeEmail = async (stepUp: StepUpCredentials) => {
    await putAuthMeEmail({ email, ...stepUp })
    // The change waits for the link sent to the new address. Reading the profile again shows it
    // as pending; the profile's reader copies it into the store.
    await queryClient.invalidateQueries({ queryKey: getGetAuthMeQueryKey() })

    return true
  }

  return (
    <StepUpDialog
      open={open}
      onOpenChange={onOpenChange}
      trigger={children}
      title="Change email"
      description={t('auth.emailVerification.changeEmailDescription')}
      submitLabel="Save"
      fallbackError="Failed to change email."
      onConfirm={changeEmail}
    >
      <div>
        <FieldLabel htmlFor="new-email">
          New email
        </FieldLabel>
        <Input
          id="new-email"
          type="email"
          required
          value={email}
          onChange={(e) => setEmail(e.target.value)}
          autoComplete="email"
          autoFocus
        />
      </div>
    </StepUpDialog>
  )
}
