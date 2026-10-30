import { useLiveCoordinator } from '@/shared/live/context';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { AXIOS_INSTANCE } from '@/shared/api-client/axios-instance';
import { deletePasskeyDelete } from '@/shared/api-client/gen/endpoints';

export interface PasskeyInfo {
  publicId: string;
  name: string;
  createdAt: string;
  lastUsedAt: string | null;
}

async function fetchPasskeys(signal: AbortSignal): Promise<PasskeyInfo[]> {
  const response = await AXIOS_INSTANCE.get('/api/auth/passkey', { signal });
  return response.data.data;
}

const PASSKEYS_QUERY_KEY = ['passkeys'] as const;

export function usePasskeyList() {
  return useQuery({
    queryKey: PASSKEYS_QUERY_KEY,
    queryFn: ({ signal }) => fetchPasskeys(signal),
    staleTime: 30_000,
  });
}

export function useDeletePasskey() {
  const queryClient = useQueryClient();
  const coordinator = useLiveCoordinator();

  return useMutation({
    mutationFn: async (publicId: string) => {
      const release = coordinator?.beginMutation(['account']);
      try { return await deletePasskeyDelete(publicId); }
      finally { release?.(); }
    },
    onSuccess: () => {
      if (!coordinator) return queryClient.invalidateQueries({ queryKey: PASSKEYS_QUERY_KEY });
    },
  });
}
