import { useAdminMutationTools } from './use-admin-mutation'
import { useQuery, useMutation } from '@tanstack/react-query'
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
    queryFn: ({ signal }) => schedulerAdminApi.list(signal),
  })
}

export function useSchedulableCommands() {
  return useQuery({
    queryKey: COMMANDS_KEY,
    queryFn: ({ signal }) => schedulerAdminApi.commands(signal),
    staleTime: Infinity,
  })
}

export function useCreateScheduledJob() {
  const { run, invalidate } = useAdminMutationTools(['admin-jobs'])

  return useMutation({
    mutationFn: (payload: CreateScheduledJobPayload) => run(() => schedulerAdminApi.create(payload)),
    onSuccess: () => invalidate(SCHEDULER_KEY),
  })
}

export function useUpdateScheduledJob() {
  const { run, invalidate } = useAdminMutationTools(['admin-jobs'])

  return useMutation({
    mutationFn: (params: { id: string; payload: UpdateScheduledJobPayload }) =>
      run(() => schedulerAdminApi.update(params.id, params.payload)),
    onSuccess: () => invalidate(SCHEDULER_KEY),
  })
}

export function useDeleteScheduledJob() {
  const { run, invalidate } = useAdminMutationTools(['admin-jobs'])

  return useMutation({
    mutationFn: (variables: Parameters<typeof schedulerAdminApi.remove>[0]) => run(() => schedulerAdminApi.remove(variables)),
    onSuccess: () => invalidate(SCHEDULER_KEY),
  })
}

export function usePauseScheduledJob() {
  const { run, invalidate } = useAdminMutationTools(['admin-jobs'])

  return useMutation({
    mutationFn: (variables: Parameters<typeof schedulerAdminApi.pause>[0]) => run(() => schedulerAdminApi.pause(variables)),
    onSuccess: () => invalidate(SCHEDULER_KEY),
  })
}

export function useResumeScheduledJob() {
  const { run, invalidate } = useAdminMutationTools(['admin-jobs'])

  return useMutation({
    mutationFn: (variables: Parameters<typeof schedulerAdminApi.resume>[0]) => run(() => schedulerAdminApi.resume(variables)),
    onSuccess: () => invalidate(SCHEDULER_KEY),
  })
}

export function useTriggerScheduledJob() {
  const { run, invalidate } = useAdminMutationTools(['admin-jobs'])

  return useMutation({
    mutationFn: (variables: Parameters<typeof schedulerAdminApi.trigger>[0]) => run(() => schedulerAdminApi.trigger(variables)),
    onSuccess: () => invalidate(SCHEDULER_KEY),
  })
}

export function useEnableScheduledJob() {
  const { run, invalidate } = useAdminMutationTools(['admin-jobs'])

  return useMutation({
    mutationFn: (variables: Parameters<typeof schedulerAdminApi.enable>[0]) => run(() => schedulerAdminApi.enable(variables)),
    onSuccess: () => invalidate(SCHEDULER_KEY),
  })
}

export function useDisableScheduledJob() {
  const { run, invalidate } = useAdminMutationTools(['admin-jobs'])

  return useMutation({
    mutationFn: (variables: Parameters<typeof schedulerAdminApi.disable>[0]) => run(() => schedulerAdminApi.disable(variables)),
    onSuccess: () => invalidate(SCHEDULER_KEY),
  })
}

export function useScheduledJob(id: string | undefined, enabled: boolean) {
  return useQuery({
    queryKey: ['admin-scheduler', id],
    queryFn: ({ signal }) => schedulerAdminApi.show(id!, signal),
    enabled: Boolean(id) && enabled,
  })
}
