import { useState } from 'react'
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
        <LibraryAccessForm userId={user.id} onClose={() => onOpenChange(false)} />
      </DialogContent>
    </Dialog>
  )
}

/** Mounted only while the dialog is open, so every opening starts from the server's state. */
function LibraryAccessForm({ userId, onClose }: { userId: string; onClose: () => void }) {
  const queryClient = useQueryClient()
  const queryKey = libraryAccessKey(userId)
  const { data: libraries, isPending, isError, isFetching, refetch } = useQuery({
    queryKey,
    queryFn: ({ signal }) => userAdminApi.libraryAccess(userId, signal),
  })
  /** The checked state of rows the admin toggled, keyed by library id. */
  const [edits, setEdits] = useState<Record<string, boolean>>({})
  const [unsaved, setUnsaved] = useState<string[]>([])

  const save = useMutation({
    mutationFn: (changes: AccessChange[]) => Promise.allSettled(
      changes.map(({ library, granted }) => (granted
        ? userAdminApi.grantLibraryAccess(userId, library.libraryId)
        : userAdminApi.revokeLibraryAccess(userId, library.libraryId))),
    ),
    onSuccess: async (results, changes) => {
      const saved = new Map<string, AdminUserLibraryAccess>()
      const failed: string[] = []
      results.forEach((result, index) => {
        if (result.status === 'fulfilled') {
          saved.set(result.value.libraryId, result.value)
        } else {
          failed.push(changes[index].library.name)
        }
      })

      // Saved rows become the loaded state; failed rows drop their edit and show the loaded state again.
      queryClient.setQueryData<AdminUserLibraryAccess[]>(queryKey, (current) => current?.map(
        (library) => saved.get(library.libraryId) ?? library,
      ))
      setEdits({})
      setUnsaved(failed)
      await queryClient.invalidateQueries({ queryKey })

      if (failed.length === 0) onClose()
    },
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

  if (isError) {
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

  const isChecked = (library: AdminUserLibraryAccess) => edits[library.libraryId] ?? library.granted
  const changes: AccessChange[] = libraries
    .filter((library) => isChecked(library) !== library.granted)
    .map((library) => ({ library, granted: isChecked(library) }))

  const toggle = (library: AdminUserLibraryAccess) => {
    setEdits((current) => ({ ...current, [library.libraryId]: !isChecked(library) }))
  }

  const handleSubmit = (event: React.FormEvent) => {
    event.preventDefault()
    if (changes.length === 0) {
      onClose()
      return
    }
    setUnsaved([])
    save.mutate(changes)
  }

  return (
    <Form onSubmit={handleSubmit}>
      <LibraryList>
        {libraries.map((library) => (
          <LibraryLabel key={library.libraryId}>
            <Checkbox
              checked={isChecked(library)}
              onChange={() => toggle(library)}
              disabled={save.isPending}
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
      <DialogFooter>
        <Button type="button" variant="outline" onClick={onClose}>Cancel</Button>
        <Button type="submit" disabled={save.isPending}>
          {save.isPending ? 'Saving...' : 'Save'}
        </Button>
      </DialogFooter>
    </Form>
  )
}
