import { type FormEvent, useState } from 'react'
import styled from 'styled-components'
import { useAuthStore } from '@/features/auth/stores/auth-store'
import { Button } from '@/shared/components/ui/button'
import { Input } from '@/shared/components/ui/input'
import { Card, CardContent } from '@/shared/components/ui/card'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/shared/components/ui/dialog'
import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'
import { Loader2 } from 'lucide-react'
import { useNavigate } from 'react-router-dom'

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
  font-size: 11px;
  font-weight: 500;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--color-muted-foreground);
`

const Value = styled.p`
  font-size: 0.875rem;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
`

const ValueMuted = styled.p`
  font-size: 0.875rem;
  color: var(--color-muted-foreground);
`

const SpinningIcon = styled(Loader2)`
  animation: spin 1s linear infinite;

  @keyframes spin {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
  }
`

const SpinningIconWithMargin = styled(Loader2)`
  margin-right: 0.25rem;
  animation: spin 1s linear infinite;

  @keyframes spin {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
  }
`

const Form = styled.form`
  display: flex;
  flex-direction: column;
  gap: 1rem;
`

const ErrorBox = styled.div`
  border-radius: var(--radius-md);
  background-color: color-mix(in srgb, var(--color-destructive) 10%, transparent);
  padding: 0.75rem;
  font-size: 0.875rem;
  color: var(--color-destructive);
`

const FieldLabel = styled.label`
  display: block;
  margin-bottom: 0.375rem;
  font-size: 0.75rem;
  font-weight: 500;
  color: var(--color-muted-foreground);
`

const Heading = styled.p`
  font-size: 0.875rem;
  font-weight: 500;
`

const Subheading = styled.p`
  font-size: 0.75rem;
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
            <Button size="xs" variant="outline" onClick={() => setEmailDialogOpen(true)}>
              Change
            </Button>
          </Row>

          {/* Password row */}
          <Row>
            <RowContent>
              <RowText>
                <Label>Password</Label>
                <ValueMuted>••••••••</ValueMuted>
              </RowText>
            </RowContent>
            <Button size="xs" variant="outline" onClick={() => setPasswordDialogOpen(true)}>
              Change
            </Button>
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

      <ChangeEmailDialog open={emailDialogOpen} onOpenChange={setEmailDialogOpen} />
      <ChangePasswordDialog open={passwordDialogOpen} onOpenChange={setPasswordDialogOpen} />
    </>
  )
}

function ChangeEmailDialog({ open, onOpenChange }: { open: boolean; onOpenChange: (open: boolean) => void }) {
  const user = useAuthStore((s) => s.user)
  const [email, setEmail] = useState(user?.email ?? '')
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const handleSubmit = async (e: FormEvent) => {
    e.preventDefault()
    setError(null)
    setLoading(true)

    try {
      await AXIOS_INSTANCE.put('/api/auth/me/email', { email })
      // Update the local user state
      useAuthStore.setState((s) => ({
        user: s.user ? { ...s.user, email } : null,
      }))
      onOpenChange(false)
    } catch (err: unknown) {
      const msg = (err as { response?: { data?: { message?: string } } })?.response?.data?.message
      setError(msg ?? 'Failed to change email.')
    } finally {
      setLoading(false)
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Change email</DialogTitle>
          <DialogDescription>
            Enter your new email address. You may need to verify it.
          </DialogDescription>
        </DialogHeader>
        <Form onSubmit={handleSubmit}>
          {error && (
            <ErrorBox>{error}</ErrorBox>
          )}
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
          <DialogFooter>
            <Button variant="outline" type="button" onClick={() => onOpenChange(false)}>
              Cancel
            </Button>
            <Button type="submit" disabled={loading}>
              {loading ? <SpinningIconWithMargin size={14} /> : null}
              Save
            </Button>
          </DialogFooter>
        </Form>
      </DialogContent>
    </Dialog>
  )
}

function ChangePasswordDialog({ open, onOpenChange }: { open: boolean; onOpenChange: (open: boolean) => void }) {
  const [currentPassword, setCurrentPassword] = useState('')
  const [newPassword, setNewPassword] = useState('')
  const [confirmPassword, setConfirmPassword] = useState('')
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const handleSubmit = async (e: FormEvent) => {
    e.preventDefault()
    setError(null)

    if (newPassword !== confirmPassword) {
      setError('Passwords do not match.')
      return
    }

    if (newPassword.length < 8) {
      setError('Password must be at least 8 characters.')
      return
    }

    setLoading(true)

    try {
      await AXIOS_INSTANCE.put('/api/auth/me/password', {
        currentPassword,
        newPassword,
      })
      setCurrentPassword('')
      setNewPassword('')
      setConfirmPassword('')
      onOpenChange(false)
    } catch (err: unknown) {
      const msg = (err as { response?: { data?: { message?: string } } })?.response?.data?.message
      setError(msg ?? 'Failed to change password.')
    } finally {
      setLoading(false)
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Change password</DialogTitle>
          <DialogDescription>
            Enter your current password and choose a new one.
          </DialogDescription>
        </DialogHeader>
        <Form onSubmit={handleSubmit}>
          {error && (
            <ErrorBox>{error}</ErrorBox>
          )}
          <div>
            <FieldLabel htmlFor="current-password">
              Current password
            </FieldLabel>
            <Input
              id="current-password"
              type="password"
              required
              value={currentPassword}
              onChange={(e) => setCurrentPassword(e.target.value)}
              autoComplete="current-password"
              autoFocus
            />
          </div>
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
            />
          </div>
          <div>
            <FieldLabel htmlFor="confirm-password">
              Confirm new password
            </FieldLabel>
            <Input
              id="confirm-password"
              type="password"
              required
              value={confirmPassword}
              onChange={(e) => setConfirmPassword(e.target.value)}
              autoComplete="new-password"
              minLength={8}
            />
          </div>
          <DialogFooter>
            <Button variant="outline" type="button" onClick={() => onOpenChange(false)}>
              Cancel
            </Button>
            <Button type="submit" disabled={loading}>
              {loading ? <SpinningIconWithMargin size={14} /> : null}
              Save
            </Button>
          </DialogFooter>
        </Form>
      </DialogContent>
    </Dialog>
  )
}
