import { guardMutationCallbacks } from '@/shared/live/ownership'
import { useEffect, useRef, useState } from 'react'
import { useDraft } from '@/shared/drafts/use-draft'
import { captureOwnership, isCurrentOwnership, subscribeOwnership } from '@/shared/live/ownership'
import styled from 'styled-components'
import { Button } from '@/shared/components/ui/button'
import { Input } from '@/shared/components/ui/input'
import {
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/shared/components/ui/dialog'
import { useCreateGenre, useUpdateGenre } from '../../hooks/use-genre-admin'
import type { Genre } from '../../api/genre-admin-api'

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

const StyledSelect = styled.select`
  width: 100%;
  border-radius: 0.375rem;
  border: 1px solid var(--color-border);
  background-color: var(--color-background);
  padding: 0.5rem 0.75rem;
  font-size: 13px;
`

function slugify(text: string): string {
  return text
    .toLowerCase()
    .trim()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-|-$/g, '')
}

export function GenreDialog({
  open,
  onOpenChange,
  genre,
  genres,
}: {
  open: boolean
  onOpenChange: (v: boolean) => void
  genre?: Genre | null
  genres: Genre[]
}) {
  const isEdit = genre !== null && genre !== undefined
  const draft = useDraft({ identity: genre?.publicId ?? 'create', initialData: {
    name: genre?.name ?? '', slug: genre?.slug ?? '', parentId: genre?.parentId ?? null,
    mbid: genre?.mbid ?? '',
  } })
  const { receive, revokeAccess } = draft
  const { name = '', slug = '', parentId = null, mbid = '' } = draft.values
  const setName = (value: string) => draft.updateField('name', value)
  const setSlug = (value: string) => draft.updateField('slug', value)
  const setParentId = (value: string | null) => draft.updateField('parentId', value)
  const setMbid = (value: string) => draft.updateField('mbid', value)
  const current = genre ? genres.find(row => row.publicId === genre.publicId) : undefined
  const latestSnapshot = useRef(current ?? genre ?? undefined)
  const revokedSnapshot = useRef<Genre | undefined>(undefined)
  useEffect(() => {
    if (!genre || (current && current === revokedSnapshot.current)) return
    if (current) latestSnapshot.current = current
    receive(current ? { name: current.name, slug: current.slug, parentId: current.parentId, mbid: current.mbid ?? '' } : null)
  }, [genre, current, receive])
  useEffect(() => {
    const owner = captureOwnership()
    return subscribeOwnership(() => {
      if (!isCurrentOwnership(owner)) {
        revokedSnapshot.current = latestSnapshot.current
        revokeAccess()
      }
    })
  }, [revokeAccess])
  const [slugEdited, setSlugEdited] = useState(isEdit)

  const createGenre = useCreateGenre()
  const updateGenre = useUpdateGenre()

  const handleNameChange = (v: string) => {
    setName(v)
    if (!slugEdited) {
      setSlug(slugify(v))
    }
  }

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    const submission = draft.beginSave()
    if (!submission) return
    const payload = { name, slug, parentId: parentId || null, mbid: mbid || null }

    if (isEdit && genre) {
      updateGenre.mutate(
        { slug: current?.slug ?? genre.slug, name, newSlug: slug },
        guardMutationCallbacks({
          onSuccess: () => draft.completeSave(submission),
          onError: () => draft.failSave(submission),
        }),
      )
    } else {
      createGenre.mutate(payload, guardMutationCallbacks({
        onSuccess: () => { draft.completeSave(submission) },
        onError: () => { draft.failSave(submission) },
      }))
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent style={{ maxWidth: '28rem' }}>
        <DialogHeader>
          <DialogTitle>{isEdit ? 'Edit Genre' : 'Create Genre'}</DialogTitle>
        </DialogHeader>
        <Form onSubmit={handleSubmit}>
          {draft.status === 'conflict' && <div role="alert">This genre changed. Your edits are preserved.
            <Button type="button" onClick={() => draft.resolveConflict('local')}>Keep my edits</Button>
            <Button type="button" onClick={() => draft.resolveConflict('server')}>Use server values</Button>
          </div>}
          {draft.status === 'unavailable' && <p role="alert">This genre is unavailable. Your edits are preserved.</p>}
          <FieldGroup>
            <Label>Name</Label>
            <Input
              value={name}
              onChange={(e) => handleNameChange(e.target.value)}
              placeholder="Rock"
              required
            />
          </FieldGroup>
          <FieldGroup>
            <Label>Slug</Label>
            <Input
              value={slug}
              onChange={(e) => {
                setSlug(e.target.value)
                setSlugEdited(true)
              }}
              placeholder="rock"
              required
            />
          </FieldGroup>
          <FieldGroup>
            <Label>Parent Genre</Label>
            <StyledSelect
              value={parentId ?? ''}
              onChange={(e) => setParentId(e.target.value || null)}
            >
              <option value="">None (root)</option>
              {genres
                .filter((g) => g.publicId !== genre?.publicId)
                .map((g) => (
                  <option key={g.publicId} value={g.publicId}>
                    {g.name}
                  </option>
                ))}
            </StyledSelect>
          </FieldGroup>
          <FieldGroup>
            <Label>MusicBrainz ID</Label>
            <Input
              value={mbid}
              onChange={(e) => setMbid(e.target.value)}
              placeholder="optional"
            />
          </FieldGroup>
          <DialogFooter>
            <Button
              type="button"
              variant="ghost"
              onClick={() => onOpenChange(false)}
            >
              Cancel
            </Button>
            <Button type="submit" disabled={!name || !slug || !draft.canSave}>
              {isEdit ? 'Save' : 'Create'}
            </Button>
          </DialogFooter>
        </Form>
      </DialogContent>
    </Dialog>
  )
}
