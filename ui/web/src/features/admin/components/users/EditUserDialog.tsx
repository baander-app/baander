import { useState } from 'react'
import styled from 'styled-components'
import { useAdminCheck } from '@/features/auth/hooks/use-admin-check'
import { parseApiError } from '@/features/auth/lib/parse-api-error'
import { type AdminUser } from '../../api/user-admin-api'
import { useChangeUserSetting, useUpdateUser, useUserSettings } from '../../hooks/use-users'
import { UserLanguageField } from './UserLanguageField'
import { LANGUAGE_KEY, languageChange } from './user-language'
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

const ErrorText = styled.p`
  font-size: 0.6875rem;
  color: var(--color-destructive);
`

interface EditUserDialogProps {
  user: AdminUser | null
  open: boolean
  onOpenChange: (open: boolean) => void
}

export function EditUserDialog({ user, open, onOpenChange }: EditUserDialogProps) {
  const { isSuperAdmin } = useAdminCheck()
  if (!user) return null

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent style={{ maxWidth: '28rem' }}>
        <DialogHeader>
          <DialogTitle>{isSuperAdmin ? 'Edit User' : 'User Details'}</DialogTitle>
          <DialogDescription>
            {isSuperAdmin
              ? `Update name, email and email language for ${user.email}.`
              : `Name, email and email language of ${user.email}. Only super admins can change them.`}
          </DialogDescription>
        </DialogHeader>
        {/* The form mounts with the dialog content, so each opening starts from the user's current values. */}
        <EditUserForm key={user.id} user={user} onDone={() => onOpenChange(false)} />
      </DialogContent>
    </Dialog>
  )
}

function EditUserForm({ user, onDone }: { user: AdminUser; onDone: () => void }) {
  const [email, setEmail] = useState(user.email)
  const [name, setName] = useState(user.name)
  const [languageSelection, setLanguageSelection] = useState<string | null>(null)
  const { isSuperAdmin } = useAdminCheck()
  const updateUser = useUpdateUser()
  const settings = useUserSettings(user.id)
  const changeSetting = useChangeUserSetting()
  const language = settings.data?.find((setting) => setting.key === LANGUAGE_KEY)
  const saving = updateUser.isPending || changeSetting.isPending

  const selectLanguage = (selection: string) => {
    changeSetting.reset()
    setLanguageSelection(selection)
  }

  const retryLoad = async () => {
    await settings.refetch()
  }

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    updateUser.reset()
    const change = isSuperAdmin && language && languageSelection !== null
      ? languageChange(user.id, language, languageSelection)
      : null

    try {
      // The language goes first: if it is rejected, nothing else has been saved yet.
      if (change) {
        await changeSetting.mutateAsync(change)
        setLanguageSelection(null)
      }
      await updateUser.mutateAsync({ id: user.id, email, name })
    } catch {
      // Both mutations keep their error, which the form shows next to the field that failed.
      return
    }
    onDone()
  }

  return (
    <Form onSubmit={handleSubmit}>
      <FieldGroup>
        <Label>Email</Label>
        <StyledInput type="email" value={email} onChange={(e) => setEmail(e.target.value)} required readOnly={!isSuperAdmin} />
      </FieldGroup>
      <FieldGroup>
        <Label>Name</Label>
        <StyledInput value={name} onChange={(e) => setName(e.target.value)} required readOnly={!isSuperAdmin} />
      </FieldGroup>
      <UserLanguageField
        setting={language}
        loading={settings.isPending}
        loadFailed={settings.isError}
        onRetry={retryLoad}
        selection={languageSelection}
        onSelect={selectLanguage}
        canEdit={isSuperAdmin}
        saveError={changeSetting.error}
        disabled={saving}
      />
      {updateUser.error && (
        <ErrorText role="alert">
          {`Could not save: ${parseApiError(updateUser.error, updateUser.error.message).message}`}
        </ErrorText>
      )}
      <DialogFooter>
        <Button type="button" variant="outline" onClick={onDone}>{isSuperAdmin ? 'Cancel' : 'Close'}</Button>
        {isSuperAdmin && (
          <Button type="submit" disabled={saving}>
            {saving ? 'Saving...' : 'Save'}
          </Button>
        )}
      </DialogFooter>
    </Form>
  )
}
