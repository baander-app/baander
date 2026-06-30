import styled, { css } from 'styled-components'
import { useState } from 'react'
import { useGenres } from '../hooks/use-genre-admin'
import { type Genre } from '../api/genre-admin-api'
import { Button } from '@/shared/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/shared/components/ui/dropdown-menu'
import { MoreHorizontal, Plus, Trash2, Pencil, Tags } from 'lucide-react'
import { GenreTree } from '../components/media/GenreTree'
import { GenreDialog } from '../components/media/GenreDialog'
import { DeleteGenreDialog } from '../components/media/DeleteGenreDialog'
import { EmptyState } from '@/shared/components/empty-state'

const Container = styled.div`
  display: flex;
  flex-direction: column;
  gap: 1.5rem;
  padding: 1.5rem;
`

const HeaderRow = styled.div`
  display: flex;
  justify-content: flex-end;
`

const HeaderActions = styled.div`
  display: flex;
  align-items: center;
  gap: 0.5rem;
`

const ViewToggle = styled.div`
  display: flex;
  border-radius: var(--radius-md);
  border: 1px solid var(--color-border);
`

const ViewButton = styled.button<{ $active: boolean }>`
  padding: 0.375rem 0.75rem;
  font-size: 0.75rem;
  transition: background-color var(--duration-hover) ease-out;

  ${({ $active }) => $active ? css`
    background: var(--color-highlight);
    font-weight: 500;
  ` : css`
    color: var(--color-muted-foreground);
  `}
`

const Card = styled.div`
  border-radius: var(--radius-lg);
  border: 1px solid var(--color-border);
  background: var(--color-card);
`

const StyledTable = styled.table`
  width: 100%;
`

const HeadRow = styled.tr`
  border-bottom: 1px solid var(--color-border);
  text-align: left;
  font-size: 0.6875rem;
  font-weight: 500;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--color-muted-foreground);
`

const Th = styled.th`
  padding: 0.75rem 1rem;
`

const ThAction = styled.th`
  width: 2.5rem;
  padding: 0.75rem 1rem;
`

const Divider = styled.tbody`
  & > tr + tr {
    border-top: 1px solid var(--color-border);
  }
`

const BodyRow = styled.tr`
  &:hover ${() => ''} {
    /* group-hover handled via DropdownMenuTrigger */
  }
`

const TdName = styled.td`
  padding: 0.625rem 1rem;
  font-size: 0.8125rem;
  font-weight: 500;
`

const TdMono = styled.td`
  padding: 0.625rem 1rem;
  font-size: 0.8125rem;
  color: var(--color-muted-foreground);
  font-family: var(--font-mono);
`

const TdMuted = styled.td`
  padding: 0.625rem 1rem;
  font-size: 0.8125rem;
  color: var(--color-muted-foreground);
`

const TdAction = styled.td`
  padding: 0.625rem 1rem;
`

const StyledTrigger = styled(DropdownMenuTrigger)`
  opacity: 0;

  ${BodyRow}:hover & {
    opacity: 1;
  }
`

const LoadingRow = styled.div`
  height: 2.5rem;
  border-radius: var(--radius-md);
  background: var(--color-muted);
  animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;

  @keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.5; }
  }
`

const LoadingStack = styled.div`
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
`

export function GenresPage() {
  const { data: genres, isLoading } = useGenres()
  const [view, setView] = useState<'list' | 'tree'>('list')
  const [dialogOpen, setDialogOpen] = useState(false)
  const [editingGenre, setEditingGenre] = useState<Genre | null>(null)
  const [deletingGenre, setDeletingGenre] = useState<Genre | null>(null)

  if (isLoading) {
    return (
      <Container>
        <LoadingStack>
          {Array.from({ length: 8 }).map((_, i) => (
            <LoadingRow key={i} />
          ))}
        </LoadingStack>
      </Container>
    )
  }

  const genreList = genres ?? []

  return (
    <Container>
      <HeaderRow>
        <HeaderActions>
          <ViewToggle>
            <ViewButton $active={view === 'list'} onClick={() => setView('list')}>
              List
            </ViewButton>
            <ViewButton $active={view === 'tree'} onClick={() => setView('tree')}>
              Tree
            </ViewButton>
          </ViewToggle>
          <Button
            size="sm"
            onClick={() => {
              setEditingGenre(null)
              setDialogOpen(true)
            }}
          >
            <Plus size={14} strokeWidth={1.5} />
            <span style={{ marginLeft: '0.375rem' }}>Create Genre</span>
          </Button>
        </HeaderActions>
      </HeaderRow>

      {genreList.length === 0 ? (
        <EmptyState message="No genres yet. Create one or run a genre sync." icon={<Tags size={32} strokeWidth={1} />} />
      ) : view === 'tree' ? (
        <GenreTree genres={genreList} />
      ) : (
        <Card>
          <StyledTable>
            <thead>
              <HeadRow>
                <Th>Name</Th>
                <Th>Slug</Th>
                <Th>MBID</Th>
                <Th>Parent</Th>
                <ThAction />
              </HeadRow>
            </thead>
            <Divider>
              {genreList.map((genre) => {
                const parent = genre.parentId
                  ? genreList.find((g) => g.uuid === genre.parentId)
                  : null

                return (
                  <BodyRow key={genre.uuid}>
                    <TdName>{genre.name}</TdName>
                    <TdMono>{genre.slug}</TdMono>
                    <TdMono>
                      {genre.mbid
                        ? `${genre.mbid.slice(0, 8)}...`
                        : '-'}
                    </TdMono>
                    <TdMuted>{parent?.name ?? '-'}</TdMuted>
                    <TdAction>
                      <DropdownMenu>
                        <StyledTrigger>
                          <MoreHorizontal size={14} strokeWidth={1.5} />
                        </StyledTrigger>
                        <DropdownMenuContent align="end">
                          <DropdownMenuItem
                            onClick={() => {
                              setEditingGenre(genre)
                              setDialogOpen(true)
                            }}
                          >
                            <Pencil size={13} style={{ marginRight: '0.5rem' }} />
                            Edit
                          </DropdownMenuItem>
                          <DropdownMenuItem
                            style={{ color: 'var(--color-destructive)' }}
                            onClick={() => setDeletingGenre(genre)}
                          >
                            <Trash2 size={13} style={{ marginRight: '0.5rem' }} />
                            Delete
                          </DropdownMenuItem>
                        </DropdownMenuContent>
                      </DropdownMenu>
                    </TdAction>
                  </BodyRow>
                )
              })}
            </Divider>
          </StyledTable>
        </Card>
      )}

      <GenreDialog
        open={dialogOpen}
        onOpenChange={setDialogOpen}
        genre={editingGenre}
        genres={genreList}
      />
      <DeleteGenreDialog
        open={deletingGenre !== null}
        onOpenChange={(v) => !v && setDeletingGenre(null)}
        genre={deletingGenre}
      />
    </Container>
  )
}
