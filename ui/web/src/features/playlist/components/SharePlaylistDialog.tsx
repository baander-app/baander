import { useState } from 'react'
import styled from 'styled-components'
import { useQueryClient } from '@tanstack/react-query'
import { Copy, Share2, X } from 'lucide-react'
import {
  useDeletePlaylistRemoveCollaborator,
  useGetPlaylistShow,
  usePatchPlaylistUpdate,
  usePutPlaylistAddCollaborator,
} from '@/shared/api-client/gen/endpoints'
import { invalidatePlaylistQueries } from '../lib/playlist-queries'
import { parseApiError } from '@/features/auth/lib/parse-api-error'
import { createLogger } from '@/shared/lib/logger'
import { Button } from '@/shared/components/ui/button'
import { Input } from '@/shared/components/ui/input'
import { Switch } from '@/shared/components/ui/switch'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from '@/shared/components/ui/dialog'

const logger = createLogger('playlist.share')

const Section = styled.section`
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
`

const SectionLabel = styled.span`
  font-size: 0.6875rem;
  font-weight: 500;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  color: var(--color-muted-foreground);
`

const SwitchRow = styled.div`
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
`

const SwitchLabel = styled.label`
  font-size: 0.875rem;
  cursor: pointer;
`

const PublicIdRow = styled.div`
  display: flex;
  align-items: center;
  gap: 0.5rem;
  min-width: 0;
`

const PublicId = styled.code`
  flex: 1;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-family: var(--font-mono);
  font-size: 0.8125rem;
  user-select: all;
`

const CopyStatus = styled.span`
  font-size: 0.75rem;
  color: var(--color-muted-foreground);
`

const CollaboratorList = styled.ul`
  display: flex;
  flex-direction: column;
  gap: 0.125rem;
  margin: 0;
  padding: 0;
  list-style: none;
`

const CollaboratorItem = styled.li`
  display: flex;
  align-items: center;
  gap: 0.5rem;
  min-width: 0;
  border-radius: var(--radius-md);
  padding: 0.25rem 0 0.25rem 0.5rem;
`

const EmptyText = styled.p`
  font-size: 0.875rem;
  color: var(--color-muted-foreground);
`

const AddForm = styled.form`
  display: flex;
  gap: 0.5rem;
`

const AddInput = styled(Input)`
  flex: 1;
  min-width: 0;
`

const ErrorText = styled.p`
  font-size: 0.75rem;
  color: var(--color-destructive);
`

type CopyState = 'idle' | 'copied' | 'failed'

interface SharePlaylistDialogProps {
  playlistPublicId: string
}

/** The owner's sharing controls: the collaborative switch, the collaborator list and the owner's own public id. */
export function SharePlaylistDialog({ playlistPublicId }: SharePlaylistDialogProps) {
  return (
    <Dialog>
      <DialogTrigger asChild>
        <Button variant="outline" size="sm">
          <Share2 size={14} /> Share
        </Button>
      </DialogTrigger>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Share playlist</DialogTitle>
          <DialogDescription>
            Collaborators can see this playlist and, while collaboration is on, change its songs.
            Add one by the public id they share with you.
          </DialogDescription>
        </DialogHeader>
        <SharePlaylistForm playlistPublicId={playlistPublicId} />
      </DialogContent>
    </Dialog>
  )
}

/** Mounted only while the dialog is open, so every opening reads the server's state. */
function SharePlaylistForm({ playlistPublicId }: SharePlaylistDialogProps) {
  const queryClient = useQueryClient()
  const { data } = useGetPlaylistShow(playlistPublicId)
  const playlist = data?.data
  const [newCollaborator, setNewCollaborator] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [copyState, setCopyState] = useState<CopyState>('idle')

  const refresh = () => invalidatePlaylistQueries(queryClient, playlistPublicId)
  const mutationOptions = (fallback: string) => ({
    mutation: {
      onMutate: () => setError(null),
      onSuccess: refresh,
      onError: (failure: unknown) => setError(parseApiError(failure, fallback).message),
    },
  })

  const update = usePatchPlaylistUpdate(mutationOptions('The playlist could not be updated.'))
  const addCollaborator = usePutPlaylistAddCollaborator({
    mutation: {
      ...mutationOptions('The collaborator could not be added.').mutation,
      onSuccess: async () => {
        setNewCollaborator('')
        await refresh()
      },
    },
  })
  const removeCollaborator = useDeletePlaylistRemoveCollaborator(mutationOptions('The collaborator could not be removed.'))

  if (!playlist) return null

  const collaborators = playlist.collaboratorPublicIds ?? []
  const busy = update.isPending || addCollaborator.isPending || removeCollaborator.isPending
  const candidate = newCollaborator.trim()

  const handleAdd = (event: React.FormEvent) => {
    event.preventDefault()
    if (candidate === '' || busy) return
    addCollaborator.mutate({ publicId: playlistPublicId, userPublicId: candidate })
  }

  const copyOwnPublicId = async () => {
    const ownerPublicId = playlist.ownerPublicId ?? ''
    try {
      if (!navigator.clipboard) throw new Error('The clipboard is unavailable')
      await navigator.clipboard.writeText(ownerPublicId)
      setCopyState('copied')
    } catch (failure: unknown) {
      logger.warn('Could not copy the public id:', failure)
      setCopyState('failed')
    }
  }

  return (
    <>
      <Section>
        <SwitchRow>
          <SwitchLabel htmlFor="playlist-collaborative">Let collaborators edit</SwitchLabel>
          <Switch
            id="playlist-collaborative"
            checked={playlist.isCollaborative ?? false}
            disabled={busy}
            onCheckedChange={(checked) => update.mutate({ publicId: playlistPublicId, data: { isCollaborative: checked } })}
          />
        </SwitchRow>
      </Section>

      <Section aria-labelledby="playlist-collaborators-label">
        <SectionLabel id="playlist-collaborators-label">Collaborators</SectionLabel>
        {collaborators.length === 0 ? (
          <EmptyText>No collaborators yet.</EmptyText>
        ) : (
          <CollaboratorList aria-label="Collaborators">
            {collaborators.map((collaborator) => (
              <CollaboratorItem key={collaborator}>
                <PublicId>{collaborator}</PublicId>
                <Button
                  type="button"
                  variant="ghost"
                  size="icon-sm"
                  aria-label={`Remove ${collaborator}`}
                  disabled={busy}
                  onClick={() => removeCollaborator.mutate({ publicId: playlistPublicId, userPublicId: collaborator })}
                >
                  <X size={14} />
                </Button>
              </CollaboratorItem>
            ))}
          </CollaboratorList>
        )}
        <AddForm onSubmit={handleAdd}>
          <AddInput
            aria-label="Collaborator public id"
            placeholder="Paste a public id"
            value={newCollaborator}
            onChange={(event) => setNewCollaborator(event.target.value)}
            disabled={busy}
            autoComplete="off"
            spellCheck={false}
          />
          <Button type="submit" size="sm" disabled={candidate === '' || busy}>
            Add
          </Button>
        </AddForm>
        {error && <ErrorText role="alert">{error}</ErrorText>}
      </Section>

      <Section>
        <SectionLabel>Your public id</SectionLabel>
        <PublicIdRow>
          <PublicId>{playlist.ownerPublicId}</PublicId>
          <Button type="button" variant="outline" size="sm" aria-label="Copy your public id" onClick={copyOwnPublicId}>
            <Copy size={14} /> Copy
          </Button>
        </PublicIdRow>
        <CopyStatus aria-live="polite">
          {copyState === 'copied' && 'Copied to the clipboard.'}
          {copyState === 'failed' && 'Could not copy. Select the id and copy it manually.'}
        </CopyStatus>
      </Section>
    </>
  )
}
