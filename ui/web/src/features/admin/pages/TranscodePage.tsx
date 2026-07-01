import styled from 'styled-components'
import { useTranscodeSessions, useTranscodeStats } from '../hooks/use-transcode-admin'
import { Cpu, CheckCircle2, XCircle, Clock, Loader2 } from 'lucide-react'
import { StatCard } from '@/shared/components/stat-card'
import { ProgressBar } from '@/shared/components/progress-bar'
import { formatDurationHuman } from '@/shared/utils/format-human'
import type { ComponentType, CSSProperties } from 'react'

const statusIcon: Record<string, ComponentType<{ size?: number; strokeWidth?: number; className?: string; style?: CSSProperties }>> = {
  queued: Clock,
  processing: Loader2,
  completed: CheckCircle2,
  failed: XCircle,
}

const statusColor: Record<string, string> = {
  queued: 'var(--color-muted-foreground)',
  processing: 'var(--color-highlight)',
  completed: '#10b981',
  failed: 'var(--color-destructive)',
}

const Container = styled.div`
  display: flex;
  flex-direction: column;
  gap: 1.5rem;
  padding: 1.5rem;
`

const StatsRow = styled.div`
  display: flex;
  flex-wrap: wrap;
  gap: 0.75rem;
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

const CardEmpty = styled.div`
  padding: 2rem 1rem;
  text-align: center;
  font-size: 0.8125rem;
  color: var(--color-muted-foreground);
`

const Divider = styled.div`
  & > div + div {
    border-top: 1px solid var(--color-border);
  }
`

const SessionRow = styled.div`
  display: flex;
  align-items: center;
  gap: 1rem;
  padding: 0.75rem 1rem;
`

const SessionInfo = styled.div`
  flex: 1;
  min-width: 0;
`

const SessionName = styled.div`
  font-size: 0.8125rem;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
`

const SessionMeta = styled.div`
  font-size: 0.6875rem;
  color: var(--color-muted-foreground);
  font-family: var(--font-mono);
`

const ProgressGroup = styled.div`
  display: flex;
  align-items: center;
  gap: 0.5rem;
`

const ProgressLabel = styled.span`
  font-size: 0.6875rem;
  font-variant-numeric: tabular-nums;
  color: var(--color-muted-foreground);
  width: 2rem;
  text-align: right;
`

const ElapsedLabel = styled.span`
  font-size: 0.6875rem;
  color: var(--color-muted-foreground);
  font-variant-numeric: tabular-nums;
  width: 3rem;
  text-align: right;
`

const LoadingCard = styled.div`
  height: 4rem;
  width: 8rem;
  border-radius: var(--radius-lg);
  border: 1px solid var(--color-border);
  background: var(--color-card);
  animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;

  @keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.5; }
  }
`

function formatTime(iso: string | null): string {
  if (!iso) return '—'
  return new Date(iso).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
}

function formatElapsed(iso: string | null): string {
  if (!iso) return '—'
  const diffMs = Date.now() - new Date(iso).getTime()
  const secs = Math.floor(diffMs / 1000)
  return formatDurationHuman(secs)
}

export function TranscodePage() {
  const { data: stats, isLoading: statsLoading } = useTranscodeStats()
  const { data: sessions, isLoading: sessionsLoading } = useTranscodeSessions()
  const activeSessions = sessions
    ? (Array.isArray(sessions) ? sessions : []).filter((s) => s.status === 'processing' || s.status === 'queued')
    : []
  const completedSessions = sessions
    ? (Array.isArray(sessions) ? sessions : []).filter((s) => s.status === 'completed' || s.status === 'failed').slice(0, 20)
    : []

  if (statsLoading && sessionsLoading) {
    return (
      <Container>
        <StatsRow>
          {Array.from({ length: 4 }).map((_, i) => (
            <LoadingCard key={i} />
          ))}
        </StatsRow>
      </Container>
    )
  }

  return (
    <Container>
      {/* Stats bar */}
      <StatsRow>
        <StatCard
          label="Active"
          value={stats?.active ?? activeSessions.filter((s) => s.status === 'processing').length}
          icon={Cpu}
        />
        <StatCard
          label="Queued"
          value={stats?.queued ?? activeSessions.filter((s) => s.status === 'queued').length}
          icon={Clock}
        />
        <StatCard
          label="Completed Today"
          value={stats?.completedToday ?? '—'}
          icon={CheckCircle2}
        />
        <StatCard
          label="Failed Today"
          value={stats?.failedToday ?? '—'}
          icon={XCircle}
        />
      </StatsRow>

      {/* Active sessions */}
      <Card>
        <CardHeader>
          <CardTitle>Active Sessions</CardTitle>
        </CardHeader>
        {activeSessions.length === 0 ? (
          <CardEmpty>No active transcode sessions</CardEmpty>
        ) : (
          <Divider>
            {activeSessions.map((session) => {
              const Icon = statusIcon[session.status] ?? Clock
              return (
                <SessionRow key={session.id}>
                  <Icon size={14} strokeWidth={1.5} style={{ color: statusColor[session.status] }} />
                  <SessionInfo>
                    <SessionName>{session.trackName || session.trackId}</SessionName>
                    <SessionMeta>
                      {session.codec} {session.bitrate}kbps
                    </SessionMeta>
                  </SessionInfo>
                  {session.status === 'processing' && (
                    <ProgressGroup>
                      <ProgressBar value={session.progress ?? 0} style={{ width: '6rem' }} />
                      <ProgressLabel>
                        {session.progress !== null ? `${session.progress}%` : ''}
                      </ProgressLabel>
                    </ProgressGroup>
                  )}
                  <ElapsedLabel>
                    {formatElapsed(session.startedAt)}
                  </ElapsedLabel>
                </SessionRow>
              )
            })}
          </Divider>
        )}
      </Card>

      {/* Session history */}
      {completedSessions.length > 0 && (
        <Card>
          <CardHeader>
            <CardTitle>Recent History</CardTitle>
          </CardHeader>
          <Divider>
            {completedSessions.map((session) => {
              const Icon = statusIcon[session.status] ?? Clock
              return (
                <SessionRow key={session.id}>
                  <Icon size={14} strokeWidth={1.5} style={{ color: statusColor[session.status] }} />
                  <SessionInfo>
                    <SessionName>{session.trackName || session.trackId}</SessionName>
                    <SessionMeta>
                      {session.codec} {session.bitrate}kbps
                    </SessionMeta>
                  </SessionInfo>
                  <span style={{ fontSize: '0.6875rem', color: 'var(--color-muted-foreground)', fontVariantNumeric: 'tabular-nums' }}>
                    {formatTime(session.finishedAt)}
                  </span>
                </SessionRow>
              )
            })}
          </Divider>
        </Card>
      )}
    </Container>
  )
}
