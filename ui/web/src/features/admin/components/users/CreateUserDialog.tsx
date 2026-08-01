import { useState } from 'react'
import styled from 'styled-components'
import { useCreateUser } from '../../hooks/use-users'
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
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/shared/components/ui/select'

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

export function CreateUserDialog({ open, onOpenChange }: { open: boolean; onOpenChange: (v: boolean) => void }) {
  const [email, setEmail] = useState('')
  const [name, setName] = useState('')
  const [password, setPassword] = useState('')
  const [role, setRole] = useState('ROLE_USER')
  const createUser = useCreateUser()

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    createUser.mutate(
      { email, name, password, roles: [role] },
      {
        onSuccess: () => {
          setEmail('')
          setName('')
          setPassword('')
          setRole('ROLE_USER')
          onOpenChange(false)
        },
      },
    )
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent style={{ maxWidth: '28rem' }}>
        <DialogHeader>
          <DialogTitle>Create User</DialogTitle>
          <DialogDescription>Add a new user account.</DialogDescription>
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
          <FieldGroup>
            <Label>Password</Label>
            <StyledInput type="password" value={password} onChange={(e) => setPassword(e.target.value)} required minLength={8} />
          </FieldGroup>
          <FieldGroup>
            <Label>Role</Label>
            <Select value={role} onValueChange={setRole}>
              <SelectTrigger style={{ marginTop: '0.25rem' }}>
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="ROLE_USER">User</SelectItem>
                <SelectItem value="ROLE_ADMIN">Admin</SelectItem>
                <SelectItem value="ROLE_SUPER_ADMIN">Super Admin</SelectItem>
              </SelectContent>
            </Select>
          </FieldGroup>
          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
              Cancel
            </Button>
            <Button type="submit" disabled={createUser.isPending}>
              {createUser.isPending ? 'Creating...' : 'Create'}
            </Button>
          </DialogFooter>
        </Form>
      </DialogContent>
    </Dialog>
  )
}
