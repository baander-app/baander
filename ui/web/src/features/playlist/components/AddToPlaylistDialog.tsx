import { useState } from 'react'
import styled from 'styled-components'
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
} from '@/shared/components/ui/dialog'
import { Input } from '@/shared/components/ui/input'
import { Button } from '@/shared/components/ui/button'
import { Skeleton } from '@/shared/components/ui/skeleton'
import { useGetPlaylistIndex, usePostPlaylistAddSong } from '@/shared/api-client/gen/endpoints'
import { usePostPlaylistStore } from '@/shared/api-client/gen/endpoints'
import { toast } from 'sonner'
import { Plus } from 'lucide-react'

const ContentWrapper = styled.div`
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
`

const SkeletonList = styled.div`
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
`

const ScrollArea = styled.div`
  max-height: 12rem;
  overflow-y: auto;
`

const PlaylistList = styled.div`
  display: flex;
  flex-direction: column;
  gap: 0.125rem;
`

const PlaylistButton = styled.button`
  width: 100%;
  border-radius: var(--radius-md);
  padding: 0.5rem 0.75rem;
  text-align: left;
  font-size: 0.875rem;
  background: none;
  border: none;
  cursor: pointer;
  transition: background-color 0.15s, color 0.15s;

  &:hover {
    background-color: var(--color-accent);
    color: var(--color-accent-foreground);
  }
`

const EmptyMessage = styled.p`
  padding: 1rem 0;
  text-align: center;
  font-size: 0.875rem;
  color: var(--color-muted-foreground);
`

const CreateRow = styled.div`
  display: flex;
  align-items: center;
  gap: 0.5rem;
  border-top: 1px solid var(--color-border);
  padding-top: 0.75rem;
`

interface PlaylistItem {
  publicId: string
  name: string
  isSmart?: boolean
}

interface AddToPlaylistDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  songId: string
}

export function AddToPlaylistDialog({ open, onOpenChange, songId }: AddToPlaylistDialogProps) {
  const [search, setSearch] = useState('')
  const [creating, setCreating] = useState(false)
  const [newName, setNewName] = useState('')
  const { data, isLoading } = useGetPlaylistIndex({
    query: { enabled: open },
  })
  const addSong = usePostPlaylistAddSong()
  const createPlaylist = usePostPlaylistStore()

  const playlists = ((data as any)?.data ?? []) as PlaylistItem[]
  const filtered = search
    ? playlists.filter((p) => p.name.toLowerCase().includes(search.toLowerCase()))
    : playlists
  const regularPlaylists = filtered.filter((p) => !p.isSmart)

  const handleAdd = async (playlistPublicId: string) => {
    try {
      await addSong.mutateAsync({ publicId: playlistPublicId, data: { songId } })
      toast.success('Added to playlist')
      onOpenChange(false)
    } catch {
      toast.error('Failed to add song to playlist')
    }
  }

  const handleCreateAndAdd = async () => {
    if (!newName.trim()) return
    setCreating(true)
    try {
      const result = await createPlaylist.mutateAsync({
        data: {
          name: newName.trim(),
          isPublic: false,
          isCollaborative: false,
          isSmart: false,
          smartRules: [],
        },
      })
      const newPlaylistId = (result as any)?.data?.publicId
      if (newPlaylistId) {
        await addSong.mutateAsync({ publicId: newPlaylistId, data: { songId } })
        toast.success(`Created "${newName.trim()}" and added song`)
      }
      setNewName('')
      onOpenChange(false)
    } catch {
      toast.error('Failed to create playlist')
    } finally {
      setCreating(false)
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Add to Playlist</DialogTitle>
          <DialogDescription>Choose an existing playlist or create a new one.</DialogDescription>
        </DialogHeader>

        <ContentWrapper>
          <Input
            placeholder="Search playlists..."
            value={search}
            onChange={(e) => setSearch(e.target.value)}
          />

          {isLoading ? (
            <SkeletonList>
              {Array.from({ length: 4 }).map((_, i) => (
                <Skeleton key={i} style={{ height: '2.25rem', width: '100%', borderRadius: 'var(--radius-md)' }} />
              ))}
            </SkeletonList>
          ) : regularPlaylists.length === 0 && !creating ? (
            <EmptyMessage>No playlists yet. Create one below.</EmptyMessage>
          ) : (
            <ScrollArea>
              <PlaylistList>
                {regularPlaylists.map((playlist) => (
                  <PlaylistButton
                    key={playlist.publicId}
                    type="button"
                    onClick={() => handleAdd(playlist.publicId)}
                  >
                    {playlist.name}
                  </PlaylistButton>
                ))}
              </PlaylistList>
            </ScrollArea>
          )}

          <CreateRow>
            <Input
              placeholder="New playlist name"
              value={newName}
              onChange={(e) => setNewName(e.target.value)}
              onKeyDown={(e) => {
                if (e.key === 'Enter') handleCreateAndAdd()
              }}
              disabled={creating}
            />
            <Button
              size="sm"
              onClick={handleCreateAndAdd}
              disabled={!newName.trim() || creating}
            >
              <Plus size={14} />
              Create
            </Button>
          </CreateRow>
        </ContentWrapper>

        <DialogFooter showCloseButton />
      </DialogContent>
    </Dialog>
  )
}
