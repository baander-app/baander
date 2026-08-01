import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'

export interface ScheduledJob {
  id: string
  name: string
  expression: string
  jobType: 'messenger' | 'console'
  command: string
  status: 'active' | 'paused' | 'disabled'
  description: string | null
  parameters: Record<string, unknown>
  lastRunAt: string | null
  nextRunAt: string | null
  lastResult: string | null
  runCount: number
  lastFailureAt: string | null
  lastError: string | null
  createdAt: string
  updatedAt: string
}

export interface SchedulableCommand {
  description: string
  parameters: Record<string, {
    type: string
    required: boolean
    description?: string
    default?: unknown
    format?: string
    example?: unknown
    enum?: string[]
    nullable?: boolean
  }>
}

export interface SchedulableCommands {
  messenger: Record<string, SchedulableCommand>
  console: Record<string, SchedulableCommand>
}

export interface CreateScheduledJobPayload {
  name: string
  expression: string
  jobType: 'messenger' | 'console'
  command: string
  description?: string | null
  parameters?: Record<string, unknown>
}

export interface UpdateScheduledJobPayload {
  name: string
  expression: string
  jobType: 'messenger' | 'console'
  command: string
  description?: string | null
  parameters?: Record<string, unknown>
}

export const schedulerAdminApi = {
  list: async (): Promise<ScheduledJob[]> => {
    const { data } = await AXIOS_INSTANCE.get('/api/admin/scheduler/jobs')
    return data.data
  },

  show: async (id: string): Promise<ScheduledJob> => {
    const { data } = await AXIOS_INSTANCE.get(`/api/admin/scheduler/jobs/${id}`)
    return data.data
  },

  create: async (payload: CreateScheduledJobPayload): Promise<ScheduledJob> => {
    const { data } = await AXIOS_INSTANCE.post('/api/admin/scheduler/jobs', payload)
    return data.data
  },

  update: async (id: string, payload: UpdateScheduledJobPayload): Promise<ScheduledJob> => {
    const { data } = await AXIOS_INSTANCE.put(`/api/admin/scheduler/jobs/${id}`, payload)
    return data.data
  },

  remove: async (id: string): Promise<void> => {
    await AXIOS_INSTANCE.delete(`/api/admin/scheduler/jobs/${id}`)
  },

  pause: async (id: string): Promise<ScheduledJob> => {
    const { data } = await AXIOS_INSTANCE.post(`/api/admin/scheduler/jobs/${id}/pause`)
    return data.data
  },

  resume: async (id: string): Promise<ScheduledJob> => {
    const { data } = await AXIOS_INSTANCE.post(`/api/admin/scheduler/jobs/${id}/resume`)
    return data.data
  },

  trigger: async (id: string): Promise<ScheduledJob> => {
    const { data } = await AXIOS_INSTANCE.post(`/api/admin/scheduler/jobs/${id}/trigger`)
    return data.data
  },

  enable: async (id: string): Promise<ScheduledJob> => {
    const { data } = await AXIOS_INSTANCE.post(`/api/admin/scheduler/jobs/${id}/enable`)
    return data.data
  },

  disable: async (id: string): Promise<ScheduledJob> => {
    const { data } = await AXIOS_INSTANCE.post(`/api/admin/scheduler/jobs/${id}/disable`)
    return data.data
  },

  commands: async (): Promise<SchedulableCommands> => {
    const { data } = await AXIOS_INSTANCE.get('/api/admin/scheduler/jobs/commands')
    return data.data
  },
}
