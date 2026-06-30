import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'
import { Monitor, Smartphone } from 'lucide-react'
import { getDeviceId } from '../utils/device-id'
import styled from 'styled-components'
import { interactiveTransition } from '@/shared/theme'

const DEVICES_KEY = ['devices']
const SESSION_KEY = ['session', 'current']

interface Device {
  id: string
  deviceId: string
  name: string | null
  lastUsedAt: string | null
}

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

const TransferButton = styled.button`
  display: flex;
  align-items: center;
  gap: 0.25rem;
  border-radius: var(--radius);
  padding: 0 0.25rem;
  border: none;
  background: none;
  cursor: pointer;
  ${interactiveTransition(['color', 'background-color'])}

  &:hover {
    background-color: color-mix(in srgb, var(--color-accent) 50%, transparent);
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
  const queryClient = useQueryClient()
  const myDeviceId = getDeviceId()

  const { data: devices = [] } = useQuery({
    queryKey: DEVICES_KEY,
    queryFn: async (): Promise<Device[]> => {
      const res = await AXIOS_INSTANCE.get('/api/devices')
      return res.data?.data ?? []
    },
  })

  const { data: session } = useQuery({
    queryKey: SESSION_KEY,
    queryFn: async () => {
      try {
        const res = await AXIOS_INSTANCE.get('/api/session')
        return res.data?.data ?? res.data ?? null
      } catch {
        return null
      }
    },
    staleTime: 30_000,
  })

  const claimMutation = useMutation({
    mutationFn: async () => {
      await AXIOS_INSTANCE.post('/api/session/claim', { deviceId: myDeviceId })
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: SESSION_KEY })
    },
  })

  const activeDeviceId = session?.activeDeviceId ?? null
  const isActive = activeDeviceId === myDeviceId
  const activeDevice = devices.find(d => d.deviceId === activeDeviceId)
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
