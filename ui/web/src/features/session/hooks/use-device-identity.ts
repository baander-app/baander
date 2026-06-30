import { useMemo } from 'react'
import { getDeviceId } from '../utils/device-id'

export function useDeviceIdentity() {
  const deviceId = useMemo(() => getDeviceId(), [])
  return { deviceId }
}
