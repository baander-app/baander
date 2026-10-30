import { useEffect, useRef } from 'react'
import { isAxiosError } from 'axios'
import { useDraft } from '@/shared/drafts/use-draft'
import { captureOwnership, isCurrentOwnership, subscribeOwnership } from '@/shared/live/ownership'
import styled from 'styled-components'
import { useAdminCheck } from '@/features/auth/hooks/use-admin-check'
import { parseApiError } from '@/features/auth/lib/parse-api-error'
import { EMAIL_LANGUAGE_KEY } from '@/features/settings/email-language'
import { type AdminUser } from '../../api/user-admin-api'
import { useChangeUserSetting, useUpdateUser, useUserSettings } from '../../hooks/use-users'
import { UserLanguageField } from './UserLanguageField'
import { initialLanguageSelection, languageChange } from './user-language'
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
              ? `Update name, email and language for ${user.email}.`
              : `Name, email and language of ${user.email}. Only super admins can change them.`}
          </DialogDescription>
        </DialogHeader>
        {/* The form mounts with the dialog content, so each opening starts from the user's current values. */}
        <EditUserForm key={user.publicId} user={user} onDone={() => onOpenChange(false)} />
      </DialogContent>
    </Dialog>
  )
}

function EditUserForm({ user, onDone }: { user: AdminUser; onDone: () => void }) {
  const draft = useDraft({ identity: user.publicId, initialData: { email: user.email, name: user.name } })
  const languageDraft = useDraft<{ selection: string | null }>({ identity: user.publicId, initialData: { selection: null } })
  const latestValues = useRef(draft.values)
  useEffect(() => {
    latestValues.current = draft.values
  }, [draft.values])
  const { isSuperAdmin } = useAdminCheck()
  const wasSuperAdmin = useRef(isSuperAdmin)
  const updateUser = useUpdateUser()
  const settings = useUserSettings(user.publicId)
  const changeSetting = useChangeUserSetting()
  const language = settings.data?.find((setting) => setting.key === EMAIL_LANGUAGE_KEY)
  const saving = updateUser.isPending || changeSetting.isPending
  const { receive, revokeAccess } = draft
  const { receive: receiveLanguage, revokeAccess: revokeLanguage } = languageDraft
  const lastUser = useRef(user)
  const revokedUser = useRef<AdminUser | null>(null)
  const lastLanguage = useRef(language)
  const revokedLanguage = useRef<typeof language>(undefined)
  useEffect(() => subscribeOwnership(() => {
    revokedUser.current = lastUser.current
    revokedLanguage.current = lastLanguage.current
    revokeAccess()
    revokeLanguage()
  }), [revokeAccess, revokeLanguage])
  useEffect(() => {
    lastUser.current = user
    if (isAxiosError(settings.error) && settings.error.response?.status === 404) {
      receive(null)
      return
    }
    if (revokedUser.current !== user && (isSuperAdmin || revokedUser.current === null)) {
      receive({ email: user.email, name: user.name })
    }
  }, [receive, user, isSuperAdmin, settings.error])
  useEffect(() => {
    lastLanguage.current = language
    if (language && !saving && revokedLanguage.current !== language && (isSuperAdmin || revokedUser.current === null)) {
      receiveLanguage({ selection: initialLanguageSelection(language) })
    }
  }, [language, receiveLanguage, saving, isSuperAdmin])
  useEffect(() => {
    if (wasSuperAdmin.current && !isSuperAdmin) {
      revokedUser.current = lastUser.current
      revokedLanguage.current = lastLanguage.current
      revokeAccess()
      revokeLanguage()
    }
    wasSuperAdmin.current = isSuperAdmin
  }, [isSuperAdmin, revokeAccess, revokeLanguage])
  const languageSelection = languageDraft.values.selection
  const selectLanguage = (selection: string) => {
    changeSetting.reset()
    languageDraft.updateField('selection', selection)
  }

  const retryLoad = async () => {
    await settings.refetch()
  }

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!isSuperAdmin || draft.status === 'conflict' || draft.status === 'unavailable'
      || languageDraft.status === 'conflict' || languageDraft.status === 'unavailable') return
    updateUser.reset()
    const stamp = captureOwnership()
    const submission = draft.beginSave()
    const languageSubmission = languageDraft.beginSave()
    const userPayload = {
      id: user.publicId,
      email: submission?.values.email ?? draft.values.email,
      name: submission?.values.name ?? draft.values.name,
    }
    const change = language && languageSubmission?.values.selection
      ? languageChange(user.publicId, language, languageSubmission.values.selection)
      : null
    try {
      if (change) {
        await changeSetting.mutateAsync(change)
        if (!isCurrentOwnership(stamp)) return
      }
      if (languageSubmission) languageDraft.completeSave(languageSubmission)
      await updateUser.mutateAsync(userPayload)
      if (!isCurrentOwnership(stamp)) return
      if (submission) draft.completeSave(submission)
    } catch {
      if (!isCurrentOwnership(stamp)) return
      if (submission) draft.failSave(submission)
      if (languageSubmission) languageDraft.failSave(languageSubmission)
      return
    }
    if (latestValues.current.email === userPayload.email && latestValues.current.name === userPayload.name) {
      onDone()
    }
  }

  return (
    <Form onSubmit={handleSubmit}>
      <FieldGroup>
        <Label htmlFor="edituserdialog-email">Email</Label>
        <StyledInput id="edituserdialog-email" type="email" value={draft.values.email ?? ''} onChange={(e) => draft.updateField('email', e.target.value)} required readOnly={!isSuperAdmin} />
      </FieldGroup>
      <FieldGroup>
        <Label htmlFor="edituserdialog-name">Name</Label>
        <StyledInput id="edituserdialog-name" value={draft.values.name ?? ''} onChange={(e) => draft.updateField('name', e.target.value)} required readOnly={!isSuperAdmin} />
      </FieldGroup>
      <UserLanguageField
        setting={languageDraft.status === 'unavailable' ? undefined : language}
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
      {(draft.status === 'conflict' || languageDraft.status === 'conflict') && <div role="alert">
        Changed on the server.
        <Button type="button" onClick={() => {
          draft.resolveConflict('local')
          languageDraft.resolveConflict('local')
        }}>Keep my changes</Button>
        <Button type="button" onClick={() => {
          draft.resolveConflict('server')
          languageDraft.resolveConflict('server')
        }}>Use server values</Button>
      </div>}
      <DialogFooter>
        <Button type="button" variant="outline" onClick={onDone}>{isSuperAdmin ? 'Cancel' : 'Close'}</Button>
        {isSuperAdmin && (
          <Button type="submit" disabled={saving || draft.status === 'conflict' || draft.status === 'unavailable' || languageDraft.status === 'conflict'}>
            {saving ? 'Saving...' : 'Save'}
          </Button>
        )}
      </DialogFooter>
    </Form>
  )
}
