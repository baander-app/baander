import { useState } from 'react'
import styled, { keyframes } from 'styled-components'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'
import { Activity, Loader, Music, RefreshCw, Stethoscope } from 'lucide-react'
import { interactiveTransition } from '@/shared/theme'

type ActionState = 'idle' | 'loading' | 'success' | 'error'

interface ActionButtonConfig {
  label: string
  icon: React.ComponentType<{ size?: number; strokeWidth?: number; className?: string }>
  action: () => Promise<unknown>
}

function useActionButton(config: ActionButtonConfig) {
  const [state, setState] = useState<ActionState>('idle')

  const mutation = useMutation({
    mutationFn: config.action,
    onMutate: () => setState('loading'),
    onSuccess: () => {
      setState('success')
      setTimeout(() => setState('idle'), 2000)
    },
    onError: () => {
      setState('error')
      setTimeout(() => setState('idle'), 3000)
    },
  })

  return { state, run: () => mutation.mutate() }
}

const spin = keyframes`
  from { transform: rotate(0deg); }
  to { transform: rotate(360deg); }
`

const ActionButtonWrapper = styled.button`
  display: flex;
  align-items: center;
  gap: 0.5rem;
  border-radius: 0.25rem;
  padding: 0.375rem 0.75rem;
  font-size: 13px;
  color: var(--color-muted-foreground);
  ${interactiveTransition(['color', 'background-color'])}

  &:hover {
    background-color: color-mix(in srgb, var(--color-accent) 20%, transparent);
    color: var(--color-foreground);
  }

  &:disabled { opacity: 0.5; }
`

const SpinIcon = styled(Loader)`
  animation: ${spin} 1s linear infinite;
`

const SuccessIcon = styled.span`
  color: #10b981;
  font-size: 11px;
`

const ErrorIcon = styled.span`
  color: #ef4444;
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

  const actions: ActionButtonConfig[] = [
    {
      label: 'Run Health Check',
      icon: Stethoscope,
      action: async () => {
        const result = await AXIOS_INSTANCE.get('/health')
        queryClient.invalidateQueries({ queryKey: ['server-stats'] })
        return result
      },
    },
    {
      label: 'Flush Failed Jobs',
      icon: RefreshCw,
      action: async () => {
        const result = await AXIOS_INSTANCE.post(
          '/api/monitor/transport/failed/flush',
          null,
          { params: { confirm: 'true' } },
        )
        queryClient.invalidateQueries({ queryKey: ['transport-status'] })
        queryClient.invalidateQueries({ queryKey: ['status-overview'] })
        return result
      },
    },
    {
      label: 'Bulk Fetch Lyrics',
      icon: Music,
      action: async () => {
        const result = await AXIOS_INSTANCE.post('/api/admin/lyrics/bulk-fetch')
        return result
      },
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
