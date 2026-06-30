import styled, { css } from 'styled-components'
import { useServerStats } from '../hooks/use-server-stats'
import { useCoroutineStats, useWorkerStats, useSpans, useClearSpans } from '../hooks/use-debug-stats'
import { LiveHealthBar } from '../components/dashboard/LiveHealthBar'
import { useDevPanelStore } from '@/shared/stores/dev-panel-store'
import { SectionCard } from '@/shared/components/section-card'
import { KVRow } from '@/shared/components/kv-row'
import { formatUptime } from '@/shared/utils/format-human'
import type { RedisStats } from '../api/server-stats-api'
import type { Span } from '../api/debug-api'
import { Loader } from 'lucide-react'

const Container = styled.div`
  display: flex;
  flex-direction: column;
  gap: 1.25rem;
  padding: 1.5rem;
`

const FlexBetween = styled.div`
  display: flex;
  align-items: center;
  justify-content: space-between;
`

const Grid = styled.div`
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 1rem;

  @media (max-width: 1024px) {
    grid-template-columns: 1fr;
  }
`

const SectionTitle = styled.h2`
  font-size: 0.6875rem;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--color-muted-foreground);
  font-weight: 500;
  padding-top: 0.5rem;
`

const MutedSmall = styled.span`
  font-size: 0.75rem;
  color: var(--color-muted-foreground);
`

const SpansContainer = styled.div`
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
`

const SpansCard = styled.div`
  border-radius: var(--radius-md);
  border: 1px solid var(--color-border);
  background: var(--color-card);
`

const SpansEmpty = styled.div`
  padding: 0.75rem 1rem;
  font-size: 0.875rem;
  color: var(--color-muted-foreground);
`

const SpansDivider = styled.div`
  & > div + div {
    border-top: 1px solid var(--color-border);
  }
  max-height: 16rem;
  overflow-y: auto;
`

const SpanRow = styled.div`
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0.375rem 1rem;
  font-size: 0.8125rem;
`

const SpanName = styled.span`
  color: var(--color-muted-foreground);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  margin-right: 1rem;
`

const SpanDuration = styled.span`
  font-family: var(--font-mono);
  font-size: 0.75rem;
  flex-shrink: 0;
`

const ClearButton = styled.button`
  font-size: 0.6875rem;
  color: var(--color-muted-foreground);
  transition: color var(--duration-hover) ease-out;

  &:hover {
    color: var(--color-foreground);
  }
`

const CenteredLoader = styled.div`
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

const ToggleTrack = styled.button<{ $on: boolean }>`
  position: relative;
  height: 1.5rem;
  width: 2.75rem;
  border-radius: 9999px;
  transition: background-color var(--duration-hover) ease-out;
  background: ${({ $on }) => $on ? 'var(--color-primary)' : 'var(--color-muted)'};
`

const ToggleThumb = styled.span<{ $on: boolean }>`
  position: absolute;
  top: 0.125rem;
  left: 0.125rem;
  height: 1.25rem;
  width: 1.25rem;
  border-radius: 50%;
  background: white;
  box-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);
  transition: transform 150ms ease-out;
  transform: ${({ $on }) => $on ? 'translateX(1.25rem)' : 'translateX(0)'};
`

function MemorySection({ memory }: { memory: { usage: number; peak: number; real: number; real_peak: number } }) {
  return (
    <SectionCard title="Memory">
      <KVRow label="Usage" value={`${memory.usage} MB`} />
      <KVRow label="Peak" value={`${memory.peak} MB`} />
      <KVRow label="Real usage" value={`${memory.real} MB`} />
      <KVRow label="Real peak" value={`${memory.real_peak} MB`} />
    </SectionCard>
  )
}

function ProcessSection({ process }: { process: { pid: number; uid: number; gid: number; user: string; uptime: number } }) {
  return (
    <SectionCard title="Process">
      <KVRow label="PID" value={process.pid} />
      <KVRow label="User" value={process.user} />
      <KVRow label="UID" value={process.uid} />
      <KVRow label="GID" value={process.gid} />
      <KVRow label="Uptime" value={formatUptime(process.uptime)} />
    </SectionCard>
  )
}

function DoctrineSection({ doctrine }: { doctrine: { identity_map_size: number; scheduled_inserts: number; scheduled_updates: number; scheduled_deletes: number; is_open: boolean } }) {
  const pending = doctrine.scheduled_inserts + doctrine.scheduled_updates + doctrine.scheduled_deletes
  return (
    <SectionCard title="Doctrine EntityManager">
      <KVRow label="Open" value={doctrine.is_open ? 'Yes' : 'No'} muted={!doctrine.is_open} />
      <KVRow label="Identity map" value={doctrine.identity_map_size} />
      <KVRow label="Pending operations" value={pending} muted={pending === 0} />
      <KVRow label="Scheduled inserts" value={doctrine.scheduled_inserts} muted={doctrine.scheduled_inserts === 0} />
      <KVRow label="Scheduled updates" value={doctrine.scheduled_updates} muted={doctrine.scheduled_updates === 0} />
      <KVRow label="Scheduled deletes" value={doctrine.scheduled_deletes} muted={doctrine.scheduled_deletes === 0} />
    </SectionCard>
  )
}

function RedisSection({ redis }: { redis: RedisStats | null }) {
  if (!redis) {
    return (
      <SectionCard title="Redis">
        <KVRow label="Status" value="Not configured" muted />
      </SectionCard>
    )
  }

  if (!redis.connected) {
    return (
      <SectionCard title="Redis">
        <KVRow label="Status" value="Disconnected" muted />
        {redis.error && <KVRow label="Error" value={redis.error} />}
      </SectionCard>
    )
  }

  return (
    <SectionCard title="Redis">
      <KVRow label="Connected" value={redis.connected ? 'Yes' : 'No'} muted={!redis.connected} />
      <KVRow label="Ping" value={redis.ping ? 'PONG' : 'Failed'} muted={!redis.ping} />
      <KVRow label="DB size" value={redis.db_size} />
      <KVRow label="Connected clients" value={redis.connected_clients} />
      <KVRow label="Used memory" value={redis.used_memory != null ? `${redis.used_memory} MB` : '-'} />
      <KVRow label="Max memory" value={redis.maxmemory != null ? `${redis.maxmemory} MB` : '-'} />
    </SectionCard>
  )
}

function SwooleSection({ swoole }: { swoole: Record<string, unknown> | null }) {
  if (!swoole) {
    return null
  }

  const entries = Object.entries(swoole)
  if (entries.length === 0) {
    return null
  }

  return (
    <SectionCard title="Swoole">
      {entries.map(([key, value]) => (
        <KVRow key={key} label={key.replace(/_/g, ' ')} value={String(value)} />
      ))}
    </SectionCard>
  )
}

function CoroutineSection() {
  const { data, isLoading } = useCoroutineStats()

  if (isLoading || !data) {
    return (
      <SectionCard title="Coroutines">
        <KVRow label="Status" value="Loading..." muted />
      </SectionCard>
    )
  }

  return (
    <SectionCard title="Coroutines">
      <KVRow label="Active" value={data.active ?? data.num ?? 0} />
      <KVRow label="Peak" value={data.peak ?? 0} />
      <KVRow label="Total" value={data.num ?? 0} />
    </SectionCard>
  )
}

function WorkerSection() {
  const { data, isLoading } = useWorkerStats()

  if (isLoading || !data) {
    return (
      <SectionCard title="Workers">
        <KVRow label="Status" value="Loading..." muted />
      </SectionCard>
    )
  }

  return (
    <SectionCard title="Workers">
      {data.http_workers && (
        <>
          <KVRow label="HTTP total" value={data.http_workers.total} />
          <KVRow label="HTTP active" value={data.http_workers.active} />
          <KVRow label="HTTP idle" value={data.http_workers.idle} />
        </>
      )}
      {data.task_workers && (
        <>
          <KVRow label="Task total" value={data.task_workers.total} />
          <KVRow label="Task active" value={data.task_workers.active} />
          <KVRow label="Task idle" value={data.task_workers.idle} />
        </>
      )}
      {data.cpu_pool && (
        <>
          <KVRow label="CPU pool workers" value={data.cpu_pool.workers} />
          <KVRow label="CPU pool busy" value={data.cpu_pool.busy} />
        </>
      )}
    </SectionCard>
  )
}

function SpansSection() {
  const { data: spans, isLoading } = useSpans(30)
  const clearSpans = useClearSpans()

  return (
    <SpansContainer>
      <FlexBetween>
        <SectionTitle style={{ padding: 0 }}>Recent Spans</SectionTitle>
        <ClearButton onClick={() => clearSpans()}>Clear</ClearButton>
      </FlexBetween>
      <SpansCard>
        {isLoading || !spans || spans.length === 0 ? (
          <SpansEmpty>
            {isLoading ? 'Loading...' : 'No spans recorded'}
          </SpansEmpty>
        ) : (
          <SpansDivider>
            {spans.map((span: Span, i: number) => (
              <SpanRow key={i}>
                <SpanName>{span.name}</SpanName>
                <SpanDuration>{span.duration_ms.toFixed(1)}ms</SpanDuration>
              </SpanRow>
            ))}
          </SpansDivider>
        )}
      </SpansCard>
    </SpansContainer>
  )
}

function DevPanelToggle() {
  const visible = useDevPanelStore((s) => s.visible)
  const setVisible = useDevPanelStore((s) => s.setVisible)

  return (
    <ToggleTrack
      $on={visible}
      onClick={() => setVisible(!visible)}
      role="switch"
      aria-checked={visible}
      aria-label="Mediator debug panel"
    >
      <ToggleThumb $on={visible} />
    </ToggleTrack>
  )
}

export function ServerDiagnosticsPage() {
  const { data: stats, isLoading, error, dataUpdatedAt } = useServerStats()

  if (isLoading) {
    return <CenteredLoader><Loader size={24} style={{ animation: 'spin 1s linear infinite', color: 'var(--color-muted-foreground)' }} /></CenteredLoader>
  }

  if (error) {
    return (
      <Container>
        <ErrorText>Failed to load server diagnostics.</ErrorText>
      </Container>
    )
  }

  if (!stats) return null

  return (
    <Container>
      <FlexBetween>
        <LiveHealthBar />
        <MutedSmall>Auto-refreshes every 5s</MutedSmall>
      </FlexBetween>

      <Grid>
        {/* Developer Tools */}
        <SectionCard title="Developer Tools">
          <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: '0.625rem 1rem', fontSize: '0.875rem' }}>
            <div>
              <span style={{ color: 'var(--color-muted-foreground)' }}>Mediator debug panel</span>
              <p style={{ fontSize: '0.75rem', color: 'color-mix(in srgb, var(--color-muted-foreground) 60%, transparent)', marginTop: '0.125rem' }}>Cross-context action timeline, handler map, and store inspector</p>
            </div>
            <DevPanelToggle />
          </div>
        </SectionCard>
      </Grid>

      <Grid>
        <MemorySection memory={stats.memory} />
        <ProcessSection process={stats.process} />
        <DoctrineSection doctrine={stats.doctrine} />
        <RedisSection redis={stats.redis} />
        {stats.swoole && <SwooleSection swoole={stats.swoole} />}
      </Grid>

      {/* Runtime details */}
      <SectionTitle>Runtime</SectionTitle>
      <Grid>
        <CoroutineSection />
        <WorkerSection />
      </Grid>

      {/* Recent spans */}
      <SpansSection />

      {dataUpdatedAt > 0 && (
        <MutedSmall>
          Last updated: {new Date(dataUpdatedAt).toLocaleTimeString()}
        </MutedSmall>
      )}
    </Container>
  )
}
