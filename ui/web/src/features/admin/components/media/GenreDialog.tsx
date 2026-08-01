import { useState } from 'react'
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
  const [name, setName] = useState(genre?.name ?? '')
  const [slug, setSlug] = useState(genre?.slug ?? '')
  const [parentId, setParentId] = useState<string | null>(
    genre?.parentId ?? null,
  )
  const [mbid, setMbid] = useState(genre?.mbid ?? '')
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
    const payload = { name, slug, parentId: parentId || null, mbid: mbid || null }

    if (isEdit && genre) {
      updateGenre.mutate(
        { slug: genre.slug, name, newSlug: slug },
        { onSuccess: () => onOpenChange(false) },
      )
    } else {
      createGenre.mutate(payload, {
        onSuccess: () => {
          setName('')
          setSlug('')
          setParentId(null)
          setMbid('')
          setSlugEdited(false)
          onOpenChange(false)
        },
      })
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent style={{ maxWidth: '28rem' }}>
        <DialogHeader>
          <DialogTitle>{isEdit ? 'Edit Genre' : 'Create Genre'}</DialogTitle>
        </DialogHeader>
        <Form onSubmit={handleSubmit}>
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
                .filter((g) => g.uuid !== genre?.uuid)
                .map((g) => (
                  <option key={g.uuid} value={g.uuid}>
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
            <Button type="submit" disabled={!name || !slug}>
              {isEdit ? 'Save' : 'Create'}
            </Button>
          </DialogFooter>
        </Form>
      </DialogContent>
    </Dialog>
  )
}
