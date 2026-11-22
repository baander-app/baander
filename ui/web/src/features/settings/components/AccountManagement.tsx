import { type ReactNode, useEffect, useRef, useState } from 'react'
import styled from 'styled-components'
import { useAuthStore } from '@/features/auth/stores/auth-store'
import { useCurrentUserRefresh } from '@/features/auth/hooks/use-current-user-refresh'
import { putAuthMePassword } from '@/shared/api-client/gen/endpoints'
import { useTranslation } from '@/shared/i18n'
import { textRole } from '@/shared/theme'
import { Button } from '@/shared/components/ui/button'
import { Input } from '@/shared/components/ui/input'
import { Card, CardContent } from '@/shared/components/ui/card'
import { Loader2 } from 'lucide-react'
import { useNavigate } from 'react-router-dom'
import { ChangeEmailDialog } from './ChangeEmailDialog'
import { EmailVerificationNotice } from './EmailVerificationNotice'
import { PendingEmailNotice } from './PendingEmailNotice'
import { StepUpDialog, type StepUpCredentials } from './StepUpDialog'
import { FieldLabel } from './settings-form-styles'

const CardContentStyled = styled(CardContent)`
  display: flex;
  flex-direction: column;
  gap: 1rem;
`

const Row = styled.div`
  display: flex;
  align-items: center;
  justify-content: space-between;
  border-radius: var(--radius-md);
  background-color: var(--color-secondary);
  padding: 0.5rem 0.75rem;
`

const RowContent = styled.div`
  display: flex;
  align-items: center;
  gap: 0.5rem;
  min-width: 0;
`

const RowText = styled.div`
  min-width: 0;
`

const Label = styled.p`
  ${textRole('label')}
  color: var(--color-muted-foreground);
`

const Value = styled.p`
  ${textRole('body')}
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
`

const ValueMuted = styled.p`
  ${textRole('body')}
  color: var(--color-muted-foreground);
`

const SpinningIcon = styled(Loader2)`
  animation: spin 1s linear infinite;

  @keyframes spin {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
  }
`

const Heading = styled.p`
  ${textRole('body')}
  font-weight: 500;
`

const Subheading = styled.p`
  ${textRole('caption')}
  color: var(--color-muted-foreground);
`

const HeaderGroup = styled.div`
  display: flex;
  align-items: center;
  justify-content: space-between;
`

export function AccountManagement() {
  const user = useAuthStore((s) => s.user)
  const logout = useAuthStore((s) => s.logout)
  const navigate = useNavigate()

  const [emailDialogOpen, setEmailDialogOpen] = useState(false)
  const [passwordDialogOpen, setPasswordDialogOpen] = useState(false)
  const [loggingOut, setLoggingOut] = useState(false)

  useCurrentUserRefresh()

  const handleLogout = async () => {
    setLoggingOut(true)
    try {
      await logout()
      navigate('/login')
    } finally {
      setLoggingOut(false)
    }
  }

  return (
    <>
      <Card size="sm">
        <CardContentStyled>
          <HeaderGroup>
            <div>
              <Heading>Account</Heading>
              <Subheading>Manage your email, password, and session</Subheading>
            </div>
          </HeaderGroup>

          {/* Email row */}
          <Row>
            <RowContent>
              <RowText>
                <Label>Email</Label>
                <Value>{user?.email}</Value>
              </RowText>
            </RowContent>
            <ChangeEmailDialog open={emailDialogOpen} onOpenChange={setEmailDialogOpen}>
              <Button size="xs" variant="outline">Change</Button>
            </ChangeEmailDialog>
          </Row>

          {/* A resend goes to the pending address while a change waits, so only one notice offers it. */}
          {user?.pendingEmail
            ? <PendingEmailNotice email={user.pendingEmail} />
            : user?.emailVerifiedAt === null && <EmailVerificationNotice />}

          {/* Password row */}
          <Row>
            <RowContent>
              <RowText>
                <Label>Password</Label>
                <ValueMuted>••••••••</ValueMuted>
              </RowText>
            </RowContent>
            <ChangePasswordDialog open={passwordDialogOpen} onOpenChange={setPasswordDialogOpen}>
              <Button size="xs" variant="outline">Change</Button>
            </ChangePasswordDialog>
          </Row>

          {/* Logout */}
          <Row>
            <RowContent>
              <RowText>
                <Label>Session</Label>
                <ValueMuted>Sign out of your account</ValueMuted>
              </RowText>
            </RowContent>
            <Button size="xs" variant="destructive" onClick={handleLogout} disabled={loggingOut}>
              {loggingOut ? <SpinningIcon size={14} /> : 'Sign out'}
            </Button>
          </Row>
        </CardContentStyled>
      </Card>

    </>
  )
}

/**
 * The new password and its confirmation, then the step-up prompt. A confirmation that differs
 * blocks the submit in the browser, so the password and code typed below are kept.
 */
function ChangePasswordDialog({ open, onOpenChange, children }: { open: boolean; onOpenChange: (open: boolean) => void; children: ReactNode }) {
  const { t } = useTranslation()
  const [newPassword, setNewPassword] = useState('')
  const [confirmPassword, setConfirmPassword] = useState('')
  const confirmInput = useRef<HTMLInputElement>(null)

  useEffect(() => {
    confirmInput.current?.setCustomValidity(confirmPassword === newPassword ? '' : t('auth.passwordMismatch'))
  }, [newPassword, confirmPassword, t])

  const handleOpenChange = (next: boolean) => {
    if (!next) {
      setNewPassword('')
      setConfirmPassword('')
    }
    onOpenChange(next)
  }

  const changePassword = async (stepUp: StepUpCredentials) => {
    await putAuthMePassword({ newPassword, ...stepUp })

    return true
  }

  return (
    <StepUpDialog
      open={open}
      onOpenChange={handleOpenChange}
      trigger={children}
      title="Change password"
      description={<>Choose a new password. {t('auth.stepUp.signsOutOtherSessions')}</>}
      submitLabel="Save"
      fallbackError="Failed to change password."
      onConfirm={changePassword}
    >
      <div>
        <FieldLabel htmlFor="new-password">
          New password
        </FieldLabel>
        <Input
          id="new-password"
          type="password"
          required
          value={newPassword}
          onChange={(e) => setNewPassword(e.target.value)}
          autoComplete="new-password"
          minLength={8}
          maxLength={255}
          autoFocus
        />
      </div>
      <div>
        <FieldLabel htmlFor="confirm-password">
          Confirm new password
        </FieldLabel>
        <Input
          ref={confirmInput}
          id="confirm-password"
          type="password"
          required
          value={confirmPassword}
          onChange={(e) => setConfirmPassword(e.target.value)}
          autoComplete="new-password"
        />
      </div>
    </StepUpDialog>
  )
}
