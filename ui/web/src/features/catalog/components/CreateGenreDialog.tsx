import styled from 'styled-components'
import { useState } from 'react'
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogFooter,
} from '@/shared/components/ui/dialog'
import { Button } from '@/shared/components/ui/button'
import { Input } from '@/shared/components/ui/input'
import { createGenre } from '../api/catalog-admin-api'

const Form = styled.form`
  display: flex;
  flex-direction: column;
  gap: 1rem;
`

const FieldGroup = styled.div`
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
`

const Label = styled.label`
  font-size: 0.875rem;
  font-weight: 500;
  color: var(--color-foreground);
`

const ErrorText = styled.p`
  font-size: 0.875rem;
  color: var(--color-destructive);
`

interface CreateGenreDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  onCreated?: () => void
}

export function CreateGenreDialog({ open, onOpenChange, onCreated }: CreateGenreDialogProps) {
  const [name, setName] = useState('')
  const [slug, setSlug] = useState('')
  const [mbid, setMbid] = useState('')
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const handleNameChange = (value: string) => {
    setName(value)
    // Auto-generate slug from name
    const generated = value
      .toLowerCase()
      .trim()
      .replace(/[^a-z0-9]+/g, '-')
      .replace(/^-|-$/g, '')
    setSlug(generated)
  }

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!name.trim() || !slug.trim()) return

    setSaving(true)
    setError(null)

    try {
      await createGenre({
        name: name.trim(),
        slug,
        mbid: mbid.trim() || undefined,
      })
      setName('')
      setSlug('')
      setMbid('')
      onOpenChange(false)
      onCreated?.()
    } catch (err: unknown) {
      const message = err instanceof Error ? err.message : 'Failed to create genre'
      setError(message)
    } finally {
      setSaving(false)
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Create Genre</DialogTitle>
        </DialogHeader>

        <Form onSubmit={handleSubmit}>
          <FieldGroup>
            <Label>Name</Label>
            <Input
              value={name}
              onChange={(e) => handleNameChange(e.target.value)}
              placeholder="e.g. Jazz"
              autoFocus
            />
          </FieldGroup>

          <FieldGroup>
            <Label>Slug</Label>
            <Input
              value={slug}
              onChange={(e) => setSlug(e.target.value)}
              placeholder="auto-generated from name"
            />
          </FieldGroup>

          <FieldGroup>
            <Label>MusicBrainz ID</Label>
            <Input
              value={mbid}
              onChange={(e) => setMbid(e.target.value)}
              placeholder="optional"
            />
          </FieldGroup>

          {error && (
            <ErrorText>{error}</ErrorText>
          )}

          <DialogFooter>
            <Button type="button" variant="ghost" onClick={() => onOpenChange(false)}>
              Cancel
            </Button>
            <Button type="submit" disabled={!name.trim() || !slug.trim() || saving}>
              {saving ? 'Creating\u2026' : 'Create'}
            </Button>
          </DialogFooter>
        </Form>
      </DialogContent>
    </Dialog>
  )
}
