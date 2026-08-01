import { useState } from 'react'
import styled, { css } from 'styled-components'
import { type Genre } from '../../api/genre-admin-api'
import { ChevronRight, Pencil, Trash2 } from 'lucide-react'
import { useDeleteGenre } from '../../hooks/use-genre-admin'
import { interactiveTransition } from '@/shared/theme'

interface GenreTreeNode extends Genre {
  children: GenreTreeNode[]
}

function buildGenreTree(genres: Genre[]): GenreTreeNode[] {
  const map = new Map<string, GenreTreeNode>()
  const roots: GenreTreeNode[] = []

  for (const g of genres) {
    map.set(g.uuid, { ...g, children: [] })
  }

  for (const node of map.values()) {
    if (node.parentId && map.has(node.parentId)) {
      map.get(node.parentId)!.children.push(node)
    } else {
      roots.push(node)
    }
  }

  return roots
}

const TreeContainer = styled.div`
  border-radius: var(--radius-lg, 0.5rem);
  border: 1px solid var(--color-border);
  background-color: var(--color-card);
  padding: 0.5rem;
`

const EmptyContainer = styled.div`
  border-radius: var(--radius-lg, 0.5rem);
  border: 1px solid var(--color-border);
  background-color: var(--color-card);
  padding: 2rem;
  text-align: center;
`

const EmptyText = styled.p`
  font-size: 0.875rem;
  color: var(--color-muted-foreground);
`

const NodeRow = styled.div`
  display: flex;
  align-items: center;
  gap: 0.5rem;
  border-radius: 0.25rem;
  padding: 0.375rem 0.5rem;
  ${interactiveTransition(['background-color'])}

  &:hover { background-color: color-mix(in srgb, var(--color-accent) 30%, transparent); }
`

const ExpandButton = styled.button<{ $hasChildren: boolean }>`
  display: flex;
  height: 1.25rem;
  width: 1.25rem;
  flex-shrink: 0;
  align-items: center;
  justify-content: center;
  border-radius: 0.25rem;

  ${({ $hasChildren }) => $hasChildren
    ? css`
        color: var(--color-muted-foreground);
        &:hover { color: var(--color-foreground); }
      `
    : css`
        color: transparent;
      `
  }
`

const ChevronIcon = styled(ChevronRight)<{ $expanded: boolean }>`
  transition: transform 80ms;

  ${({ $expanded }) => $expanded && css`transform: rotate(90deg);`}
`

const NodeName = styled.span`
  flex: 1;
  font-size: 13px;
`

const MbidLabel = styled.span`
  font-size: 10px;
  color: var(--color-muted-foreground);
  font-family: monospace;
`

const Actions = styled.div`
  display: flex;
  gap: 0.25rem;
  opacity: 0;

  ${NodeRow}:hover & { opacity: 1; }
`

const ActionButton = styled.button`
  border-radius: 0.25rem;
  padding: 0.25rem;
  color: var(--color-muted-foreground);

  &:hover { color: var(--color-foreground); }
`

const DeleteActionButton = styled.button`
  border-radius: 0.25rem;
  padding: 0.25rem;
  color: var(--color-muted-foreground);

  &:hover { color: var(--color-destructive); }
`

function TreeNode({
  node,
  depth,
  onEdit,
  allGenres,
}: {
  node: GenreTreeNode
  depth: number
  onEdit: (genre: Genre) => void
  allGenres: Genre[]
}) {
  const [expanded, setExpanded] = useState(depth < 1)
  const hasChildren = node.children.length > 0
  const deleteGenre = useDeleteGenre()

  return (
    <div>
      <NodeRow style={{ paddingLeft: `${depth * 20 + 8}px` }}>
        <ExpandButton
          type="button"
          $hasChildren={hasChildren}
          onClick={() => hasChildren && setExpanded(!expanded)}
        >
          <ChevronIcon size={12} strokeWidth={1.5} $expanded={expanded} />
        </ExpandButton>
        <NodeName>{node.name}</NodeName>
        {node.mbid && <MbidLabel>MBID</MbidLabel>}
        <Actions>
          <ActionButton type="button" onClick={() => onEdit(node)}>
            <Pencil size={12} strokeWidth={1.5} />
          </ActionButton>
          <DeleteActionButton type="button" onClick={() => deleteGenre.mutate(node.slug)}>
            <Trash2 size={12} strokeWidth={1.5} />
          </DeleteActionButton>
        </Actions>
      </NodeRow>
      {expanded &&
        node.children.map((child) => (
          <TreeNode
            key={child.uuid}
            node={child}
            depth={depth + 1}
            onEdit={onEdit}
            allGenres={allGenres}
          />
        ))}
    </div>
  )
}

export function GenreTree({
  genres,
  onEdit,
}: {
  genres: Genre[]
  onEdit?: (genre: Genre) => void
}) {
  const tree = buildGenreTree(genres)

  if (tree.length === 0) {
    return (
      <EmptyContainer>
        <EmptyText>No genres to display.</EmptyText>
      </EmptyContainer>
    )
  }

  return (
    <TreeContainer>
      {tree.map((node) => (
        <TreeNode
          key={node.uuid}
          node={node}
          depth={0}
          onEdit={onEdit ?? (() => {})}
          allGenres={genres}
        />
      ))}
    </TreeContainer>
  )
}
