import { useId, useRef, useState } from 'react'
import styled from 'styled-components'
import { textRole } from '@/shared/theme'
import {
  useMetadataSyncStatus,
  useMetadataProviders,
  useSetMetadataProviderEnabled,
  useTriggerMetadataSync,
  useTriggerGenreSync,
} from '../hooks/use-metadata-admin'
import { useGenres } from '../hooks/use-genre-admin'
import { useAdminCheck } from '@/features/auth/hooks/use-admin-check'
import { Database, RefreshCw, CheckCircle2, XCircle, Clock, AlertTriangle, Tags } from 'lucide-react'
import { Button } from '@/shared/components/ui/button'
import { Switch } from '@/shared/components/ui/switch'
import { StatCard } from '@/shared/components/stat-card'
import { MusicBrainzMatchDialog } from '@/features/catalog/components/metadata/MusicBrainzMatchDialog'
import { MatchReviewTable } from '../components/matching/MatchReviewTable'
import { VideoMatchReviewTable } from '../components/matching/VideoMatchReviewTable'
import type { MatchReview } from '../api/match-reviews-api'
import type { MetadataProvider } from '../api/metadata-admin-api'

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
  ${textRole('subtitle')}
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
  ${textRole('body-sm')}
  font-weight: 500;
  width: 8rem;
`

const StatIcon = styled.span`
  display: flex;
  align-items: center;
  gap: 0.375rem;
`

const StatNum = styled.span`
  ${textRole('mono-sm')}
`

const ConfigRow = styled.div`
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  padding: 0.75rem 1rem;
`

const ConfigName = styled.span`
  ${textRole('body-sm')}
`

const ConfigStatus = styled.div`
  display: flex;
  align-items: center;
  gap: 1rem;
`

const ConfigLabel = styled.span<{ $ok?: boolean }>`
  ${textRole('label')}
  ${({ $ok }) => $ok ? 'color: var(--color-success);' : 'color: var(--color-muted-foreground);'}
`

const ProviderNote = styled.p`
  ${textRole('caption')}
  color: var(--color-muted-foreground);
`

const ReviewsGrid = styled.div`
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  gap: 1.5rem;

  @media (min-width: 1280px) {
    grid-template-columns: repeat(2, minmax(0, 1fr));
    align-items: start;
  }
`

const ReviewSection = styled.section`
  min-width: 0;
`

const ReviewTitle = styled.h2`
  ${textRole('subtitle')}
  margin-bottom: 1rem;
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
  border: 1px solid var(--color-muted);
  background: var(--color-card);
  padding: 1rem;
`

const ErrorText = styled.p`
  ${textRole('body')}
  color: var(--color-destructive);
`

const ErrorDetail = styled.p`
  ${textRole('mono-sm')}
  color: var(--color-muted-foreground);
  margin-top: 0.25rem;
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

function SyncActions() {
  const triggerSync = useTriggerMetadataSync()
  const triggerGenreSync = useTriggerGenreSync()
  return (
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
  )
}

function SyncSummary() {
  const { data: syncStatus, isPending, error, refetch, isFetching } = useMetadataSyncStatus()
  const { data: genres } = useGenres()

  if (isPending) {
    return (
      <StatsGrid role="status" aria-label="Loading metadata sync status">
        {Array.from({ length: 4 }).map((_, i) => (
          <LoadingCard key={i} />
        ))}
      </StatsGrid>
    )
  }

  if (error && !syncStatus) {
    return (
      <ErrorCard role="alert">
        <ErrorText>Failed to load metadata status.</ErrorText>
        <ErrorDetail>{error.message}</ErrorDetail>
        <Button size="sm" variant="ghost" disabled={isFetching} onClick={() => refetch()}>
          Retry sync status
        </Button>
      </ErrorCard>
    )
  }

  const total = syncStatus?.totalTracks ?? 0
  const synced = syncStatus?.syncedTracks ?? 0
  const pending = syncStatus?.pendingTracks ?? 0
  const failed = syncStatus?.failedTracks ?? 0
  const coverage = total > 0 ? ((synced / total) * 100).toFixed(1) : '0.0'

  return (
    <>
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

      {/* Sync jobs by type, as the job monitor records them */}
      {syncStatus?.sources && syncStatus.sources.length > 0 && (
        <Card>
          <CardHeader>
            <CardTitle>Sync Jobs</CardTitle>
          </CardHeader>
          <Divider>
            {syncStatus!.sources.map((source: { name: string; synced: number; failed: number }) => (
              <ProviderRow key={source.name}>
                <ProviderName>{source.name}</ProviderName>
                <StatIcon>
                  <CheckCircle2 size={12} strokeWidth={1.5} style={{ color: 'var(--color-success)' }} />
                  <StatNum>{source.synced.toLocaleString()}</StatNum>
                </StatIcon>
                {source.failed > 0 && (
                  <StatIcon>
                    <AlertTriangle size={12} strokeWidth={1.5} style={{ color: 'var(--color-warning)' }} />
                    <StatNum>{source.failed.toLocaleString()}</StatNum>
                  </StatIcon>
                )}
              </ProviderRow>
            ))}
          </Divider>
        </Card>
      )}

    </>
  )
}

function providerWaitDescription(id: string) {
  switch (id) {
    case 'musicbrainz':
      return 'Automatic album matching waits while MusicBrainz is off.'
    case 'tvdb':
    case 'tmdb':
    case 'local':
      return 'Refreshes for titles matched with this provider wait while it is off.'
    default:
      return 'Metadata updates from this provider wait while it is off.'
  }
}

function ProviderSwitch({ provider, canManage }: { provider: MetadataProvider; canManage: boolean }) {
  const nameId = useId()
  const descriptionId = useId()
  const setEnabled = useSetMetadataProviderEnabled(provider.id)
  return (
    <ConfigRow>
      <div>
        <ConfigName id={nameId}>{provider.name}</ConfigName>
        <ProviderNote id={descriptionId}>{providerWaitDescription(provider.id)}</ProviderNote>
        {setEnabled.isError && (
          <ErrorText role="alert">Could not change {provider.name}. {setEnabled.error.message}</ErrorText>
        )}
      </div>
      <ConfigStatus>
        <ConfigLabel $ok={provider.configured}>
          {provider.configured ? 'Configured' : 'Not configured'}
        </ConfigLabel>
        <ConfigLabel $ok={provider.enabled}>{provider.enabled ? 'On' : 'Off'}</ConfigLabel>
        {canManage && (
          <Switch
            checked={provider.enabled}
            disabled={setEnabled.isPending}
            aria-labelledby={nameId}
            aria-describedby={descriptionId}
            onCheckedChange={(enabled) => setEnabled.mutate(enabled)}
          />
        )}
      </ConfigStatus>
    </ConfigRow>
  )
}

function MetadataProviders() {
  const { isAdmin } = useAdminCheck()
  const { data, isPending, isError, isFetching, refetch } = useMetadataProviders()

  return (
    <Card>
      <CardHeader>
        <CardTitle>Providers</CardTitle>
      </CardHeader>
      {isPending && <ConfigRow role="status">Loading metadata providers…</ConfigRow>}
      {isError && (
        <ConfigRow role="alert">
          <ErrorText>Unable to load metadata providers.</ErrorText>
          <Button size="sm" variant="ghost" disabled={isFetching} onClick={() => refetch()}>
            Retry providers
          </Button>
        </ConfigRow>
      )}
      {data && (
        <Divider>
          {data.map((provider) => <ProviderSwitch key={provider.id} provider={provider} canManage={isAdmin} />)}
        </Divider>
      )}
    </Card>
  )
}

function AlbumReviews() {
  const [matching, setMatching] = useState<MatchReview | null>(null)
  const headingId = useId()
  const heading = useRef<HTMLHeadingElement>(null)
  const trigger = useRef<HTMLButtonElement>(null)

  return (
    <ReviewSection aria-labelledby={headingId}>
      <ReviewTitle id={headingId} ref={heading} tabIndex={-1}>Album reviews</ReviewTitle>
      <MatchReviewTable onMatchAlbum={(review, button) => {
        trigger.current = button
        setMatching(review)
      }} />
      {matching !== null && (
        <MusicBrainzMatchDialog
          key={matching.albumPublicId}
          open
          onOpenChange={(open) => {
            if (!open) {
              setMatching(null)
              requestAnimationFrame(() => {
                if (trigger.current?.isConnected) trigger.current.focus()
                else heading.current?.focus()
              })
            }
          }}
          type="album"
          publicId={matching.albumPublicId}
          title={matching.title}
        />
      )}
    </ReviewSection>
  )
}

export function MetadataPage() {
  const { isSuperAdmin } = useAdminCheck()
  return (
    <Container>
      {isSuperAdmin && <SyncActions />}
      <SyncSummary />
      <MetadataProviders />
      <ReviewsGrid>
        <AlbumReviews />
        <VideoMatchReviewTable />
      </ReviewsGrid>
    </Container>
  )
}
