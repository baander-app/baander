import styled from 'styled-components'
import type { ReactNode } from 'react'
import { interactiveTransition } from '@/shared/theme'

const Container = styled.div``

const Grid = styled.div`
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 1rem;

  @media (min-width: 640px) {
    grid-template-columns: repeat(3, 1fr);
  }
  @media (min-width: 768px) {
    grid-template-columns: repeat(4, 1fr);
  }
  @media (min-width: 1024px) {
    grid-template-columns: repeat(5, 1fr);
  }
  @media (min-width: 1280px) {
    grid-template-columns: repeat(6, 1fr);
  }
`

const Pagination = styled.div`
  margin-top: 1.5rem;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 0.5rem;
`

const PageButton = styled.button`
  border-radius: var(--radius-md);
  border: 1px solid var(--color-border);
  padding: 0.375rem 0.75rem;
  font-size: 0.875rem;
  ${interactiveTransition(['color', 'background-color'])}
  background: none;
  cursor: pointer;

  &:hover {
    background-color: var(--color-accent);
  }

  &:disabled {
    opacity: 0.5;
    cursor: not-allowed;
  }
`

const PageInfo = styled.span`
  font-size: 0.875rem;
  color: var(--color-muted-foreground);
`

interface PaginationInfo {
  currentPage: number
  lastPage: number
  total: number
}

interface PaginatedGridProps {
  pagination: PaginationInfo
  onPageChange: (page: number) => void
  children: ReactNode
}

export function PaginatedGrid({ pagination, onPageChange, children }: PaginatedGridProps) {
  return (
    <Container>
      <Grid>
        {children}
      </Grid>
      {pagination.lastPage > 1 && (
        <Pagination>
          <PageButton
            onClick={() => onPageChange(pagination.currentPage - 1)}
            disabled={pagination.currentPage <= 1}
          >
            Previous
          </PageButton>
          <PageInfo>
            {pagination.currentPage} / {pagination.lastPage}
          </PageInfo>
          <PageButton
            onClick={() => onPageChange(pagination.currentPage + 1)}
            disabled={pagination.currentPage >= pagination.lastPage}
          >
            Next
          </PageButton>
        </Pagination>
      )}
    </Container>
  )
}
