import { useEffect, useRef, useState } from 'react'
import { assertCurrentOwnership, captureOwnership, isCurrentOwnership, subscribeOwnership } from '@/shared/live/ownership'
import { useAdminMutationTools } from '../../hooks/use-admin-mutation'
import styled, { keyframes } from 'styled-components'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'
import { Activity, Loader, Music, RefreshCw, Stethoscope } from 'lucide-react'
import { interactiveTransition, focusVisibleRing } from '@/shared/theme'
import { useBulkFetchLyrics } from '../../hooks/use-lyrics-admin'
import { useFlushFailedQueue } from '../../hooks/use-job-monitor'

type ActionState = 'idle' | 'loading' | 'success' | 'error'

interface ActionButtonConfig {
  label: string
  icon: React.ComponentType<{ size?: number; strokeWidth?: number; className?: string }>
  action: (signal: AbortSignal) => Promise<unknown>
}

function useActionButton(config: ActionButtonConfig) {
  const [state, setState] = useState<ActionState>('idle')
  const active = useRef<AbortController | null>(null)
  const timer = useRef<ReturnType<typeof setTimeout> | undefined>(undefined)
  useEffect(() => () => {
    active.current?.abort()
    clearTimeout(timer.current)
  }, [])

  const mutation = useMutation({
    mutationFn: async () => {
      const controller = new AbortController()
      active.current = controller
      const owner = captureOwnership()
      const unsubscribe = subscribeOwnership(() => {
        if (!isCurrentOwnership(owner)) controller.abort()
      })
      try {
        const result = await config.action(controller.signal)
        assertCurrentOwnership(owner)
        return result
      } finally {
        unsubscribe()
        if (active.current === controller) active.current = null
      }
    },
    onMutate: () => setState('loading'),
    onSuccess: () => {
      setState('success')
      timer.current = setTimeout(() => setState('idle'), 2000)
    },
    onError: () => {
      setState('error')
      timer.current = setTimeout(() => setState('idle'), 3000)
    },
  })

  return { state, run: () => mutation.mutate() }
}

const spin = keyframes`
  from { transform: rotate(0deg); }
  to { transform: rotate(360deg); }
`

const ActionButtonWrapper = styled.button`
  ${focusVisibleRing}
  border: 0;
  background-color: transparent;
  color: var(--color-foreground);
  display: flex;
  align-items: center;
  gap: 0.5rem;
  border-radius: var(--radius-md);
  padding: 0.375rem 0.75rem;
  font-size: 13px;
  color: var(--color-muted-foreground);
  ${interactiveTransition(['color', 'background-color'])}

  &:hover {
    background-color: color-mix(in srgb, var(--color-highlight) 20%, transparent);
    color: var(--color-foreground);
  }

  &:disabled { opacity: 0.5; }
`

const SpinIcon = styled(Loader)`
  animation: ${spin} 1s linear infinite;
`

const SuccessIcon = styled.span`
  color: var(--color-success);
  font-size: 11px;
`

const ErrorIcon = styled.span`
  color: var(--color-destructive);
  font-size: 11px;
`

const Wrapper = styled.div`
  display: flex;
  align-items: center;
  gap: 0.5rem;
  flex-wrap: wrap;
`

function ActionButton({ config }: { config: ActionButtonConfig }) {
  const { state, run } = useActionButton(config)
  const Icon = config.icon

  return (
    <ActionButtonWrapper onClick={run} disabled={state === 'loading'}>
      {state === 'loading' ? (
        <SpinIcon size={14} />
      ) : state === 'success' ? (
        <SuccessIcon>✓</SuccessIcon>
      ) : state === 'error' ? (
        <ErrorIcon>✗</ErrorIcon>
      ) : (
        <Icon size={14} strokeWidth={1.5} />
      )}
      {config.label}
    </ActionButtonWrapper>
  )
}

export function QuickActions() {
  const queryClient = useQueryClient()
  const { invalidate } = useAdminMutationTools(['admin-runtime', 'admin-jobs', 'admin-alerts'])
  const bulkFetchLyrics = useBulkFetchLyrics()
  const flushFailedQueue = useFlushFailedQueue()

  const actions: ActionButtonConfig[] = [
    {
      label: 'Run Health Check',
      icon: Stethoscope,
      action: async (signal) => {
        const owner = captureOwnership()
        const result = await AXIOS_INSTANCE.get('/health', { signal })
        assertCurrentOwnership(owner)
        invalidate(['server-stats'])
        return result
      },
    },
    {
      label: 'Flush Failed Jobs',
      icon: RefreshCw,
      // Use the shared hook so transport-status invalidation stays consistent with
      // the rest of the admin UI; add status-overview refresh on top.
      action: async () => {
        const owner = captureOwnership()
        const result = await flushFailedQueue.mutateAsync()
        assertCurrentOwnership(owner)
        invalidate(['status-overview'])
        return result
      },
    },
    {
      label: 'Bulk Fetch Lyrics',
      icon: Music,
      // Use the shared hook so lyrics coverage/sync-status queries are invalidated.
      action: () => bulkFetchLyrics.mutateAsync(),
    },
    {
      label: 'Refresh Stats',
      icon: Activity,
      action: async () => {
        queryClient.invalidateQueries({ queryKey: ['server-stats'] })
        queryClient.invalidateQueries({ queryKey: ['status-overview'] })
        queryClient.invalidateQueries({ queryKey: ['admin-alerts'] })
      },
    },
  ]

  return (
    <Wrapper>
      {actions.map((action) => (
        <ActionButton key={action.label} config={action} />
      ))}
    </Wrapper>
  )
}
