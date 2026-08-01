import { useState } from 'react'
import styled from 'styled-components'
import { type AdminUser } from '../../api/user-admin-api'
import { useResetPassword } from '../../hooks/use-users'
import { Button } from '@/shared/components/ui/button'
import { Input } from '@/shared/components/ui/input'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/shared/components/ui/dialog'

const Form = styled.form`
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
`

const FieldGroup = styled.div`
  display: flex;
  flex-direction: column;
`

const Label = styled.label`
  font-size: 11px;
  font-weight: 500;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--color-muted-foreground);
`

const StyledInput = styled(Input)`
  margin-top: 0.25rem;
`

const MismatchError = styled.p`
  font-size: 11px;
  color: var(--color-destructive);
  margin-top: 0.25rem;
`

export function ResetPasswordDialog({ user, open, onOpenChange }: { user: AdminUser | null; open: boolean; onOpenChange: (v: boolean) => void }) {
  const [password, setPassword] = useState('')
  const [confirm, setConfirm] = useState('')
  const resetPassword = useResetPassword()

  if (!user) return null

  const mismatch = confirm.length > 0 && password !== confirm

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    resetPassword.mutate(
      { id: user.id, password },
      {
        onSuccess: () => {
          setPassword('')
          setConfirm('')
          onOpenChange(false)
        },
      },
    )
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent style={{ maxWidth: '28rem' }}>
        <DialogHeader>
          <DialogTitle>Reset Password</DialogTitle>
          <DialogDescription>Set a new password for {user.email}.</DialogDescription>
        </DialogHeader>
        <Form onSubmit={handleSubmit}>
          <FieldGroup>
            <Label>New Password</Label>
            <StyledInput type="password" value={password} onChange={(e) => setPassword(e.target.value)} required minLength={8} />
          </FieldGroup>
          <FieldGroup>
            <Label>Confirm Password</Label>
            <StyledInput type="password" value={confirm} onChange={(e) => setConfirm(e.target.value)} required minLength={8} />
            {mismatch && <MismatchError>Passwords do not match.</MismatchError>}
          </FieldGroup>
          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>Cancel</Button>
            <Button type="submit" disabled={resetPassword.isPending || mismatch}>
              {resetPassword.isPending ? 'Resetting...' : 'Reset Password'}
            </Button>
          </DialogFooter>
        </Form>
      </DialogContent>
    </Dialog>
  )
}
