import { useState } from 'react'
import styled from 'styled-components'
import { type AdminUser } from '../../api/user-admin-api'
import { useUpdateUser } from '../../hooks/use-users'
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

export function EditUserDialog({ user, open, onOpenChange }: { user: AdminUser | null; open: boolean; onOpenChange: (v: boolean) => void }) {
  const [email, setEmail] = useState('')
  const [name, setName] = useState('')
  const updateUser = useUpdateUser()

  const handleOpen = (val: boolean) => {
    if (val && user) {
      setEmail(user.email)
      setName(user.name)
    }
    onOpenChange(val)
  }

  if (!user) return null

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    updateUser.mutate(
      { id: user.id, email, name },
      { onSuccess: () => onOpenChange(false) },
    )
  }

  return (
    <Dialog open={open} onOpenChange={handleOpen}>
      <DialogContent style={{ maxWidth: '28rem' }}>
        <DialogHeader>
          <DialogTitle>Edit User</DialogTitle>
          <DialogDescription>Update name and email for {user.email}.</DialogDescription>
        </DialogHeader>
        <Form onSubmit={handleSubmit}>
          <FieldGroup>
            <Label>Email</Label>
            <StyledInput type="email" value={email} onChange={(e) => setEmail(e.target.value)} required />
          </FieldGroup>
          <FieldGroup>
            <Label>Name</Label>
            <StyledInput value={name} onChange={(e) => setName(e.target.value)} required />
          </FieldGroup>
          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>Cancel</Button>
            <Button type="submit" disabled={updateUser.isPending}>
              {updateUser.isPending ? 'Saving...' : 'Save'}
            </Button>
          </DialogFooter>
        </Form>
      </DialogContent>
    </Dialog>
  )
}
