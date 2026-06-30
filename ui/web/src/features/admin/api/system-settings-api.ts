import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'

export type SystemSettings = Record<string, boolean | string | number>

export async function getSystemSettings(): Promise<SystemSettings> {
  const { data } = await AXIOS_INSTANCE.get('/api/admin/settings')
  return data.data
}

export async function updateSystemSettings(settings: SystemSettings): Promise<SystemSettings> {
  const { data } = await AXIOS_INSTANCE.patch('/api/admin/settings', { settings })
  return data.data
}
