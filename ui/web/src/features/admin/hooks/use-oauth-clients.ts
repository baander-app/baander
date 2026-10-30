import { useMutation } from '@tanstack/react-query'
import { useAdminMutationTools } from './use-admin-mutation'
import {
  type AdminCreateOAuthClientRequest,
  getGetAdminOauthClientsListQueryKey,
  postAdminOauthClientsCreate,
  postAdminOauthClientsRevoke,
  postAdminOauthClientsRotateSecret,
  useGetAdminOauthClientsList,
} from '@/shared/api-client/gen/endpoints'

export function useOAuthClients() {
  return useGetAdminOauthClientsList({ query: { select: (response) => response.data } })
}

/*
 * Create and rotate answers carry a client secret that is shown once. `gcTime: 0` drops the
 * finished mutation, and with it the secret, as soon as no component observes it; the list is
 * refetched rather than updated from the answer, so the secret never enters the query cache.
 * A failed change refetches too: a 404 or 409 means the list on screen is out of date.
 */

export function useCreateOAuthClient() {
  const live = useAdminMutationTools(['admin-users'])
  return useMutation({
    mutationFn: async (payload: AdminCreateOAuthClientRequest) => live.run(async () => (await postAdminOauthClientsCreate(payload)).data),
    gcTime: 0,
    onSettled: () => live.invalidate(getGetAdminOauthClientsListQueryKey()),
  })
}

export function useRotateOAuthClientSecret() {
  const live = useAdminMutationTools(['admin-users'])
  return useMutation({
    mutationFn: async (clientId: string) => live.run(async () => (await postAdminOauthClientsRotateSecret(clientId)).data),
    gcTime: 0,
    onSettled: () => live.invalidate(getGetAdminOauthClientsListQueryKey()),
  })
}

export function useRevokeOAuthClient() {
  const live = useAdminMutationTools(['admin-users'])
  return useMutation({
    mutationFn: async (clientId: string) => live.run(async () => (await postAdminOauthClientsRevoke(clientId)).data),
    onSettled: () => live.invalidate(getGetAdminOauthClientsListQueryKey()),
  })
}
