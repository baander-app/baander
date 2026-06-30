import { useState, useEffect, type FormEvent } from 'react'
import styled, { css } from 'styled-components'
import { Button } from '@/shared/components/ui/button'
import { Input } from '@/shared/components/ui/input'
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/shared/components/ui/select'
import { LIBRARY_TYPES, type Library } from '../api/library-api'
import { usePathValidation } from '../hooks/use-path-validation'

const Overlay = styled.div`
  position: fixed;
  inset: 0;
  z-index: 50;
  display: flex;
  align-items: center;
  justify-content: center;
  background-color: rgba(0, 0, 0, 0.5);
`

const DialogCard = styled.div<{ $maxW?: string }>`
  width: 100%;
  max-width: ${(p) => p.$maxW ?? '28rem'};
  border-radius: var(--radius-lg);
  border: 1px solid var(--color-border);
  background-color: var(--color-card);
  padding: 1.5rem;
  box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -4px rgba(0, 0, 0, 0.1);
`

const Title = styled.h2`
  font-size: 1.125rem;
  font-weight: 600;
`

const Subtitle = styled.p`
  margin-top: 0.25rem;
  font-size: 0.875rem;
  color: var(--color-muted-foreground);
`

const Form = styled.form`
  margin-top: 1rem;
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
`

const FieldGroup = styled.div`
  display: flex;
  flex-direction: column;
`

const Label = styled.label`
  margin-bottom: 0.25rem;
  display: block;
  font-size: 0.875rem;
  font-weight: 500;
`

const InputWrapper = styled.div`
  position: relative;
`

const ValidatingHint = styled.span`
  position: absolute;
  right: 0.5rem;
  top: 50%;
  transform: translateY(-50%);
  font-size: 0.75rem;
  color: var(--color-muted-foreground);
`

const PathHint = styled.p<{ $valid: boolean }>`
  margin-top: 0.25rem;
  font-size: 0.75rem;
  ${(p) => p.$valid
    ? css`color: #10b981;`
    : css`color: #ef4444;`
  }
`

const Actions = styled.div`
  display: flex;
  justify-content: flex-end;
  gap: 0.5rem;
  padding-top: 0.5rem;
`

const InfoBox = styled.div`
  border-radius: var(--radius-md);
  background-color: var(--color-secondary);
  padding: 0.75rem;
  font-size: 0.75rem;
  color: var(--color-muted-foreground);

  p {
    margin: 0.125rem 0;
  }

  .mono {
    font-family: monospace;
  }

  .capitalize {
    text-transform: capitalize;
  }
`

const CheckboxLabel = styled.label`
  margin-top: 1rem;
  display: flex;
  align-items: flex-start;
  gap: 0.75rem;
  cursor: pointer;
  border-radius: var(--radius-md);
  border: 1px solid var(--color-border);
  background-color: rgba(var(--color-secondary-rgb, 0 0 0), 0.5);
  padding: 0.75rem;
  transition: background-color 0.15s;

  &:hover {
    background-color: var(--color-secondary);
  }

  input[type="checkbox"] {
    margin-top: 0.125rem;
    height: 1rem;
    width: 1rem;
    flex-shrink: 0;
    border-radius: var(--radius-sm);
    accent-color: var(--color-primary);
  }

  p:first-of-type {
    font-size: 0.875rem;
    font-weight: 500;
  }

  p:last-of-type {
    margin-top: 0.125rem;
    font-size: 0.75rem;
    color: var(--color-muted-foreground);
  }
`

interface CreateLibraryDialogProps {
  open: boolean
  onClose: () => void
  onSubmit: (data: { name: string; path: string; type: string; slug?: string }) => void
  isPending: boolean
}

export function CreateLibraryDialog({ open, onClose, onSubmit, isPending }: CreateLibraryDialogProps) {
  const [name, setName] = useState('')
  const [path, setPath] = useState('')
  const [type, setType] = useState('music')
  const [slug, setSlug] = useState('')
  const { result: pathResult, isValidating, validate, reset: resetValidation } = usePathValidation()

  useEffect(() => {
    if (!open) {
      setName('')
      setPath('')
      setType('music')
      setSlug('')
      resetValidation()
    }
  }, [open, resetValidation])

  if (!open) return null

  const handleSubmit = (e: FormEvent) => {
    e.preventDefault()
    onSubmit({ name, path, type, slug: slug || undefined })
  }

  return (
    <Overlay>
      <DialogCard>
        <Title>Add Library</Title>
        <Subtitle>Create a new media library by pointing to a filesystem path.</Subtitle>

        <Form onSubmit={handleSubmit}>
          <FieldGroup>
            <Label>Name</Label>
            <Input
              value={name}
              onChange={(e) => setName(e.target.value)}
              placeholder="My Music"
              required
            />
          </FieldGroup>

          <FieldGroup>
            <Label>Path</Label>
            <InputWrapper>
              <Input
                value={path}
                onChange={(e) => {
                  setPath(e.target.value)
                  validate(e.target.value)
                }}
                placeholder="/mnt/media/music"
                required
                style={pathResult ? (pathResult.valid ? { borderColor: '#10b981' } : { borderColor: '#ef4444' }) : {}}
              />
              {isValidating && (
                <ValidatingHint>Checking...</ValidatingHint>
              )}
            </InputWrapper>
            {pathResult && !isValidating && (
              <PathHint $valid={pathResult.valid}>
                {pathResult.valid
                  ? `Resolved: ${pathResult.resolvedPath}`
                  : pathResult.error}
              </PathHint>
            )}
          </FieldGroup>

          <FieldGroup>
            <Label>Type</Label>
            <Select value={type} onValueChange={setType}>
              <SelectTrigger>
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {LIBRARY_TYPES.map((t) => (
                  <SelectItem key={t} value={t}>
                    {t.charAt(0).toUpperCase() + t.slice(1).replace('_', ' ')}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </FieldGroup>

          <FieldGroup>
            <Label>Slug (optional)</Label>
            <Input
              value={slug}
              onChange={(e) => setSlug(e.target.value)}
              placeholder="auto-generated from name"
            />
          </FieldGroup>

          <Actions>
            <Button type="button" variant="outline" onClick={onClose} disabled={isPending}>
              Cancel
            </Button>
            <Button
              type="submit"
              disabled={isPending || !name || !path || (pathResult !== null && !pathResult.valid)}
            >
              {isPending ? 'Creating...' : 'Create'}
            </Button>
          </Actions>
        </Form>
      </DialogCard>
    </Overlay>
  )
}

interface EditLibraryDialogProps {
  library: Library | null
  onClose: () => void
  onSubmit: (data: { name: string; sortOrder: number }) => void
  isPending: boolean
}

export function EditLibraryDialog({ library, onClose, onSubmit, isPending }: EditLibraryDialogProps) {
  const [name, setName] = useState(library?.name ?? '')
  const [sortOrder, setSortOrder] = useState(library?.sortOrder ?? 0)

  useEffect(() => {
    if (library) {
      setName(library.name)
      setSortOrder(library.sortOrder)
    }
  }, [library])

  if (!library) return null

  const handleSubmit = (e: FormEvent) => {
    e.preventDefault()
    onSubmit({ name, sortOrder })
  }

  return (
    <Overlay>
      <DialogCard>
        <Title>Edit Library</Title>
        <Subtitle>
          Update name and sort order for <span style={{ fontWeight: 500 }}>{library.name}</span>.
        </Subtitle>

        <Form onSubmit={handleSubmit}>
          <FieldGroup>
            <Label>Name</Label>
            <Input
              value={name}
              onChange={(e) => setName(e.target.value)}
              required
            />
          </FieldGroup>

          <FieldGroup>
            <Label>Sort Order</Label>
            <Input
              type="number"
              value={sortOrder}
              onChange={(e) => setSortOrder(Number(e.target.value))}
            />
          </FieldGroup>

          <InfoBox>
            <p>Slug: <span className="mono">/{library.slug}</span></p>
            <p>Path: <span className="mono">{library.path}</span></p>
            <p>Type: <span className="capitalize">{library.type.replace('_', ' ')}</span></p>
          </InfoBox>

          <Actions>
            <Button type="button" variant="outline" onClick={onClose} disabled={isPending}>
              Cancel
            </Button>
            <Button type="submit" disabled={isPending || !name}>
              {isPending ? 'Saving...' : 'Save'}
            </Button>
          </Actions>
        </Form>
      </DialogCard>
    </Overlay>
  )
}

interface DeleteLibraryDialogProps {
  library: Library | null
  onClose: () => void
  onConfirm: () => void
  isPending: boolean
}

export function DeleteLibraryDialog({ library, onClose, onConfirm, isPending }: DeleteLibraryDialogProps) {
  if (!library) return null

  return (
    <Overlay>
      <DialogCard $maxW="24rem">
        <Title>Delete Library</Title>
        <Subtitle>
          Are you sure you want to delete <span style={{ fontWeight: 500 }}>{library.name}</span>?
          This will also remove all associated albums and songs. This action cannot be undone.
        </Subtitle>

        <Actions style={{ marginTop: '1rem' }}>
          <Button variant="outline" onClick={onClose} disabled={isPending}>
            Cancel
          </Button>
          <Button variant="destructive" onClick={onConfirm} disabled={isPending}>
            {isPending ? 'Deleting...' : 'Delete'}
          </Button>
        </Actions>
      </DialogCard>
    </Overlay>
  )
}

interface ScanLibraryDialogProps {
  library: Library | null
  onClose: () => void
  onConfirm: (rescan: boolean) => void
  isPending: boolean
}

export function ScanLibraryDialog({ library, onClose, onConfirm, isPending }: ScanLibraryDialogProps) {
  const [rescan, setRescan] = useState(false)

  useEffect(() => {
    if (!library) {
      setRescan(false)
    }
  }, [library])

  if (!library) return null

  const handleConfirm = () => {
    onConfirm(rescan)
  }

  return (
    <Overlay>
      <DialogCard $maxW="24rem">
        <Title>Scan Library</Title>
        <Subtitle>
          Start a scan for <span style={{ fontWeight: 500 }}>{library.name}</span>.
        </Subtitle>

        <CheckboxLabel>
          <input
            type="checkbox"
            checked={rescan}
            onChange={(e) => setRescan(e.target.checked)}
          />
          <div>
            <p>Rescan all files</p>
            <p>
              Re-read metadata from all files and update existing songs. Use this when new metadata fields have been added.
            </p>
          </div>
        </CheckboxLabel>

        <Actions style={{ marginTop: '1rem' }}>
          <Button variant="outline" onClick={onClose} disabled={isPending}>
            Cancel
          </Button>
          <Button onClick={handleConfirm} disabled={isPending}>
            {isPending ? 'Scanning...' : 'Start Scan'}
          </Button>
        </Actions>
      </DialogCard>
    </Overlay>
  )
}
