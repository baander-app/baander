import styled from 'styled-components'
import { Button } from '@/shared/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/shared/components/ui/dialog'
import { useDeleteGenre } from '../../hooks/use-genre-admin'
import type { Genre } from '../../api/genre-admin-api'

const Message = styled.p`
  font-size: 13px;
  color: var(--color-muted-foreground);
`

export function DeleteGenreDialog({
  open,
  onOpenChange,
  genre,
}: {
  open: boolean
  onOpenChange: (v: boolean) => void
  genre: Genre | null
}) {
  const deleteGenre = useDeleteGenre()

  if (!genre) return null

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent style={{ maxWidth: '24rem' }}>
        <DialogHeader>
          <DialogTitle>Delete Genre</DialogTitle>
        </DialogHeader>
        <Message>
          Delete <strong>{genre.name}</strong>? This cannot be undone.
        </Message>
        <DialogFooter>
          <Button
            variant="ghost"
            onClick={() => onOpenChange(false)}
          >
            Cancel
          </Button>
          <Button
            variant="destructive"
            onClick={() =>
              deleteGenre.mutate(genre.slug, {
                onSuccess: () => onOpenChange(false),
              })
            }
          >
            Delete
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
