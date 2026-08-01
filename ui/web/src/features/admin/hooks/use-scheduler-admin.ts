import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import {
  schedulerAdminApi,
  type CreateScheduledJobPayload,
  type UpdateScheduledJobPayload,
} from '../api/scheduler-admin-api'

const SCHEDULER_KEY = ['admin-scheduler']
const COMMANDS_KEY = ['admin-scheduler-commands']

export function useScheduledJobs() {
  return useQuery({
    queryKey: SCHEDULER_KEY,
    queryFn: () => schedulerAdminApi.list(),
  })
}

export function useSchedulableCommands() {
  return useQuery({
    queryKey: COMMANDS_KEY,
    queryFn: () => schedulerAdminApi.commands(),
    staleTime: Infinity,
  })
}

export function useCreateScheduledJob() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (payload: CreateScheduledJobPayload) => schedulerAdminApi.create(payload),
    onSuccess: () => qc.invalidateQueries({ queryKey: SCHEDULER_KEY }),
  })
}

export function useUpdateScheduledJob() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (params: { id: string; payload: UpdateScheduledJobPayload }) =>
      schedulerAdminApi.update(params.id, params.payload),
    onSuccess: () => qc.invalidateQueries({ queryKey: SCHEDULER_KEY }),
  })
}

export function useDeleteScheduledJob() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: schedulerAdminApi.remove,
    onSuccess: () => qc.invalidateQueries({ queryKey: SCHEDULER_KEY }),
  })
}

export function usePauseScheduledJob() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: schedulerAdminApi.pause,
    onSuccess: () => qc.invalidateQueries({ queryKey: SCHEDULER_KEY }),
  })
}

export function useResumeScheduledJob() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: schedulerAdminApi.resume,
    onSuccess: () => qc.invalidateQueries({ queryKey: SCHEDULER_KEY }),
  })
}

export function useTriggerScheduledJob() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: schedulerAdminApi.trigger,
    onSuccess: () => qc.invalidateQueries({ queryKey: SCHEDULER_KEY }),
  })
}

export function useEnableScheduledJob() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: schedulerAdminApi.enable,
    onSuccess: () => qc.invalidateQueries({ queryKey: SCHEDULER_KEY }),
  })
}

export function useDisableScheduledJob() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: schedulerAdminApi.disable,
    onSuccess: () => qc.invalidateQueries({ queryKey: SCHEDULER_KEY }),
  })
}
