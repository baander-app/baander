import styled from 'styled-components'
import { useTranscodeSessions } from '../hooks/use-transcode-admin'
import { Cpu, CheckCircle2, XCircle, Clock, Loader2 } from 'lucide-react'
import { StatCard } from '@/shared/components/stat-card'
import { formatDurationHuman } from '@/shared/utils/format-human'
import type { ComponentType, CSSProperties } from 'react'
import type { TranscodeSession } from '../api/transcode-admin-api'

type SessionState = TranscodeSession['state']

const stateIcon: Record<SessionState, ComponentType<{ size?: number; strokeWidth?: number; className?: string; style?: CSSProperties }>> = {
  pending: Clock,
  preparing: Loader2,
  active: Loader2,
  paused: Clock,
  completed: CheckCircle2,
  failed: XCircle,
  cancelled: XCircle,
}

const stateColor: Record<SessionState, string> = {
  pending: 'var(--color-muted-foreground)',
  preparing: 'var(--color-highlight)',
  active: 'var(--color-highlight)',
  paused: 'var(--color-muted-foreground)',
  completed: '#10b981',
  failed: 'var(--color-destructive)',
  cancelled: 'var(--color-muted-foreground)',
}

const ACTIVE_STATES: SessionState[] = ['pending', 'preparing', 'active']
const HISTORY_STATES: SessionState[] = ['completed', 'failed', 'cancelled']

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

const SegmentLabel = styled.span`
  font-size: 0.6875rem;
  color: var(--color-muted-foreground);
  font-variant-numeric: tabular-nums;
  width: 4rem;
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

function stateLabel(state: SessionState): string {
  return state.charAt(0).toUpperCase() + state.slice(1)
}

export function TranscodePage() {
  const { data: sessions, isLoading: sessionsLoading } = useTranscodeSessions()
  const list = sessions ?? []
  const activeSessions = list.filter((s) => ACTIVE_STATES.includes(s.state))
  const historySessions = list
    .filter((s) => HISTORY_STATES.includes(s.state))
    .slice(0, 20)

  if (sessionsLoading) {
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
          value={list.filter((s) => s.state === 'active').length}
          icon={Cpu}
        />
        <StatCard
          label="Preparing"
          value={list.filter((s) => s.state === 'preparing' || s.state === 'pending').length}
          icon={Clock}
        />
        <StatCard
          label="Completed"
          value={list.filter((s) => s.state === 'completed').length}
          icon={CheckCircle2}
        />
        <StatCard
          label="Failed"
          value={list.filter((s) => s.state === 'failed').length}
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
              const Icon = stateIcon[session.state]
              return (
                <SessionRow key={session.uuid}>
                  <Icon size={14} strokeWidth={1.5} style={{ color: stateColor[session.state] }} />
                  <SessionInfo>
                    <SessionName>{stateLabel(session.state)}</SessionName>
                    <SessionMeta>
                      {session.priority} · video {session.videoId.slice(0, 8)}
                    </SessionMeta>
                  </SessionInfo>
                  <SegmentLabel>
                    {session.currentSegmentIndex !== null ? `seg ${session.currentSegmentIndex}` : ''}
                  </SegmentLabel>
                  <ElapsedLabel>
                    {formatElapsed(session.createdAt)}
                  </ElapsedLabel>
                </SessionRow>
              )
            })}
          </Divider>
        )}
      </Card>

      {/* Session history */}
      {historySessions.length > 0 && (
        <Card>
          <CardHeader>
            <CardTitle>Recent History</CardTitle>
          </CardHeader>
          <Divider>
            {historySessions.map((session) => {
              const Icon = stateIcon[session.state]
              return (
                <SessionRow key={session.uuid}>
                  <Icon size={14} strokeWidth={1.5} style={{ color: stateColor[session.state] }} />
                  <SessionInfo>
                    <SessionName>{stateLabel(session.state)}</SessionName>
                    <SessionMeta>
                      {session.priority} · video {session.videoId.slice(0, 8)}
                    </SessionMeta>
                  </SessionInfo>
                  <span style={{ fontSize: '0.6875rem', color: 'var(--color-muted-foreground)', fontVariantNumeric: 'tabular-nums' }}>
                    {formatTime(session.updatedAt)}
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
