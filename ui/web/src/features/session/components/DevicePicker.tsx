import { useQuery } from '@tanstack/react-query'
import { useGetDeviceList } from '@/shared/api-client/gen/endpoints'
import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'
import { Monitor, Smartphone } from 'lucide-react'
import { useSessionClaim } from '../hooks/use-session-claim'
import { getDeviceId } from '../utils/device-id'
import styled from 'styled-components'
import { interactiveTransition } from '@/shared/theme'
import { Button } from '@/shared/components/ui/button'

const SESSION_KEY = ['session', 'current']

const ActiveDeviceWrapper = styled.div`
  display: flex;
  align-items: center;
  gap: 0.25rem;
`;

const DeviceIcon = styled(Monitor)`
  color: var(--color-muted-foreground);
`;

const DeviceLabel = styled.span`
  font-size: 10px;
  color: var(--color-muted-foreground);
  max-width: 80px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
`;

const TransferButton = styled(Button).attrs({ variant: 'ghost', size: 'xs' })`
  display: flex;
  align-items: center;
  gap: 0.25rem;
  padding: 0 0.25rem;
  border: none;
  background: none;
  cursor: pointer;
  ${interactiveTransition(['color', 'background-color'])}

  &:hover {
    background-color: var(--color-highlight);
  }

  &:disabled {
    cursor: not-allowed;
    opacity: 0.5;
  }
`;

const TransferIcon = styled(Smartphone)`
  color: var(--color-muted-foreground);
`;

const TransferLabel = styled.span`
  font-size: 10px;
  color: var(--color-muted-foreground);
  max-width: 60px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
`;

export function DevicePicker() {
  const myDeviceId = getDeviceId()

  const { data: devices = [] } = useGetDeviceList({
    query: { select: response => response.data ?? [] },
  })

  const { data: session } = useQuery({
    queryKey: SESSION_KEY,
    queryFn: async ({ signal }) => {
      try {
        const res = await AXIOS_INSTANCE.get('/api/session', { signal })
        return res.data?.data ?? res.data ?? null
      } catch (error) {
        if ((error as { response?: { status?: number } }).response?.status === 404) return null
        throw error
      }
    },
    staleTime: 30_000,
  })

  const claimMutation = useSessionClaim()

  const activeDeviceId = session?.activeDeviceId ?? null
  const isActive = activeDeviceId === devices.find(d => d.deviceId === myDeviceId)?.publicId
  const activeDevice = devices.find(d => d.publicId === activeDeviceId)
  const activeName = activeDevice?.name ?? 'Unknown device'

  // Single device, nothing to show
  if (devices.length <= 1) return null

  // This device is active — just show name
  if (isActive) {
    return (
      <ActiveDeviceWrapper>
        <DeviceIcon size={12} />
        <DeviceLabel>
          This device
        </DeviceLabel>
      </ActiveDeviceWrapper>
    )
  }

  // Another device is active — show which + take over action
  return (
    <TransferButton
      type="button"
      onClick={() => claimMutation.mutate()}
      disabled={claimMutation.isPending}
      title={`Listening on ${activeName} — click to transfer here`}
    >
      <TransferIcon size={12} />
      <TransferLabel>
        {claimMutation.isPending ? 'Transferring…' : activeName}
      </TransferLabel>
    </TransferButton>
  )
}
