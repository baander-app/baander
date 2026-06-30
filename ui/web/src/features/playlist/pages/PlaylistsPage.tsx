import { useState } from 'react'
import { Link } from 'react-router-dom'
import styled, { css } from 'styled-components'
import { interactiveTransition } from '@/shared/theme'
import {
  useGetPlaylistIndex,
  usePostPlaylistStore,
  useDeletePlaylistDestroy,
} from '@/shared/api-client/gen/endpoints'
import { SmartPlaylistEditor, type SmartRule } from '../components/SmartPlaylistEditor'
import { Skeleton } from '@/shared/components/ui/skeleton'
import { Button } from '@/shared/components/ui/button'
import { Input } from '@/shared/components/ui/input'
import { Plus, Trash2 } from 'lucide-react'
import { toast } from 'sonner'

const PageContainer = styled.div`
  display: flex;
  flex-direction: column;
  height: 100%;
`

const ContentArea = styled.div`
  flex: 1;
  overflow-y: auto;
  background-color: color-mix(in srgb, var(--color-card) 50%, transparent);
`

const PageHeader = styled.div`
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 1rem;
`

const PageTitle = styled.h1`
  font-size: 0.875rem;
  font-weight: 600;
  letter-spacing: -0.025em;
`

const CreateForm = styled.div`
  border-bottom: 1px solid var(--color-border);
  padding: 0 0.75rem 0.75rem;
`

const CreateFormInner = styled.div`
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
`

const SmartToggle = styled.button<{ $active: boolean }>`
  border-radius: var(--radius-md);
  padding: 0.25rem 0.5rem;
  font-size: 0.75rem;
  border: none;
  cursor: pointer;
  transition: background-color 0.15s, color 0.15s;
  background: ${({ $active }) =>
    $active ? 'color-mix(in srgb, var(--color-primary) 10%, transparent)' : 'transparent'};
  color: ${({ $active }) =>
    $active ? 'var(--color-primary)' : 'var(--color-muted-foreground)'};

  &:hover {
    background-color: ${({ $active }) =>
      $active
        ? 'color-mix(in srgb, var(--color-primary) 10%, transparent)'
        : 'color-mix(in srgb, var(--color-accent) 50%, transparent)'};
  }
`

const ActionRow = styled.div`
  display: flex;
  gap: 0.25rem;
`

const ItemsArea = styled.div`
  flex: 1;
  overflow-y: auto;
  padding: 0 0.5rem;
`

const SkeletonList = styled.div`
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
`

const EmptyState = styled.div`
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 0.5rem;
  padding: 3rem 1rem;
`

const EmptyText = styled.p`
  font-size: 0.75rem;
  color: var(--color-muted-foreground);
  text-align: center;
`

const PlaylistList = styled.div`
  display: flex;
  flex-direction: column;
  gap: 0.125rem;
`

const PlaylistItem = styled.div`
  display: flex;
  align-items: center;
  border-radius: var(--radius-md);
  padding: 0.5rem 0.75rem;
  color: var(--color-muted-foreground);
  ${interactiveTransition(['background-color', 'color'])}

  &:hover {
    background-color: color-mix(in srgb, var(--color-accent) 50%, transparent);
    color: var(--color-accent-foreground);
  }
`

const PlaylistLink = styled(Link)`
  min-width: 0;
  flex: 1;
  text-align: left;
  text-decoration: none;
  color: inherit;
`

const PlaylistName = styled.p`
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: 0.875rem;
  font-weight: 500;
`

const PlaylistMeta = styled.p`
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: 10px;
  color: var(--color-muted-foreground);
`

const DeleteButton = styled.button`
  display: none;
  flex-shrink: 0;
  background: none;
  border: none;
  cursor: pointer;
  color: var(--color-muted-foreground);
  padding: 0;

  &:hover {
    color: var(--color-destructive);
  }

  ${PlaylistItem}:hover & {
    display: block;
  }
`

const SmallInput = styled(Input)`
  height: 1.75rem;
  font-size: 0.75rem;
`

const SmartToggleRow = styled.div`
  display: flex;
  align-items: center;
  gap: 0.5rem;
`

interface PlaylistItemData {
  publicId: string
  name: string
  description?: string
  isSmart?: boolean
  songCount?: number
}

export function PlaylistsPage() {
  const [showCreate, setShowCreate] = useState(false)
  const [newName, setNewName] = useState('')
  const [newDescription, setNewDescription] = useState('')
  const [isSmart, setIsSmart] = useState(false)
  const [smartRules, setSmartRules] = useState<SmartRule[]>([])
  const { data, isLoading, refetch } = useGetPlaylistIndex()
  const createPlaylist = usePostPlaylistStore()
  const deletePlaylist = useDeletePlaylistDestroy()

  const response = data as any
  const items = response?.data as PlaylistItemData[] | undefined

  const handleCreate = async () => {
    if (!newName.trim()) return
    try {
      await createPlaylist.mutateAsync({
        data: {
          name: newName.trim(),
          description: newDescription.trim() || null,
          isPublic: false,
          isCollaborative: false,
          isSmart,
          smartRules: isSmart
            ? smartRules.map((r) => ({ field: r.field, operator: r.operator, value: r.value }))
            : [],
        },
      })
      toast.success(isSmart ? 'Smart playlist created' : 'Playlist created')
      setShowCreate(false)
      setNewName('')
      setNewDescription('')
      setIsSmart(false)
      setSmartRules([])
      refetch()
    } catch {
      toast.error('Failed to create playlist')
    }
  }

  const handleDelete = async (publicId: string) => {
    try {
      await deletePlaylist.mutateAsync({ publicId })
      toast.success('Playlist deleted')
      refetch()
    } catch {
      toast.error('Failed to delete playlist')
    }
  }

  return (
    <PageContainer>
      <ContentArea>
        <PageHeader>
          <PageTitle>Playlists</PageTitle>
          <Button
            variant="ghost"
            size="icon-xs"
            onClick={() => setShowCreate(!showCreate)}
            aria-label="Create playlist"
          >
            <Plus size={14} />
          </Button>
        </PageHeader>

        {showCreate && (
          <CreateForm>
            <CreateFormInner>
              <SmallInput
                placeholder="Playlist name"
                value={newName}
                onChange={(e) => setNewName(e.target.value)}
                autoFocus
              />
              <SmallInput
                placeholder="Description (optional)"
                value={newDescription}
                onChange={(e) => setNewDescription(e.target.value)}
              />
              <SmartToggleRow>
                <SmartToggle
                  type="button"
                  $active={isSmart}
                  onClick={() => setIsSmart(!isSmart)}
                >
                  Smart playlist
                </SmartToggle>
              </SmartToggleRow>
              {isSmart && (
                <SmartPlaylistEditor rules={smartRules} onChange={setSmartRules} />
              )}
              <ActionRow>
                <Button size="xs" onClick={handleCreate} disabled={!newName.trim()}>
                  Create
                </Button>
                <Button
                  variant="ghost"
                  size="xs"
                  onClick={() => {
                    setShowCreate(false)
                    setNewName('')
                    setNewDescription('')
                    setIsSmart(false)
                    setSmartRules([])
                  }}
                >
                  Cancel
                </Button>
              </ActionRow>
            </CreateFormInner>
          </CreateForm>
        )}

        <ItemsArea>
          {isLoading ? (
            <SkeletonList>
              {Array.from({ length: 6 }).map((_, i) => (
                <Skeleton key={i} style={{ height: '2.25rem', width: '100%', borderRadius: 'var(--radius-md)' }} />
              ))}
            </SkeletonList>
          ) : !items?.length ? (
            <EmptyState>
              <EmptyText>No playlists yet</EmptyText>
              <Button variant="ghost" size="xs" onClick={() => setShowCreate(true)}>
                <Plus size={12} />
                Create one
              </Button>
            </EmptyState>
          ) : (
            <PlaylistList>
              {items.map((item: PlaylistItemData) => (
                <PlaylistItem key={item.publicId}>
                  <PlaylistLink to={`/music/playlists/${item.publicId}`}>
                    <PlaylistName>{item.name}</PlaylistName>
                    {item.isSmart && (
                      <PlaylistMeta>Smart</PlaylistMeta>
                    )}
                  </PlaylistLink>
                  <DeleteButton
                    type="button"
                    onClick={(e) => {
                      e.stopPropagation()
                      handleDelete(item.publicId)
                    }}
                    aria-label="Delete playlist"
                  >
                    <Trash2 size={12} />
                  </DeleteButton>
                </PlaylistItem>
              ))}
            </PlaylistList>
          )}
        </ItemsArea>
      </ContentArea>
    </PageContainer>
  )
}
