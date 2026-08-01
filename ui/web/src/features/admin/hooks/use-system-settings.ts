import { useQuery } from '@tanstack/react-query'
import { getSystemSettings } from '../api/system-settings-api'

export function useSystemSettings() {
  return useQuery({
    queryKey: ['system-settings'],
    queryFn: getSystemSettings,
    staleTime: 30_000,
    retry: false,
  })
}

/** Check a boolean system setting. Returns false while loading. */
export function useSystemSetting(key: string): boolean {
  const { data } = useSystemSettings()
  return data?.[key] === true
}
