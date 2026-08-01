import styled from 'styled-components'
import { useMetadataSyncStatus, useMetadataProviders, useTriggerMetadataSync, useTriggerGenreSync } from '../hooks/use-metadata-admin'
import { useGenres } from '../hooks/use-genre-admin'
import { useAuthStore } from '@/features/auth/stores/auth-store'
import { Database, RefreshCw, CheckCircle2, XCircle, Clock, AlertTriangle, Tags } from 'lucide-react'
import { Button } from '@/shared/components/ui/button'
import { StatCard } from '@/shared/components/stat-card'

const Container = styled.div`
  display: flex;
  flex-direction: column;
  gap: 1.5rem;
  padding: 1.5rem;
`

const ActionsRow = styled.div`
  display: flex;
  justify-content: flex-end;
  gap: 0.5rem;
`

const StatsGrid = styled.div`
  display: grid;
  grid-template-columns: repeat(6, 1fr);
  gap: 1rem;

  @media (max-width: 1024px) {
    grid-template-columns: repeat(2, 1fr);
  }

  @media (max-width: 640px) {
    grid-template-columns: 1fr;
  }
`

const Card = styled.div`
  border-radius: var(--radius-lg);
  border: 1px solid var(--color-border);
  background: var(--color-card);
`

const CardHeader = styled.div`
  border-bottom: 1px solid var(--color-border);
  padding: 0.75rem 1rem;
`

const CardTitle = styled.h2`
  font-size: 0.8125rem;
  font-weight: 500;
`

const Divider = styled.div`
  & > div + div {
    border-top: 1px solid var(--color-border);
  }
`

const ProviderRow = styled.div`
  display: flex;
  align-items: center;
  gap: 1rem;
  padding: 0.75rem 1rem;
`

const ProviderName = styled.span`
  font-size: 0.8125rem;
  font-weight: 500;
  width: 8rem;
`

const StatIcon = styled.span`
  display: flex;
  align-items: center;
  gap: 0.375rem;
`

const StatNum = styled.span`
  font-size: 0.8125rem;
  font-variant-numeric: tabular-nums;
`

const ConfigRow = styled.div`
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0.75rem 1rem;
`

const ConfigName = styled.span`
  font-size: 0.8125rem;
`

const ConfigStatus = styled.div`
  display: flex;
  align-items: center;
  gap: 0.75rem;
`

const ConfigLabel = styled.span<{ $ok?: boolean }>`
  font-size: 0.6875rem;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  ${({ $ok }) => $ok ? 'color: #10b981;' : 'color: var(--color-muted-foreground);'}
`

const ConfigDot = styled.span<{ $active?: boolean }>`
  display: inline-block;
  height: 0.375rem;
  width: 0.375rem;
  border-radius: 50%;
  ${({ $active }) => $active ? 'background: #10b981;' : 'background: color-mix(in srgb, var(--color-muted-foreground) 30%, transparent);'}
`

const LoadingCard = styled.div`
  height: 7rem;
  border-radius: var(--radius-lg);
  border: 1px solid var(--color-border);
  background: var(--color-card);
  animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;

  @keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.5; }
  }
`

const ErrorCard = styled.div`
  border-radius: var(--radius-lg);
  border: 1px solid color-mix(in srgb, var(--color-destructive) 30%, transparent);
  background: var(--color-card);
  padding: 1rem;
`

const ErrorText = styled.p`
  font-size: 0.875rem;
  color: var(--color-destructive);
`

const ErrorDetail = styled.p`
  font-size: 0.6875rem;
  color: var(--color-muted-foreground);
  margin-top: 0.25rem;
  font-family: var(--font-mono);
`

function formatTime(iso: string | null): string {
  if (!iso) return 'Never'
  const d = new Date(iso)
  const now = new Date()
  const diffMs = now.getTime() - d.getTime()
  const diffMins = Math.floor(diffMs / 60_000)
  if (diffMins < 1) return 'Just now'
  if (diffMins < 60) return `${diffMins}m ago`
  const diffHours = Math.floor(diffMins / 60)
  if (diffHours < 24) return `${diffHours}h ago`
  return d.toLocaleDateString()
}

export function MetadataPage() {
  const { data: syncStatus, isLoading, error } = useMetadataSyncStatus()
  const { data: providers } = useMetadataProviders()
  const triggerSync = useTriggerMetadataSync()
  const triggerGenreSync = useTriggerGenreSync()
  const { data: genres } = useGenres()
  const roles = useAuthStore((s) => s.user?.roles ?? [])
  const isSuperAdmin = roles.includes('ROLE_SUPER_ADMIN')

  if (isLoading) {
    return (
      <Container>
        <StatsGrid>
          {Array.from({ length: 4 }).map((_, i) => (
            <LoadingCard key={i} />
          ))}
        </StatsGrid>
      </Container>
    )
  }

  if (error) {
    return (
      <Container>
        <ErrorCard>
          <ErrorText>Failed to load metadata status.</ErrorText>
          <ErrorDetail>
            {error instanceof Error ? error.message : 'Unknown error'}
          </ErrorDetail>
        </ErrorCard>
      </Container>
    )
  }

  const total = syncStatus?.totalTracks ?? 0
  const synced = syncStatus?.syncedTracks ?? 0
  const pending = syncStatus?.pendingTracks ?? 0
  const failed = syncStatus?.failedTracks ?? 0
  const coverage = total > 0 ? ((synced / total) * 100).toFixed(1) : '0.0'

  return (
    <Container>
      {isSuperAdmin && (
        <ActionsRow>
          <Button
            variant="ghost"
            size="sm"
            onClick={() => triggerSync.mutate(undefined)}
            disabled={triggerSync.isPending}
          >
            <RefreshCw size={14} strokeWidth={1.5} style={{ animation: triggerSync.isPending ? 'spin 1s linear infinite' : 'none' }} />
            <span style={{ marginLeft: '0.375rem' }}>Trigger Sync</span>
          </Button>
          <Button
            variant="ghost"
            size="sm"
            onClick={() => triggerGenreSync.mutate()}
            disabled={triggerGenreSync.isPending}
          >
            <Tags size={14} strokeWidth={1.5} style={{ animation: triggerGenreSync.isPending ? 'spin 1s linear infinite' : 'none' }} />
            <span style={{ marginLeft: '0.375rem' }}>Sync Genres</span>
          </Button>
        </ActionsRow>
      )}

      {/* Coverage stats */}
      <StatsGrid>
        <StatCard label="Total Tracks" value={total.toLocaleString()} icon={Database} />
        <StatCard
          label="Synced"
          value={synced.toLocaleString()}
          sub={`${coverage}% coverage`}
          icon={CheckCircle2}
        />
        <StatCard label="Pending" value={pending.toLocaleString()} icon={Clock} />
        <StatCard label="Failed" value={failed.toLocaleString()} icon={XCircle} />
        <StatCard
          label="Last Sync"
          value={formatTime(syncStatus?.lastSyncAt ?? null)}
          icon={RefreshCw}
        />
        <StatCard
          label="Genres"
          value={(genres?.length ?? 0).toLocaleString()}
          icon={Tags}
        />
      </StatsGrid>

      {/* Provider breakdown */}
      {syncStatus?.sources && syncStatus.sources.length > 0 && (
        <Card>
          <CardHeader>
            <CardTitle>Providers</CardTitle>
          </CardHeader>
          <Divider>
            {syncStatus!.sources.map((source: { name: string; synced: number; failed: number }) => (
              <ProviderRow key={source.name}>
                <ProviderName>{source.name}</ProviderName>
                <StatIcon>
                  <CheckCircle2 size={12} strokeWidth={1.5} style={{ color: '#10b981' }} />
                  <StatNum>{source.synced.toLocaleString()}</StatNum>
                </StatIcon>
                {source.failed > 0 && (
                  <StatIcon>
                    <AlertTriangle size={12} strokeWidth={1.5} style={{ color: '#f59e0b' }} />
                    <StatNum>{source.failed.toLocaleString()}</StatNum>
                  </StatIcon>
                )}
              </ProviderRow>
            ))}
          </Divider>
        </Card>
      )}

      {/* Provider configuration */}
      {providers && providers.length > 0 && (
        <Card>
          <CardHeader>
            <CardTitle>Configuration</CardTitle>
          </CardHeader>
          <Divider>
            {providers!.map((provider: { name: string; enabled: boolean; configured: boolean }) => (
              <ConfigRow key={provider.name}>
                <ConfigName>{provider.name}</ConfigName>
                <ConfigStatus>
                  <ConfigLabel $ok={provider.configured}>
                    {provider.configured ? 'Configured' : 'Not configured'}
                  </ConfigLabel>
                  <ConfigDot $active={provider.enabled && provider.configured} />
                </ConfigStatus>
              </ConfigRow>
            ))}
          </Divider>
        </Card>
      )}
    </Container>
  )
}
