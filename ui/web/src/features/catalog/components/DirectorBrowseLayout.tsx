import type { FormEvent, ReactNode, Ref } from 'react'
import styled from 'styled-components'
import { Search } from 'lucide-react'
import { Button } from '@/shared/components/ui/button'
import { Input } from '@/shared/components/ui/input'
import { Skeleton } from '@/shared/components/ui/skeleton'
import { textRole } from '@/shared/theme'
import { ContentText } from '@/shared/typography'

const Container = styled.section`
  height: 100%;
  overflow-y: auto;
  padding: var(--space-lg);
`
const Header = styled.header`
  display: flex;
  flex-direction: column;
  gap: var(--space-md);
  margin-bottom: var(--space-lg);
`
const SearchForm = styled.form`
  display: flex;
  gap: var(--space-sm);
  max-width: 32rem;
`
const Message = styled.p`
  ${textRole('body')}
  color: var(--color-muted-foreground);
  margin-block: var(--space-md);
`
const ErrorMessage = styled(Message)`
  color: var(--color-destructive);
`

export interface DirectorBrowsePosition {
  search: string
  scrollTop: number
  selectedId: string
}

export interface DirectorNavigationState {
  directorBrowse?: DirectorBrowsePosition
}

interface DirectorBrowseLayoutProps {
  title: string
  description?: string
  navigation?: ReactNode
  query: string
  searchLabel: string
  onSearch: (query: string) => void
  children: ReactNode
  containerRef?: Ref<HTMLElement>
}

export function DirectorBrowseLayout({
  title,
  description,
  navigation,
  query,
  searchLabel,
  onSearch,
  children,
  containerRef,
}: DirectorBrowseLayoutProps) {
  const submit = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    const form = new FormData(event.currentTarget)
    onSearch(String(form.get('q') ?? '').trim())
  }
  return (
    <Container ref={containerRef}>
      <Header>
        {navigation}
        <ContentText text={title} textRole="title-lg" element="h1" />
        {description ? <Message>{description}</Message> : null}
        <SearchForm onSubmit={submit} role="search" aria-label={searchLabel}>
          <Input key={query} name="q" defaultValue={query} aria-label={searchLabel} />
          <Button type="submit" variant="secondary">
            <Search size={16} aria-hidden="true" />
            Search
          </Button>
        </SearchForm>
      </Header>
      {children}
    </Container>
  )
}

export function DirectorBrowseLoading() {
  return (
    <div role="status" aria-label="Loading results">
      <Skeleton style={{ width: '100%', height: '12rem' }} />
    </div>
  )
}

export function DirectorBrowseError({ retry }: { retry: () => unknown }) {
  return (
    <div role="alert">
      <ErrorMessage>Unable to load results.</ErrorMessage>
      <Button variant="ghost" onClick={retry}>
        Retry
      </Button>
    </div>
  )
}

export function DirectorBrowseEmpty({ children }: { children: ReactNode }) {
  return <Message role="status">{children}</Message>
}
