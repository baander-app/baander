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
import { createArtist } from '../api/catalog-admin-api'

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

interface CreateArtistDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  onCreated?: () => void
}

export function CreateArtistDialog({ open, onOpenChange, onCreated }: CreateArtistDialogProps) {
  const [name, setName] = useState('')
  const [country, setCountry] = useState('')
  const [type, setType] = useState('')
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!name.trim()) return

    setSaving(true)
    setError(null)

    try {
      await createArtist({
        name: name.trim(),
        country: country.trim() || undefined,
        type: type.trim() || undefined,
      })
      setName('')
      setCountry('')
      setType('')
      onOpenChange(false)
      onCreated?.()
    } catch (err: unknown) {
      const message = err instanceof Error ? err.message : 'Failed to create artist'
      setError(message)
    } finally {
      setSaving(false)
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Create Artist</DialogTitle>
        </DialogHeader>

        <Form onSubmit={handleSubmit}>
          <FieldGroup>
            <Label>Name</Label>
            <Input
              value={name}
              onChange={(e) => setName(e.target.value)}
              placeholder="e.g. Radiohead"
              autoFocus
            />
          </FieldGroup>

          <FieldGroup>
            <Label>Country</Label>
            <Input
              value={country}
              onChange={(e) => setCountry(e.target.value)}
              placeholder="e.g. DK"
            />
          </FieldGroup>

          <FieldGroup>
            <Label>Type</Label>
            <Input
              value={type}
              onChange={(e) => setType(e.target.value)}
              placeholder="person, group, etc."
            />
          </FieldGroup>

          {error && (
            <ErrorText>{error}</ErrorText>
          )}

          <DialogFooter>
            <Button type="button" variant="ghost" onClick={() => onOpenChange(false)}>
              Cancel
            </Button>
            <Button type="submit" disabled={!name.trim() || saving}>
              {saving ? 'Creating\u2026' : 'Create'}
            </Button>
          </DialogFooter>
        </Form>
      </DialogContent>
    </Dialog>
  )
}
