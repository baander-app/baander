import { useQueryClient } from '@tanstack/react-query'
import { Button } from '@/shared/components/ui/button'
import { Input } from '@/shared/components/ui/input'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/shared/components/ui/dialog'
import { Monitor, Trash2, Loader2, Pencil } from 'lucide-react'
import { useState } from 'react'
import {
  useGetDeviceList,
  usePutDeviceRename,
  useDeleteDeviceForget,
  getGetDeviceListQueryKey,
} from '@/shared/api-client/gen/endpoints'
import styled, { css, keyframes } from 'styled-components'

type Device = {
  id?: string
  name?: string
  lastUsedAt?: string | null
}

const spin = keyframes`
  from { transform: rotate(0deg); }
  to { transform: rotate(360deg); }
`;

const Container = styled.div`
  border-radius: var(--radius-lg);
  background-color: var(--color-card);
  padding: 1rem;
  display: flex;
  flex-direction: column;
  gap: 1rem;
`;

const HeaderText = styled.p`
  font-size: 0.875rem;
  font-weight: 500;
`;

const Subtext = styled.p`
  font-size: 0.75rem;
  color: var(--color-muted-foreground);
`;

const SpinnerIcon = styled(Loader2)`
  animation: ${spin} 1s linear infinite;
  color: var(--color-muted-foreground);
`;

const LoadingWrapper = styled.div`
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 1rem 0;
`;

const EmptyState = styled.div`
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.5rem;
  padding: 1.5rem 0;
  text-align: center;
`;

const EmptyIcon = styled(Monitor)`
  color: color-mix(in srgb, var(--color-muted-foreground) 30%, transparent);
`;

const EmptyText = styled.p`
  font-size: 0.75rem;
  color: var(--color-muted-foreground);
`;

const DeviceList = styled.div`
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
`;

const DeviceRow = styled.div`
  display: flex;
  align-items: center;
  justify-content: space-between;
  border-radius: var(--radius-md);
  border: 1px solid var(--color-border);
  padding: 0.5rem 0.75rem;
`;

const DeviceInfo = styled.div`
  display: flex;
  align-items: center;
  gap: 0.5rem;
  min-width: 0;
`;

const DeviceIcon = styled(Monitor)`
  flex-shrink: 0;
  color: var(--color-muted-foreground);
`;

const DeviceName = styled.p`
  font-size: 0.875rem;
  font-weight: 500;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
`;

const DeviceLastUsed = styled.p`
  font-size: 0.75rem;
  color: var(--color-muted-foreground);
`;

const DeviceActions = styled.div`
  display: flex;
  align-items: center;
  gap: 0.25rem;
`;

const SmallButton = styled(Button).attrs({ size: 'icon', variant: 'ghost' })`
  flex-shrink: 0;
  height: 1.75rem;
  width: 1.75rem;
`;

const IconMuted = styled.span`
  color: var(--color-muted-foreground);
  display: flex;
`;

const TrashIcon = styled(Trash2)`
  color: var(--color-muted-foreground);

  &:hover {
    color: var(--color-destructive);
  }
`;

const DialogSpinnerIcon = styled(Loader2)`
  margin-right: 0.25rem;
  animation: ${spin} 1s linear infinite;
`;

export function DeviceManagement() {
  const queryClient = useQueryClient()
  const { data: devices = [], isLoading } = useGetDeviceList({
    query: {
      select: (res): Device[] => {
        if ('data' in res && res.data && 'data' in res.data) {
          return (res.data as { data: Device[] }).data ?? []
        }
        return []
      },
    },
  })

  const renameDevice = usePutDeviceRename({
    mutation: {
      onSuccess: () => queryClient.invalidateQueries({ queryKey: getGetDeviceListQueryKey() }),
    },
  })
  const forgetDevice = useDeleteDeviceForget({
    mutation: {
      onSuccess: () => queryClient.invalidateQueries({ queryKey: getGetDeviceListQueryKey() }),
    },
  })

  const [editTarget, setEditTarget] = useState<Device | null>(null)
  const [editName, setEditName] = useState('')
  const [forgetTarget, setForgetTarget] = useState<Device | null>(null)

  function handleRename() {
    if (!editTarget?.id) return
    const name = editName.trim()
    if (!name) return
    renameDevice.mutate({ deviceId: editTarget.id, data: { name } }, {
      onSuccess: () => setEditTarget(null),
    })
  }

  function handleForget() {
    if (!forgetTarget?.id) return
    forgetDevice.mutate({ deviceId: forgetTarget.id }, {
      onSuccess: () => setForgetTarget(null),
    })
  }

  return (
    <Container>
      <div>
        <HeaderText>Devices</HeaderText>
        <Subtext>
          Manage devices linked to your account
        </Subtext>
      </div>

      {isLoading ? (
        <LoadingWrapper>
          <SpinnerIcon size={16} />
        </LoadingWrapper>
      ) : devices.length === 0 ? (
        <EmptyState>
          <EmptyIcon size={24} />
          <EmptyText>No devices registered</EmptyText>
        </EmptyState>
      ) : (
        <DeviceList>
          {devices.map((device) => (
            <DeviceRow key={device.id}>
              <DeviceInfo>
                <DeviceIcon size={14} />
                <div style={{ minWidth: 0 }}>
                  <DeviceName>{device.name || 'Unknown Device'}</DeviceName>
                  {device.lastUsedAt && (
                    <DeviceLastUsed>
                      Last used {formatRelative(device.lastUsedAt)}
                    </DeviceLastUsed>
                  )}
                </div>
              </DeviceInfo>
              <DeviceActions>
                <SmallButton
                  onClick={() => { setEditTarget(device); setEditName(device.name ?? '') }}
                >
                  <IconMuted><Pencil size={14} /></IconMuted>
                </SmallButton>
                <SmallButton
                  onClick={() => setForgetTarget(device)}
                  disabled={forgetDevice.isPending}
                >
                  <TrashIcon size={14} />
                </SmallButton>
              </DeviceActions>
            </DeviceRow>
          ))}
        </DeviceList>
      )}

      {/* Rename dialog */}
      <Dialog open={!!editTarget} onOpenChange={(open) => !open && setEditTarget(null)}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Rename device</DialogTitle>
            <DialogDescription>Give this device a recognizable name.</DialogDescription>
          </DialogHeader>
          <Input
            value={editName}
            onChange={(e) => setEditName(e.target.value)}
            onKeyDown={(e) => e.key === 'Enter' && handleRename()}
            autoFocus
          />
          <DialogFooter>
            <Button variant="outline" onClick={() => setEditTarget(null)}>Cancel</Button>
            <Button onClick={handleRename} disabled={renameDevice.isPending || !editName.trim()}>
              {renameDevice.isPending ? <DialogSpinnerIcon size={14} /> : null}
              Rename
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Forget confirmation */}
      <Dialog open={!!forgetTarget} onOpenChange={(open) => !open && setForgetTarget(null)}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Forget device</DialogTitle>
            <DialogDescription>
              Remove &quot;{forgetTarget?.name || 'Unknown Device'}&quot; from your account?
              Session history from this device will be preserved.
            </DialogDescription>
          </DialogHeader>
          <DialogFooter>
            <Button variant="outline" onClick={() => setForgetTarget(null)}>Cancel</Button>
            <Button variant="destructive" onClick={handleForget} disabled={forgetDevice.isPending}>
              {forgetDevice.isPending ? <DialogSpinnerIcon size={14} /> : null}
              Forget
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </Container>
  )
}

function formatRelative(iso: string): string {
  const diffMs = Date.now() - new Date(iso).getTime()
  const diffDays = Math.floor(diffMs / (1000 * 60 * 60 * 24))
  if (diffDays < 1) return 'today'
  if (diffDays === 1) return 'yesterday'
  if (diffDays < 30) return `${diffDays}d ago`
  return new Date(iso).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' })
}
