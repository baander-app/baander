import styled from 'styled-components'
import { useConfigCheck } from '../hooks/use-config-check'
import type { ConfigCheckResult } from '../api/config-check-api'
import { SectionCard } from '@/shared/components/section-card'
import { Settings2 } from 'lucide-react'

const CheckRowWrapper = styled.div`
  padding: 0 1rem;
  padding-top: 0.625rem;
  padding-bottom: 0.625rem;
  font-size: 0.875rem;
`

const CheckHeader = styled.div`
  display: flex;
  align-items: center;
  justify-content: space-between;
`

const ComponentName = styled.span`
  font-family: var(--font-mono);
  font-size: 0.75rem;
`

const StatusBadge = styled.span<{ $variant: 'ok' | 'fail' | 'warn' | 'na' }>`
  display: inline-flex;
  align-items: center;
  border-radius: 9999px;
  padding: 0.125rem 0.5rem;
  font-size: 0.75rem;
  font-weight: 500;

  ${({ $variant }) => {
    switch ($variant) {
      case 'ok':
        return `
          background: #dcfce7;
          color: #166534;
          [data-theme="dark"] & { background: #14532d; color: #bbf7d0; }
        `
      case 'fail':
        return `
          background: #fef2f2;
          color: #991b1b;
          [data-theme="dark"] & { background: #450a0a; color: #fca5a5; }
        `
      case 'warn':
        return `
          background: #fefce8;
          color: #854d0e;
          [data-theme="dark"] & { background: #422006; color: #fde047; }
        `
      case 'na':
        return `
          background: #f3f4f6;
          color: #4b5563;
          [data-theme="dark"] & { background: #1f2937; color: #9ca3af; }
        `
    }
  }}
`

const Message = styled.p<{ $variant: 'error' | 'warning' | 'default' }>`
  margin-top: 0.25rem;

  ${({ $variant }) => {
    switch ($variant) {
      case 'error':
        return 'color: var(--color-destructive);'
      case 'warning':
        return `
          color: #ca8a04;
          [data-theme="dark"] & { color: #facc15; }
        `
      default:
        return 'color: var(--color-muted-foreground);'
    }
  }}
`

const Suggestion = styled.p`
  margin-top: 0.125rem;
  font-size: 0.75rem;
  color: var(--color-muted-foreground);
`

function CheckRow({ result }: { result: ConfigCheckResult }) {
  const severity = result.details.severity
  const isError = severity === 'error' || result.status === 'unhealthy'
  const isWarning = severity === 'warning'

  const variant = result.status === 'healthy' ? 'ok' : isError ? 'fail' : isWarning ? 'warn' : 'na'
  const label = result.status === 'healthy' ? 'OK' : isError ? 'FAIL' : isWarning ? 'WARN' : 'N/A'
  const msgVariant = isError ? 'error' : isWarning ? 'warning' : 'default'

  return (
    <CheckRowWrapper>
      <CheckHeader>
        <ComponentName>{result.component}</ComponentName>
        <StatusBadge $variant={variant}>{label}</StatusBadge>
      </CheckHeader>
      {result.details.message && (
        <Message $variant={msgVariant}>{result.details.message}</Message>
      )}
      {result.details.suggestion && (
        <Suggestion>{result.details.suggestion}</Suggestion>
      )}
    </CheckRowWrapper>
  )
}

function groupByCategory(results: ConfigCheckResult[]): { category: string; results: ConfigCheckResult[] }[] {
  const groups: Record<string, ConfigCheckResult[]> = {}

  for (const r of results) {
    const category = r.component.startsWith('env') || r.component === 'app_secret' || r.component === 'oauth_encryption_key' || r.component === 'oauth_keys' || r.component === 'api_keys'
      ? 'Environment Variables'
      : 'Framework Config'
    if (!groups[category]) groups[category] = []
    groups[category].push(r)
  }

  return Object.entries(groups).map(([category, results]) => ({ category, results }))
}

const Container = styled.div`
  display: flex;
  flex-direction: column;
  gap: 1.25rem;
  padding: 1.5rem;
`

const HeaderRow = styled.div`
  display: flex;
  align-items: center;
  justify-content: space-between;
`

const TitleGroup = styled.div`
  display: flex;
  align-items: center;
  gap: 0.75rem;
`

const Title = styled.h1`
  font-size: 1.125rem;
  font-weight: 600;
  letter-spacing: -0.025em;
`

const RefetchButton = styled.button`
  border-radius: var(--radius-md);
  border: 1px solid var(--color-input);
  background: var(--color-background);
  padding: 0.375rem 0.75rem;
  font-size: 0.875rem;
  transition: background-color var(--duration-hover) ease-out, color var(--duration-hover) ease-out;

  &:hover {
    background: var(--color-highlight);
    color: var(--color-highlight-foreground);
  }

  &:disabled {
    opacity: 0.5;
  }
`

const SuccessBanner = styled.div`
  border-radius: var(--radius-md);
  border: 1px solid #bbf7d0;
  background: #f0fdf4;
  padding: 0.75rem 1rem;
  font-size: 0.875rem;
  color: #166534;

  [data-theme="dark"] & {
    border-color: #166534;
    background: #052e16;
    color: #bbf7d0;
  }
`

const SummaryRow = styled.div`
  display: flex;
  gap: 1rem;
`

const ErrorBanner = styled.div`
  border-radius: var(--radius-md);
  border: 1px solid #fecaca;
  background: #fef2f2;
  padding: 0.75rem 1rem;
  font-size: 0.875rem;
  color: #991b1b;

  [data-theme="dark"] & {
    border-color: #7f1d1d;
    background: #450a0a;
    color: #fca5a5;
  }
`

const WarnBanner = styled.div`
  border-radius: var(--radius-md);
  border: 1px solid #fde68a;
  background: #fefce8;
  padding: 0.75rem 1rem;
  font-size: 0.875rem;
  color: #854d0e;

  [data-theme="dark"] & {
    border-color: #854d0e;
    background: #422006;
    color: #fde047;
  }
`

const CategoryGrid = styled.div`
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 1rem;

  @media (max-width: 1024px) {
    grid-template-columns: 1fr;
  }
`

const Timestamp = styled.p`
  font-size: 0.75rem;
  color: var(--color-muted-foreground);
`

const Spinner = styled.div`
  height: 1.5rem;
  width: 1.5rem;
  animation: spin 1s linear infinite;
  border-radius: 50%;
  border: 2px solid color-mix(in srgb, var(--color-muted-foreground) 30%, transparent);
  border-top-color: var(--color-foreground);

  @keyframes spin {
    to { transform: rotate(360deg); }
  }
`

const CenteredSpinner = styled.div`
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 3rem;
`

const ErrorText = styled.p`
  margin-top: 0.5rem;
  font-size: 0.875rem;
  color: var(--color-destructive);
`

export function ConfigurationPage() {
  const { data, isLoading, error, refetch, isRefetching, dataUpdatedAt } = useConfigCheck()

  if (isLoading) {
    return <CenteredSpinner><Spinner /></CenteredSpinner>
  }

  if (error) {
    return (
      <Container>
        <ErrorText>Failed to load configuration check.</ErrorText>
      </Container>
    )
  }

  if (!data) return null

  const { results, summary } = data
  const allPassed = summary.errors === 0 && summary.warnings === 0
  const categories = groupByCategory(results)

  return (
    <Container>
      <HeaderRow>
        <TitleGroup>
          <Settings2 size={18} strokeWidth={1.5} style={{ color: 'var(--color-muted-foreground)' }} />
          <Title>Configuration</Title>
        </TitleGroup>
        <RefetchButton
          onClick={() => refetch()}
          disabled={isRefetching}
        >
          {isRefetching ? 'Running...' : 'Re-run checks'}
        </RefetchButton>
      </HeaderRow>

      {allPassed ? (
        <SuccessBanner>
          All {summary.passed} configuration checks passed.
        </SuccessBanner>
      ) : (
        <SummaryRow>
          {summary.errors > 0 && (
            <ErrorBanner>
              {summary.errors} error{summary.errors !== 1 ? 's' : ''}
            </ErrorBanner>
          )}
          {summary.warnings > 0 && (
            <WarnBanner>
              {summary.warnings} warning{summary.warnings !== 1 ? 's' : ''}
            </WarnBanner>
          )}
          {summary.passed > 0 && (
            <SuccessBanner>
              {summary.passed} passed
            </SuccessBanner>
          )}
        </SummaryRow>
      )}

      <CategoryGrid>
        {categories.map(({ category, results: catResults }) => (
          <SectionCard key={category} title={category}>
            {catResults.map((r, i) => (
              <CheckRow key={`${r.component}-${i}`} result={r} />
            ))}
          </SectionCard>
        ))}
      </CategoryGrid>

      {dataUpdatedAt > 0 && (
        <Timestamp>
          Last updated: {new Date(dataUpdatedAt).toLocaleTimeString()}
        </Timestamp>
      )}
    </Container>
  )
}
