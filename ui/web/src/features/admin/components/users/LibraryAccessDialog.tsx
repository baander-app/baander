import { useEffect, useRef, useState } from 'react'
import { isAxiosError } from 'axios'
import { useDraft } from '@/shared/drafts/use-draft'
import { captureOwnership, isCurrentOwnership, subscribeOwnership } from '@/shared/live/ownership'
import { useAdminMutationTools } from '../../hooks/use-admin-mutation'
import styled from 'styled-components'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { userAdminApi, type AdminUser, type AdminUserLibraryAccess } from '../../api/user-admin-api'
import { Button } from '@/shared/components/ui/button'
import { Skeleton } from '@/shared/components/ui/skeleton'
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

const LibraryList = styled.div`
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
`

const LibraryLabel = styled.label`
  display: flex;
  align-items: center;
  gap: 0.5rem;
  cursor: pointer;
`

const Checkbox = styled.input.attrs({ type: 'checkbox' })`
  border-radius: 0.25rem;
  border-color: var(--color-border);
`

const LibraryName = styled.span`
  font-size: 0.875rem;
`

const MutedText = styled.p`
  font-size: 0.875rem;
  color: var(--color-muted-foreground);
`

const ErrorText = styled.p`
  font-size: 0.6875rem;
  color: var(--color-destructive);
`

const ErrorRow = styled.div`
  display: flex;
  align-items: center;
  gap: 0.5rem;
`

const SkeletonLine = styled(Skeleton)`
  height: 1.25rem;
  background: var(--color-muted);
`

interface LibraryAccessDialogProps {
  user: AdminUser | null
  open: boolean
  onOpenChange: (open: boolean) => void
  /** Gets focus back when the dialog closes; the dialog is opened from a menu, not a dialog trigger. */
  returnFocusTo?: HTMLElement | null
}

interface AccessChange {
  library: AdminUserLibraryAccess
  granted: boolean
}

function libraryAccessKey(userId: string) {
  return ['admin-user-libraries', userId]
}

/** Grants and revokes a user's access to each library. */
export function LibraryAccessDialog({ user, open, onOpenChange, returnFocusTo }: LibraryAccessDialogProps) {
  if (!user) return null

  const handleCloseAutoFocus = (event: Event) => {
    if (!returnFocusTo) return
    event.preventDefault()
    returnFocusTo.focus()
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent style={{ maxWidth: '28rem' }} onCloseAutoFocus={handleCloseAutoFocus}>
        <DialogHeader>
          <DialogTitle>Library access</DialogTitle>
          <DialogDescription>Choose the libraries {user.email} can see.</DialogDescription>
        </DialogHeader>
        <LibraryAccessForm userId={user.publicId} onClose={() => onOpenChange(false)} />
      </DialogContent>
    </Dialog>
  )
}

/** Mounted only while the dialog is open, so every opening starts from the server's state. */
function LibraryAccessForm({ userId, onClose }: { userId: string; onClose: () => void }) {
  const { data: libraries, isPending, isError, isFetching, refetch, error } = useQuery({
    queryKey: libraryAccessKey(userId),
    queryFn: ({ signal }) => userAdminApi.libraryAccess(userId, signal),
  })
  if (isPending) {
    return (
      <LibraryList aria-busy="true" aria-label="Loading libraries">
        <SkeletonLine />
        <SkeletonLine />
        <SkeletonLine />
      </LibraryList>
    )
  }

  if (isError && !libraries) {
    return (
      <>
        <ErrorRow>
          <ErrorText role="alert">Unable to load the libraries.</ErrorText>
          <Button type="button" variant="ghost" size="sm" onClick={() => refetch()} disabled={isFetching}>
            Retry
          </Button>
        </ErrorRow>
        <DialogFooter>
          <Button type="button" variant="outline" onClick={onClose}>Close</Button>
        </DialogFooter>
      </>
    )
  }

  if (libraries.length === 0) {
    return (
      <>
        <MutedText>There are no libraries yet.</MutedText>
        <DialogFooter>
          <Button type="button" variant="outline" onClick={onClose}>Close</Button>
        </DialogFooter>
      </>
    )
  }

  return <LibraryAccessEditor userId={userId} libraries={libraries} onClose={onClose} unavailable={isAxiosError(error) && error.response?.status === 404} />
}

function LibraryAccessEditor({ userId, libraries, onClose, unavailable }: {
  userId: string
  libraries: AdminUserLibraryAccess[]
  unavailable: boolean
  onClose: () => void
}) {
  const queryClient = useQueryClient()
  const live = useAdminMutationTools(['admin-users'])
  const queryKey = libraryAccessKey(userId)
  const draft = useDraft<Record<string, boolean>>({ identity: userId, initialData: Object.fromEntries(libraries.map(row => [row.libraryId, row.granted])) })
  const latestValues = useRef(draft.values)
  useEffect(() => {
    latestValues.current = draft.values
  }, [draft.values])
  const { receive, pending } = draft
  const lastLibraries = useRef(libraries)
  const revokedLibraries = useRef<typeof libraries | undefined>(undefined)
  const { revokeAccess } = draft
  useEffect(() => subscribeOwnership(() => {
    revokedLibraries.current = lastLibraries.current
    revokeAccess()
  }), [revokeAccess])
  useEffect(() => {
    lastLibraries.current = libraries
    if (!libraries || pending || revokedLibraries.current === libraries) return
    if (unavailable) receive(null)
    else if ([...draft.dirty].some(id => !libraries.some(row => row.libraryId === id))) receive(null)
    else receive(Object.fromEntries(libraries.map(row => [row.libraryId, row.granted])))
  }, [libraries, receive, pending, draft.dirty, unavailable])
  const [unsaved, setUnsaved] = useState<string[]>([])

  const save = useMutation({
    mutationFn: (changes: AccessChange[]) => live.run(() => Promise.allSettled(
      changes.map(({ library, granted }) => (granted
        ? userAdminApi.grantLibraryAccess(userId, library.libraryId)
        : userAdminApi.revokeLibraryAccess(userId, library.libraryId))),
    )),
  })

  const isChecked = (library: AdminUserLibraryAccess) => draft.values[library.libraryId] ?? library.granted
  const changes: AccessChange[] = libraries
    .filter((library) => isChecked(library) !== library.granted)
    .map((library) => ({ library, granted: isChecked(library) }))

  const toggle = (library: AdminUserLibraryAccess) => {
    draft.updateField(library.libraryId, !isChecked(library))
  }

  const handleSubmit = async (event: React.FormEvent) => {
    event.preventDefault()
    const submission = draft.beginSave()
    if (!submission) return
    const stamp = captureOwnership()
    setUnsaved([])
    try {
      const results = await save.mutateAsync(changes)
      if (!isCurrentOwnership(stamp)) return
      const saved = new Map<string, AdminUserLibraryAccess>()
      const failed: string[] = []
      results.forEach((result, index) => {
        if (result.status === 'fulfilled') saved.set(result.value.libraryId, result.value)
        else failed.push(changes[index].library.name)
      })
      if (failed.length) draft.failSave(submission)
      else draft.completeSave(submission)
      queryClient.setQueryData<AdminUserLibraryAccess[]>(queryKey, current => current?.map(row => saved.get(row.libraryId) ?? row))
      setUnsaved(failed)
      await live.invalidate(queryKey)
      if (!isCurrentOwnership(stamp)) return
      if (!failed.length && Object.keys(latestValues.current).every(id => latestValues.current[id] === submission.values[id])) onClose()
    } catch {
      if (isCurrentOwnership(stamp)) draft.failSave(submission)
    }
  }

  return (
    <Form onSubmit={handleSubmit}>
      <LibraryList>
        {libraries.map((library) => (
          <LibraryLabel key={library.libraryId}>
            <Checkbox
              checked={isChecked(library)}
              onChange={() => toggle(library)}
              disabled={draft.status === 'unavailable'}
            />
            <LibraryName>{library.name}</LibraryName>
          </LibraryLabel>
        ))}
      </LibraryList>
      {unsaved.length > 0 && (
        <ErrorText role="alert">
          {`Access to ${unsaved.join(', ')} was not saved.`}
        </ErrorText>
      )}
      {draft.status === 'unavailable' && <ErrorText role="alert">Library access is no longer available. Your changes are retained.</ErrorText>}
      {draft.status === 'conflict' && <div role="alert">
        Access changed on the server.
        <Button type="button" onClick={() => draft.resolveConflict('local')}>Keep my changes</Button>
        <Button type="button" onClick={() => draft.resolveConflict('server')}>Use server values</Button>
      </div>}
      <DialogFooter>
        <Button type="button" variant="outline" onClick={onClose}>Cancel</Button>
        <Button type="submit" disabled={!draft.canSave}>
          {save.isPending ? 'Saving...' : 'Save'}
        </Button>
      </DialogFooter>
    </Form>
  )
}
