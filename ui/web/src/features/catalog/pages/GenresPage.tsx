import styled from 'styled-components'
import { useState } from 'react'
import { useGetGenreIndex } from '@/shared/api-client/gen/endpoints'
import { useNavigate } from 'react-router-dom'
import { Skeleton } from '@/shared/components/ui/skeleton'
import { Button } from '@/shared/components/ui/button'
import { Plus } from 'lucide-react'
import { useAuthStore } from '@/features/auth/stores/auth-store'
import { CreateGenreDialog } from '../components/CreateGenreDialog'
import { focusVisibleRing } from '@/shared/theme'

const PageContainer = styled.div`
  display: flex;
  height: 100%;
  flex-direction: column;
`

const PageHeader = styled.div`
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 1rem 1.5rem;
`

const PageTitle = styled.h1`
  font-size: 1.125rem;
  font-weight: 600;
  letter-spacing: -0.025em;
`

const ContentArea = styled.div`
  flex: 1;
  overflow-y: auto;
  padding: 0 1.5rem 1.5rem;
`

const ResponsiveGrid = styled.div`
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 1rem;

  @media (min-width: 640px) { grid-template-columns: repeat(3, 1fr); }
  @media (min-width: 768px) { grid-template-columns: repeat(4, 1fr); }
  @media (min-width: 1024px) { grid-template-columns: repeat(5, 1fr); }
  @media (min-width: 1280px) { grid-template-columns: repeat(6, 1fr); }
`

const GenreButton = styled.button`
  display: flex;
  height: 5rem;
  align-items: center;
  justify-content: center;
  border-radius: var(--radius-lg);
  background-color: var(--color-card);
  transition: all 150ms;
  ${focusVisibleRing}
  border: none;
  cursor: pointer;

  &:hover {
    transform: translateY(-2px);
    box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
    background-color: color-mix(in srgb, var(--color-accent) 50%, transparent);
  }
`

const GenreName = styled.p`
  font-size: 0.875rem;
  font-weight: 500;
  color: var(--color-foreground);
  text-align: center;
`

const LoadingCard = styled.div`
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
`

const EmptyContainer = styled.div`
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 0.75rem;
  padding: 5rem 0;
`

const EmptyIcon = styled.svg`
  color: color-mix(in srgb, var(--color-muted-foreground) 30%, transparent);
`

const EmptyTitle = styled.p`
  font-size: 0.875rem;
  color: var(--color-muted-foreground);
`

export function GenresPage() {
  const { data, isLoading, refetch } = useGetGenreIndex()
  const navigate = useNavigate()
  const items = data?.data as Record<string, unknown>[] | undefined
  const isAdmin = useAuthStore((s) => s.user?.roles?.includes('ROLE_ADMIN') ?? false)
  const [createOpen, setCreateOpen] = useState(false)

  return (
    <PageContainer>
      <PageHeader>
        <PageTitle>Genres</PageTitle>
        {isAdmin && (
          <Button variant="ghost" size="sm" onClick={() => setCreateOpen(true)}>
            <Plus size={14} />
            Create
          </Button>
        )}
      </PageHeader>

      <ContentArea>
        {isLoading ? (
          <ResponsiveGrid>
            {Array.from({ length: 8 }).map((_, i) => (
              <LoadingCard key={i}>
                <Skeleton style={{ height: '5rem', borderRadius: 'var(--radius-lg)' }} />
                <Skeleton style={{ height: '1rem', width: '6rem' }} />
              </LoadingCard>
            ))}
          </ResponsiveGrid>
        ) : !items?.length ? (
          <EmptyGenresState />
        ) : (
          <ResponsiveGrid>
            {items.map((genre) => (
              <GenreButton
                key={genre.slug as string}
                type="button"
                onClick={() => navigate(`/music/albums?genre=${encodeURIComponent(genre.slug as string)}`)}
              >
                <GenreName>{(genre.name as string) ?? 'Unknown'}</GenreName>
              </GenreButton>
            ))}
          </ResponsiveGrid>
        )}
      </ContentArea>

      <CreateGenreDialog
        open={createOpen}
        onOpenChange={setCreateOpen}
        onCreated={() => refetch()}
      />
    </PageContainer>
  )
}

function EmptyGenresState() {
  return (
    <EmptyContainer>
      <EmptyIcon width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round">
        <path d="M12 2L2 7l10 5 10-5-10-5z" />
        <path d="M2 17l10 5 10-5" />
        <path d="M2 12l10 5 10-5" />
      </EmptyIcon>
      <EmptyTitle>No genres found</EmptyTitle>
    </EmptyContainer>
  )
}
